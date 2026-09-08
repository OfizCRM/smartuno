<?php

namespace Tests\Feature\Email;

use App\Events\MessageReceived;
use App\Modules\Email\Services\AttachmentStore;
use App\Modules\Email\Services\InboundMailParser;
use App\Modules\Email\Services\MailboxIngestor;
use App\Modules\Shared\Models\ChannelAccount;
use App\Modules\Shared\Models\Contact;
use App\Modules\Shared\Models\Conversation;
use App\Modules\Shared\Models\Message;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Reading mail into the inbox.
 *
 * Everything here runs on plain arrays rather than a live mailbox, because the
 * parts that actually go wrong — quoted history, tracking pixels, threading and
 * autoresponders — are the parts an IMAP server makes hardest to reproduce.
 */
class InboundMailTest extends TestCase
{
    use RefreshDatabase;

    private array $ctx;

    private ChannelAccount $mailbox;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ctx = $this->createWorkspaceContext();
        $this->mailbox = ChannelAccount::create([
            'workspace_id' => $this->ctx['workspace']->id,
            'channel' => 'email',
            'provider' => 'imap',
            'status' => 'active',
            'display_name' => 'office@firma.ro',
        ]);
    }

    private function parser(): InboundMailParser
    {
        return app(InboundMailParser::class);
    }

    /** @return array<string, mixed> */
    private function mail(array $overrides = []): array
    {
        return array_replace([
            'message_id' => 'msg-'.uniqid().'@client.ro',
            'in_reply_to' => null,
            'references' => [],
            'from_email' => 'ana@client.ro',
            'from_name' => 'Ana Pop',
            'subject' => 'Întrebare despre comandă',
            'text' => 'Bună ziua, când ajunge coletul?',
            'date' => Carbon::now(),
            'automated' => false,
            'automated_reason' => null,
        ], $overrides);
    }

    // ─── the parser: what must never reach a person ──────────────────────

    #[DataProvider('automatedMail')]
    public function test_automated_mail_is_recognised(array $headers, string $from, string $expected): void
    {
        $this->assertSame($expected, $this->parser()->automatedReason($headers, $from));
    }

    /** @return array<string, array{array<string,string>, string, string}> */
    public static function automatedMail(): array
    {
        return [
            'out of office' => [['auto-submitted' => 'auto-replied'], 'ana@client.ro', 'auto-submitted'],
            'bulk mail' => [['precedence' => 'bulk'], 'ana@client.ro', 'precedence'],
            'mailing list' => [['list-id' => '<news.firma.ro>'], 'ana@client.ro', 'list-id'],
            'unsubscribable' => [['list-unsubscribe' => '<mailto:x@y.ro>'], 'ana@client.ro', 'list-unsubscribe'],
            'bounce' => [['return-path' => '<>'], 'ana@client.ro', 'bounce'],
            'no-reply sender' => [[], 'no-reply@banca.ro', 'robot-sender'],
            'mailer daemon' => [[], 'MAILER-DAEMON@server.ro', 'robot-sender'],
            // The same headers as the IMAP library spells them. Reading only the
            // RFC spelling meant none of these matched and the bot answered
            // every out-of-office it received.
            'out of office, library spelling' => [['auto_submitted' => 'auto-replied'], 'ana@client.ro', 'auto-submitted'],
            'mailing list, library spelling' => [['list_id' => '<news.firma.ro>'], 'ana@client.ro', 'list-id'],
            'unsubscribable, library spelling' => [['list_unsubscribe' => '<mailto:x@y.ro>'], 'ana@client.ro', 'list-unsubscribe'],
            'bounce, library spelling' => [['return_path' => ''], 'ana@client.ro', 'bounce'],
        ];
    }

    public function test_a_person_writing_is_not_treated_as_automated(): void
    {
        $this->assertNull($this->parser()->automatedReason(
            ['auto-submitted' => 'no', 'from' => 'Ana Pop'],
            'ana@client.ro',
        ));
    }

    public function test_html_is_converted_and_tracking_images_are_dropped(): void
    {
        $body = $this->parser()->body(
            '<p>Bună <b>ziua</b>!</p><img src="https://track.example/pixel.gif?id=42" width="1" height="1">',
            null,
        );

        $this->assertStringContainsString('Bună', $body);
        // A 1x1 in a received mail reports back the moment it is opened.
        $this->assertStringNotContainsString('track.example', $body);
        $this->assertStringNotContainsString('<img', $body);
    }

    public function test_the_quoted_history_is_cut_off(): void
    {
        $body = $this->parser()->stripQuotedReply(
            "Mulțumesc, am înțeles.\n\nOn 3 September 2026 at 10:00, Office <office@firma.ro> wrote:\n> Bună ziua,\n> comanda a plecat.\n> Numărul AWB este 123."
        );

        $this->assertStringContainsString('Mulțumesc', $body);
        $this->assertStringNotContainsString('AWB', $body);
    }

    public function test_a_message_that_is_only_a_quote_is_kept_whole(): void
    {
        $quote = "> Bună ziua,\n> comanda a plecat.\n> AWB 123.";

        // Cutting here would leave an empty bubble, which is worse than the quote.
        $this->assertNotSame('', trim($this->parser()->stripQuotedReply($quote)));
    }

    // ─── the shape a real mail server hands us ───────────────────────────

    /**
     * @param  array<string, mixed>  $headers
     * @return array<string, mixed>
     */
    private function parsed(array $headers = [], ?string $subject = 'Întrebare', ?string $fromName = 'Ana Pop'): array
    {
        return $this->parser()->parse(
            headers: $headers,
            fromEmail: 'ana@client.ro',
            fromName: $fromName,
            subject: (string) $subject,
            html: null,
            text: 'Bună ziua',
        );
    }

    public function test_a_subject_encoded_as_a_mime_word_is_decoded(): void
    {
        // Folded across two encoded words, which is how a long subject arrives.
        $mail = $this->parsed(subject: '=?UTF-8?B?W3llc2FnZW5jeS5yb10gQ2xpZW50IGNvbmZpZ3VyYXRpb24gc2V0?= '
            .'=?UTF-8?B?dGluZ3MgZm9yIOKAnGVtYWlsQHllc2FnZW5jeS5yb+KAnS4=?=');

        $this->assertSame('[yesagency.ro] Client configuration settings for “email@yesagency.ro”.', $mail['subject']);
    }

    public function test_a_romanian_subject_survives_quoted_printable(): void
    {
        // Every Romanian subject with a diacritic arrives encoded like this.
        $this->assertSame('Comandă nouă pe site', $this->parsed(subject: '=?UTF-8?Q?Comand=C4=83_nou=C4=83_pe_site?=')['subject']);
    }

    public function test_a_plain_subject_is_left_exactly_as_it_is(): void
    {
        $this->assertSame('Cerere', $this->parsed(subject: 'Cerere')['subject']);
    }

    public function test_the_senders_name_is_decoded_and_unquoted(): void
    {
        $this->assertSame('Vlad Crișan', $this->parsed(fromName: '=?UTF-8?B?VmxhZCBDcmnImWFu?=')['from_name']);
        // A quoted display name keeps its quotation marks in the header, and the
        // contact list then shows a name that begins with a stray double quote.
        $this->assertSame('cPanel on yesagency.ro', $this->parsed(fromName: '"cPanel on yesagency.ro"')['from_name']);
    }

    public function test_header_names_are_read_in_the_librarys_own_spelling(): void
    {
        $mail = $this->parsed([
            'message_id' => 'abc@client.ro',
            'in_reply_to' => 'parent@firma.ro',
            'auto_submitted' => 'auto-replied',
        ]);

        // Without the message id there is no de-duplication and no threading.
        $this->assertSame('abc@client.ro', $mail['message_id']);
        $this->assertSame('parent@firma.ro', $mail['in_reply_to']);
        $this->assertTrue($mail['automated']);
    }

    public function test_references_are_read_with_or_without_their_brackets(): void
    {
        $raw = $this->parsed(['references' => '<root@firma.ro> <parent@firma.ro>']);
        // What the library returns: brackets already stripped, comma separated.
        $library = $this->parsed(['references' => 'root@firma.ro, parent@firma.ro']);

        // The immediate parent first — it is the one worth trying when threading.
        $this->assertSame(['parent@firma.ro', 'root@firma.ro'], $raw['references']);
        $this->assertSame($raw['references'], $library['references']);
    }

    // ─── the ingestor: threads, duplicates and the bot ───────────────────

    public function test_a_first_message_creates_the_contact_the_thread_and_the_message(): void
    {
        Event::fake([MessageReceived::class]);

        $message = app(MailboxIngestor::class)->ingest($this->mailbox, $this->mail());

        $this->assertNotNull($message);
        $this->assertSame('email', $message->channel);
        $this->assertSame('Întrebare despre comandă', $message->payload['subject']);

        $conversation = $message->conversation;
        $this->assertSame($this->mailbox->id, $conversation->channel_account_id);
        $this->assertSame(1, (int) $conversation->unread_count);
        $this->assertNotNull($conversation->last_inbound_at);

        $contact = Contact::where('workspace_id', $this->ctx['workspace']->id)->first();
        $this->assertSame('ana@client.ro', $contact->email);
        $this->assertSame('Ana', $contact->first_name);

        Event::assertDispatched(MessageReceived::class);
    }

    public function test_a_reply_lands_in_the_same_thread(): void
    {
        $ingestor = app(MailboxIngestor::class);
        $first = $ingestor->ingest($this->mailbox, $this->mail(['message_id' => 'root@client.ro']));

        $reply = $ingestor->ingest($this->mailbox, $this->mail([
            'message_id' => 'second@client.ro',
            'in_reply_to' => 'root@client.ro',
        ]));

        $this->assertSame($first->conversation_id, $reply->conversation_id);
        $this->assertSame(1, Conversation::where('workspace_id', $this->ctx['workspace']->id)->count());
    }

    public function test_a_reply_threads_through_references_when_in_reply_to_is_missing(): void
    {
        $ingestor = app(MailboxIngestor::class);
        $first = $ingestor->ingest($this->mailbox, $this->mail(['message_id' => 'root@client.ro']));

        $reply = $ingestor->ingest($this->mailbox, $this->mail([
            'message_id' => 'third@client.ro',
            'references' => ['root@client.ro', 'someone-else@x.ro'],
        ]));

        $this->assertSame($first->conversation_id, $reply->conversation_id);
    }

    public function test_a_new_subject_from_the_same_person_starts_a_new_thread(): void
    {
        $ingestor = app(MailboxIngestor::class);
        $ingestor->ingest($this->mailbox, $this->mail(['message_id' => 'a@client.ro']));
        $ingestor->ingest($this->mailbox, $this->mail(['message_id' => 'b@client.ro', 'subject' => 'Cu totul altceva']));

        // One conversation per thread, not per person: two subjects are two jobs.
        $this->assertSame(2, Conversation::where('workspace_id', $this->ctx['workspace']->id)->count());
        $this->assertSame(1, Contact::where('workspace_id', $this->ctx['workspace']->id)->count());
    }

    public function test_the_same_message_is_never_filed_twice(): void
    {
        $ingestor = app(MailboxIngestor::class);
        $ingestor->ingest($this->mailbox, $this->mail(['message_id' => 'same@client.ro']));

        // An overlapping poll, or a server reissuing UIDs, re-offers what is filed.
        $this->assertNull($ingestor->ingest($this->mailbox, $this->mail(['message_id' => 'same@client.ro'])));
        $this->assertSame(1, Message::count());
    }

    public function test_automated_mail_is_stored_but_never_wakes_the_bot(): void
    {
        Event::fake([MessageReceived::class]);

        $message = app(MailboxIngestor::class)->ingest($this->mailbox, $this->mail([
            'from_email' => 'no-reply@curier.ro',
            'automated' => true,
            'automated_reason' => 'robot-sender',
        ]));

        // Kept, so the thread stays complete — but two mail robots answering each
        // other is a loop that runs until someone reads the bill.
        $this->assertNotNull($message);
        $this->assertTrue($message->payload['automated']);
        Event::assertNotDispatched(MessageReceived::class);
    }

    public function test_threading_and_deduplication_cannot_reach_another_workspace(): void
    {
        $other = $this->createWorkspaceContext();
        $theirMailbox = ChannelAccount::create([
            'workspace_id' => $other['workspace']->id,
            'channel' => 'email',
            'provider' => 'imap',
            'status' => 'active',
            'display_name' => 'office@altfirma.ro',
        ]);

        $ingestor = app(MailboxIngestor::class);
        $theirs = $ingestor->ingest($theirMailbox, $this->mail(['message_id' => 'shared@client.ro']));

        // The same Message-ID reaches two firms on a CC line every day. It must
        // be filed for both, and must not thread into the other's conversation.
        $mine = $ingestor->ingest($this->mailbox, $this->mail([
            'message_id' => 'reply@client.ro',
            'in_reply_to' => 'shared@client.ro',
        ]));

        $this->assertNotNull($mine);
        $this->assertNotSame($theirs->conversation_id, $mine->conversation_id);
        $this->assertSame($this->ctx['workspace']->id, $mine->conversation->workspace_id);
    }

    public function test_the_files_that_came_with_the_mail_are_kept_on_the_message(): void
    {
        $entry = app(AttachmentStore::class)
            ->put('factura.pdf', 'application/pdf', 'TOTAL 249 lei');

        $message = app(MailboxIngestor::class)->ingest($this->mailbox, $this->mail([
            'attachments' => [$entry],
        ]));

        $this->assertSame('factura.pdf', $message->payload['attachments'][0]['name']);

        // And the person the mail was addressed to can actually open it.
        $this->actingAs($this->ctx['user'])->get(route('client.email.attachment', [
            'conversation' => $message->conversation->uuid,
            'message' => $message->id,
            'index' => 0,
        ]))->assertOk();
    }

    public function test_a_reply_threads_when_the_headers_arrive_from_the_library(): void
    {
        $ingestor = app(MailboxIngestor::class);

        $first = $ingestor->ingest($this->mailbox, $this->parsed(['message_id' => 'root@client.ro']));
        $reply = $ingestor->ingest($this->mailbox, $this->parsed([
            'message_id' => 'reply@client.ro',
            'in_reply_to' => 'root@client.ro',
        ], subject: 'Re: Întrebare'));

        // The whole chain: the id is stored, so the reply finds its parent
        // instead of opening a second conversation with the same person.
        $this->assertSame('root@client.ro', $first->provider_message_id);
        $this->assertSame($first->conversation_id, $reply->conversation_id);
    }
}
