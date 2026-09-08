<?php

namespace Tests\Feature\Email;

use App\Modules\Email\Services\AttachmentStore;
use App\Modules\Email\Services\EmailDriver;
use App\Modules\Email\Services\MailboxTransport;
use App\Modules\Shared\Models\ChannelAccount;
use App\Modules\Shared\Models\Contact;
use App\Modules\Shared\Models\Conversation;
use App\Modules\Shared\Models\Message;
use App\Modules\Shared\Services\ChannelManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\Mailer;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Mime\Email as MimeEmail;
use Symfony\Component\Mime\RawMessage;
use Tests\TestCase;

/**
 * Replying out of the mailbox the message arrived in.
 *
 * The transport is swapped for one that records rather than sends, so the
 * headers can be asserted — threading lives entirely in those, and getting them
 * wrong shows up as the customer receiving a stray new email instead of a reply.
 */
class EmailReplyTest extends TestCase
{
    use RefreshDatabase;

    private array $ctx;

    private ChannelAccount $mailbox;

    /** @var array<int, MimeEmail> */
    public static array $sent = [];

    protected function setUp(): void
    {
        parent::setUp();
        self::$sent = [];

        $this->ctx = $this->createWorkspaceContext();

        $this->mailbox = new ChannelAccount([
            'workspace_id' => $this->ctx['workspace']->id,
            'channel' => 'email',
            'provider' => 'imap',
            'status' => 'active',
            'display_name' => 'office@firma.ro',
        ]);
        $this->mailbox->setAttribute('credentials', [
            'email' => 'office@firma.ro',
            'from_name' => 'Farmacia Verde',
            'imap' => ['host' => 'imap.gmail.com', 'port' => 993, 'encryption' => 'ssl', 'username' => 'u', 'password' => 'p'],
            'smtp' => ['host' => 'smtp.gmail.com', 'port' => 465, 'encryption' => 'ssl', 'username' => 'u', 'password' => 'p'],
        ]);
        $this->mailbox->save();

        $this->app->instance(MailboxTransport::class, new class extends MailboxTransport
        {
            public function make(array $smtp): EsmtpTransport
            {
                throw new \LogicException('unused');
            }
        });

        // Replace the driver with one that keeps the built message instead of
        // opening a socket. Everything above the transport is the real code.
        $this->app->bind(EmailDriver::class, fn () => new class(app(MailboxTransport::class), app(AttachmentStore::class)) extends EmailDriver
        {
            protected function mailerFor(array $smtp): MailerInterface
            {
                return new Mailer(new class implements TransportInterface
                {
                    public function send(RawMessage $message, ?Envelope $envelope = null): ?SentMessage
                    {
                        EmailReplyTest::$sent[] = $message;

                        return null;
                    }

                    public function __toString(): string
                    {
                        return 'recording';
                    }
                });
            }
        });
    }

    private function conversation(?string $inboundSubject = 'Întrebare despre comandă', ?string $inboundId = 'client-1@client.ro'): Conversation
    {
        $conversation = Conversation::create([
            'workspace_id' => $this->ctx['workspace']->id,
            'channel_account_id' => $this->mailbox->id,
            'contact_id' => Contact::factory()->create([
                'workspace_id' => $this->ctx['workspace']->id,
                'email' => 'ana@client.ro',
            ])->id,
            'status' => 'open',
            'last_message_at' => now(),
        ]);

        if ($inboundId) {
            Message::create([
                'conversation_id' => $conversation->id,
                'direction' => 'in',
                'channel' => 'email',
                'type' => 'text',
                'body' => 'Când ajunge coletul?',
                'payload' => ['subject' => $inboundSubject],
                'provider_message_id' => $inboundId,
                'status' => 'delivered',
                'sent_at' => now()->subMinutes(5),
            ]);
        }

        return $conversation;
    }

    private function outbound(Conversation $conversation, array $payload = []): Message
    {
        return Message::create([
            'conversation_id' => $conversation->id,
            'direction' => 'out',
            'channel' => 'email',
            'type' => 'text',
            'body' => 'Pleacă azi, vă trimit AWB-ul.',
            'payload' => $payload ?: null,
            'status' => 'queued',
            'sent_by' => 'human',
            'sent_at' => now(),
        ]);
    }

    public function test_the_driver_is_registered_so_the_inbox_can_resolve_it(): void
    {
        $this->assertInstanceOf(EmailDriver::class, app(ChannelManager::class)->driver('email'));
    }

    public function test_a_reply_goes_out_from_the_mailbox_address(): void
    {
        $message = $this->outbound($this->conversation());

        app(EmailDriver::class)->send($message);

        $mail = self::$sent[0];
        $this->assertSame('office@firma.ro', $mail->getFrom()[0]->getAddress());
        $this->assertSame('Farmacia Verde', $mail->getFrom()[0]->getName());
        $this->assertSame('ana@client.ro', $mail->getTo()[0]->getAddress());
    }

    public function test_the_reply_keeps_the_customers_subject_with_one_re(): void
    {
        app(EmailDriver::class)->send($this->outbound($this->conversation()));

        $this->assertSame('Re: Întrebare despre comandă', self::$sent[0]->getSubject());
    }

    public function test_a_subject_that_is_already_a_reply_is_not_prefixed_again(): void
    {
        app(EmailDriver::class)->send($this->outbound($this->conversation('Re: Întrebare despre comandă')));

        $this->assertSame('Re: Întrebare despre comandă', self::$sent[0]->getSubject());
    }

    public function test_a_subject_typed_in_the_composer_wins(): void
    {
        app(EmailDriver::class)->send($this->outbound($this->conversation(), ['subject' => 'AWB pentru comanda 10428']));

        $this->assertSame('AWB pentru comanda 10428', self::$sent[0]->getSubject());
    }

    public function test_the_reply_is_threaded_to_what_the_customer_sent(): void
    {
        $message = $this->outbound($this->conversation());

        $ourId = app(EmailDriver::class)->send($message);

        $headers = self::$sent[0]->getHeaders();

        // Their client matches on these; without them the reply arrives as a
        // brand-new email and the thread splits in two.
        $this->assertStringContainsString('client-1@client.ro', $headers->get('In-Reply-To')->getBodyAsString());
        $this->assertStringContainsString('client-1@client.ro', $headers->get('References')->getBodyAsString());

        // And our own id comes back so their reply can name it in turn.
        $this->assertStringContainsString('@firma.ro', $ourId);
    }

    public function test_a_thread_with_nothing_to_reply_to_still_sends(): void
    {
        $message = $this->outbound($this->conversation(null, null));

        app(EmailDriver::class)->send($message);

        $this->assertNull(self::$sent[0]->getHeaders()->get('In-Reply-To'));
        $this->assertNotSame('', self::$sent[0]->getSubject());
    }

    public function test_a_contact_without_an_email_address_is_refused(): void
    {
        $conversation = $this->conversation();
        $conversation->contact->update(['email' => null]);

        $this->expectException(\RuntimeException::class);

        app(EmailDriver::class)->send($this->outbound($conversation->fresh()));
    }

    public function test_a_conversation_with_no_mailbox_is_refused(): void
    {
        $conversation = $this->conversation();
        $conversation->update(['channel_account_id' => null]);

        $this->expectException(\RuntimeException::class);

        app(EmailDriver::class)->send($this->outbound($conversation->fresh()));
    }

    public function test_an_attachment_typed_in_the_composer_leaves_with_the_email(): void
    {
        $conversation = $this->conversation();

        $this->actingAs($this->ctx['user'])->postJson(
            route('client.inbox.reply', $conversation->uuid),
            [
                'body' => 'Vezi factura atașată',
                'attachment' => UploadedFile::fake()->createWithContent('factura.txt', 'TOTAL 249 lei'),
            ],
        )->assertOk();

        $attachments = self::$sent[0]->getAttachments();

        $this->assertCount(1, $attachments);
        $this->assertSame('TOTAL 249 lei', $attachments[0]->getBody());
        // The name the recipient sees is the one that was uploaded, not the uuid
        // the file is stored under.
        $this->assertStringContainsString('factura.txt', $attachments[0]->getPreparedHeaders()->get('content-disposition')->toString());
    }

    public function test_an_attachment_that_was_not_kept_does_not_stop_the_reply(): void
    {
        $conversation = $this->conversation();

        // The shape an oversized inbound file leaves behind. Forwarding one of
        // those must not throw on a null path and lose the reply.
        app(EmailDriver::class)->send($this->outbound($conversation, [
            'attachments' => [
                ['name' => 'film.bin', 'mime' => 'application/octet-stream', 'size' => 99999999, 'path' => null, 'stored' => false],
            ],
        ]));

        $this->assertCount(1, self::$sent);
        $this->assertCount(0, self::$sent[0]->getAttachments());
    }
}
