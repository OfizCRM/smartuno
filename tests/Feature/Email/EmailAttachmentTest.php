<?php

namespace Tests\Feature\Email;

use App\Modules\Email\Services\AttachmentStore;
use App\Modules\Shared\Models\ChannelAccount;
use App\Modules\Shared\Models\Contact;
use App\Modules\Shared\Models\Conversation;
use App\Modules\Shared\Models\Message;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\FakesPrivateDisk;
use Tests\TestCase;

/**
 * Files that arrive with an email, and files sent back with a reply.
 *
 * The parts worth pinning are the ones that bite later: a stored name that could
 * be executed, a public URL that leaks a tenant's invoice, and an unbounded disk.
 */
class EmailAttachmentTest extends TestCase
{
    use FakesPrivateDisk, RefreshDatabase;

    private array $ctx;

    private ChannelAccount $mailbox;

    protected function setUp(): void
    {
        parent::setUp();
        // Attachments are written for real by this class; keep them out of the
        // developer's storage directory.
        $this->fakePrivateDisk();
        $this->ctx = $this->createWorkspaceContext();
        $this->mailbox = ChannelAccount::create([
            'workspace_id' => $this->ctx['workspace']->id,
            'channel' => 'email',
            'provider' => 'imap',
            'status' => 'active',
            'display_name' => 'office@firma.ro',
        ]);
    }

    private function store(): AttachmentStore
    {
        return app(AttachmentStore::class);
    }

    private function conversation(?int $workspaceId = null, ?ChannelAccount $mailbox = null): Conversation
    {
        $workspaceId ??= $this->ctx['workspace']->id;

        return Conversation::create([
            'workspace_id' => $workspaceId,
            'channel_account_id' => ($mailbox ?? $this->mailbox)->id,
            'contact_id' => Contact::factory()->create(['workspace_id' => $workspaceId, 'email' => 'ana@client.ro'])->id,
            'status' => 'open',
            'last_message_at' => now(),
        ]);
    }

    private function messageWith(Conversation $conversation, array $attachments): Message
    {
        return Message::create([
            'conversation_id' => $conversation->id,
            'direction' => 'in',
            'channel' => 'email',
            'type' => 'text',
            'body' => 'Vezi atașat',
            'payload' => ['subject' => 'Factura', 'attachments' => $attachments],
            'status' => 'delivered',
            'sent_at' => now(),
        ]);
    }

    // ─── what lands on disk ──────────────────────────────────────────────

    public function test_the_stored_name_never_comes_from_the_sender(): void
    {
        // The classic: a name that a web server would happily execute.
        $entry = $this->store()->put('factura.pdf.php', 'application/pdf', 'PDF-CONTENT');

        $this->assertTrue($entry['stored']);
        $this->assertStringEndsWith('.pdf', $entry['path']);
        $this->assertStringNotContainsString('.php', $entry['path']);
        // The original is kept as data, for display only.
        $this->assertSame('factura.pdf.php', $entry['name']);
    }

    public function test_an_unknown_type_gets_a_neutral_extension(): void
    {
        $entry = $this->store()->put('ceva.svg', 'image/svg+xml', '<svg onload="alert(1)"></svg>');

        $this->assertStringEndsWith('.bin', $entry['path']);
    }

    public function test_a_path_cannot_be_smuggled_through_the_filename(): void
    {
        $entry = $this->store()->put('../../.env', 'text/plain', 'x');

        $this->assertStringNotContainsString('..', $entry['name']);
        $this->assertStringNotContainsString('/', $entry['name']);
    }

    public function test_a_file_over_the_limit_is_listed_but_not_kept(): void
    {
        $entry = $this->store()->put('film.bin', 'application/octet-stream', str_repeat('x', AttachmentStore::MAX_FILE_BYTES + 1));

        $this->assertFalse($entry['stored']);
        $this->assertNull($entry['path']);
        // Still named, so the thread does not pretend nothing arrived.
        $this->assertSame('film.bin', $entry['name']);
    }

    public function test_one_message_cannot_fill_the_disk_on_its_own(): void
    {
        $chunk = str_repeat('x', 9 * 1024 * 1024);

        $first = $this->store()->put('a.bin', 'application/octet-stream', $chunk, 0);
        $second = $this->store()->put('b.bin', 'application/octet-stream', $chunk, $first['size']);
        $third = $this->store()->put('c.bin', 'application/octet-stream', $chunk, $first['size'] + $second['size']);

        $this->assertTrue($first['stored']);
        $this->assertTrue($second['stored']);
        $this->assertFalse($third['stored'], 'the third pushes the message past 25 MB');
    }

    public function test_the_file_never_lands_on_the_disk_the_web_server_publishes(): void
    {
        $entry = $this->store()->put('factura.pdf', 'application/pdf', 'PDF-CONTENT');

        // storage/app/public is symlinked into the web root and served with no
        // authentication whatsoever. An emailed invoice does not belong there.
        $this->assertNotOnTheWebServersDisk($entry['path'], 'An emailed invoice');
        $this->assertTrue($this->privateDisk()->exists($entry['path']));
        // And the entry says which disk it went to, so a reader never has to
        // guess. Before this key existed there was nothing to guess FROM.
        $this->assertSame($this->privateDiskName(), $entry['disk']);
    }

    public function test_the_stored_file_is_not_reachable_around_the_controller(): void
    {
        $entry = $this->store()->put('factura.pdf', 'application/pdf', 'PDF-CONTENT');

        // Laravel serves the local disk at /storage, but only for a signed URL —
        // and this module never mints one. Even a logged-in user of the owning
        // workspace must come through AttachmentController, which is the only
        // place the workspace is checked.
        $response = $this->actingAs($this->ctx['user'])->get('/storage/'.$entry['path']);

        $this->assertContains($response->status(), [403, 404]);
        $this->assertStringNotContainsString('PDF-CONTENT', (string) $response->getContent());
    }

    // ─── who may read one ────────────────────────────────────────────────

    public function test_the_owner_can_download_an_attachment(): void
    {
        $entry = $this->store()->put('factura.pdf', 'application/pdf', 'PDF-CONTENT');
        $conversation = $this->conversation();
        $message = $this->messageWith($conversation, [$entry]);

        $response = $this->actingAs($this->ctx['user'])
            ->get(route('client.email.attachment', ['conversation' => $conversation->uuid, 'message' => $message->id, 'index' => 0]));

        $response->assertOk();
        $this->assertSame('PDF-CONTENT', $response->getContent());
        // Never rendered in the browser: an HTML attachment shown inline would
        // run a stranger's markup on this application's own origin.
        $this->assertStringContainsString('attachment', $response->headers->get('content-disposition'));
        $this->assertSame('application/octet-stream', $response->headers->get('content-type'));
        $this->assertSame('nosniff', $response->headers->get('x-content-type-options'));
    }

    public function test_another_workspace_cannot_download_it(): void
    {
        $entry = $this->store()->put('factura.pdf', 'application/pdf', 'PDF-CONTENT');
        $conversation = $this->conversation();
        $message = $this->messageWith($conversation, [$entry]);

        $intruder = $this->createWorkspaceContext();

        $this->actingAs($intruder['user'])
            ->get(route('client.email.attachment', ['conversation' => $conversation->uuid, 'message' => $message->id, 'index' => 0]))
            ->assertForbidden();
    }

    public function test_a_message_from_a_different_conversation_is_not_reachable(): void
    {
        $entry = $this->store()->put('factura.pdf', 'application/pdf', 'PDF');
        $mine = $this->conversation();
        $other = $this->conversation();
        $message = $this->messageWith($other, [$entry]);

        $this->actingAs($this->ctx['user'])
            ->get(route('client.email.attachment', ['conversation' => $mine->uuid, 'message' => $message->id, 'index' => 0]))
            ->assertNotFound();
    }

    public function test_an_attachment_that_was_never_kept_is_a_404(): void
    {
        $conversation = $this->conversation();
        $message = $this->messageWith($conversation, [
            ['name' => 'film.bin', 'mime' => 'application/octet-stream', 'size' => 99999999, 'path' => null, 'stored' => false],
        ]);

        $this->actingAs($this->ctx['user'])
            ->get(route('client.email.attachment', ['conversation' => $conversation->uuid, 'message' => $message->id, 'index' => 0]))
            ->assertNotFound();
    }

    // ─── showing one in the page ─────────────────────────────────────────

    public function test_a_safe_type_can_be_shown_in_the_page(): void
    {
        $entry = $this->store()->put('factura.pdf', 'application/pdf', 'PDF-CONTENT');
        $conversation = $this->conversation();
        $message = $this->messageWith($conversation, [$entry]);

        $response = $this->actingAs($this->ctx['user'])->get(route('client.email.attachment', [
            'conversation' => $conversation->uuid, 'message' => $message->id, 'index' => 0, 'preview' => 1,
        ]));

        $response->assertOk();
        $this->assertSame('application/pdf', $response->headers->get('content-type'));
        $this->assertStringContainsString('inline', $response->headers->get('content-disposition'));
        $this->assertSame('nosniff', $response->headers->get('x-content-type-options'));
        // The sandbox is what stops script inside the document running against
        // the session of the person who opened it.
        $this->assertStringContainsString('sandbox', $response->headers->get('content-security-policy'));
    }

    public function test_the_type_shown_comes_from_our_extension_not_the_senders_claim(): void
    {
        // A sender insisting their HTML is a PDF. put() files it by MIME, so it
        // lands as .bin — and .bin is not displayable.
        $entry = $this->store()->put('safe.pdf', 'text/html', '<script>alert(1)</script>');
        $conversation = $this->conversation();
        $message = $this->messageWith($conversation, [$entry]);

        $this->actingAs($this->ctx['user'])->get(route('client.email.attachment', [
            'conversation' => $conversation->uuid, 'message' => $message->id, 'index' => 0, 'preview' => 1,
        ]))->assertNotFound();
    }

    #[DataProvider('undisplayableTypes')]
    public function test_a_type_outside_the_list_is_refused_rather_than_quietly_downloaded(string $name, string $mime): void
    {
        $entry = $this->store()->put($name, $mime, 'x');
        $conversation = $this->conversation();
        $message = $this->messageWith($conversation, [$entry]);

        // 404 and not a download: the caller asked to display something that must
        // not be displayed, and answering with the file would look like it worked.
        $this->actingAs($this->ctx['user'])->get(route('client.email.attachment', [
            'conversation' => $conversation->uuid, 'message' => $message->id, 'index' => 0, 'preview' => 1,
        ]))->assertNotFound();
    }

    /** @return array<string, array{string, string}> */
    public static function undisplayableTypes(): array
    {
        return [
            'svg' => ['logo.svg', 'image/svg+xml'],
            'word document' => ['contract.docx', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'],
            'zip' => ['arhiva.zip', 'application/zip'],
            'anything unknown' => ['ceva.xyz', 'application/x-msdownload'],
        ];
    }

    public function test_another_workspace_cannot_display_it_either(): void
    {
        $entry = $this->store()->put('factura.pdf', 'application/pdf', 'PDF-CONTENT');
        $conversation = $this->conversation();
        $message = $this->messageWith($conversation, [$entry]);
        $intruder = $this->createWorkspaceContext();

        $this->actingAs($intruder['user'])->get(route('client.email.attachment', [
            'conversation' => $conversation->uuid, 'message' => $message->id, 'index' => 0, 'preview' => 1,
        ]))->assertForbidden();
    }

    public function test_without_the_flag_it_is_still_a_download(): void
    {
        $entry = $this->store()->put('poza.png', 'image/png', 'PNG-CONTENT');
        $conversation = $this->conversation();
        $message = $this->messageWith($conversation, [$entry]);

        $response = $this->actingAs($this->ctx['user'])->get(route('client.email.attachment', [
            'conversation' => $conversation->uuid, 'message' => $message->id, 'index' => 0,
        ]));

        $this->assertSame('application/octet-stream', $response->headers->get('content-type'));
        $this->assertStringContainsString('attachment', $response->headers->get('content-disposition'));
    }

    public function test_an_ordinary_page_still_gets_the_site_wide_policy(): void
    {
        // The middleware now steps aside for a response that set its own policy.
        // This is the other half of that: everything else must still be covered.
        $csp = $this->actingAs($this->ctx['user'])
            ->get(route('client.inbox.index'))
            ->headers->get('content-security-policy');

        $this->assertStringContainsString("default-src 'self'", (string) $csp);
        $this->assertStringNotContainsString('sandbox', (string) $csp);
    }

    // ─── sending one ─────────────────────────────────────────────────────

    public function test_attaching_a_file_to_an_email_reply_stores_it_on_the_message(): void
    {
        $conversation = $this->conversation();

        $this->actingAs($this->ctx['user'])->postJson(
            route('client.inbox.reply', $conversation->uuid),
            ['body' => 'Vezi atașat', 'attachment' => UploadedFile::fake()->createWithContent('oferta.txt', 'continutul ofertei')],
        )->assertOk();

        $message = Message::where('conversation_id', $conversation->id)->latest('id')->first();
        $attachment = $message->payload['attachments'][0];

        $this->assertSame('oferta.txt', $attachment['name']);
        $this->assertTrue($attachment['stored']);
        $this->assertSame('continutul ofertei', $this->store()->contents($attachment['path']));
    }

    public function test_a_reply_attachment_over_the_limit_is_refused_with_a_reason(): void
    {
        $conversation = $this->conversation();

        $response = $this->actingAs($this->ctx['user'])->postJson(
            route('client.inbox.reply', $conversation->uuid),
            // A real payload, not a fake with a reported size: the cap is applied to
            // the bytes we actually read off the temp file.
            ['body' => 'Vezi atașat', 'attachment' => UploadedFile::fake()->createWithContent('mare.txt', str_repeat('x', AttachmentStore::MAX_FILE_BYTES + 1024))],
        );

        $response->assertStatus(422);
        $this->assertNotNull($response->json('error'));
    }

    // ─── more than one at a time ─────────────────────────────────────────

    public function test_several_files_can_go_out_with_one_reply(): void
    {
        $conversation = $this->conversation();

        $this->actingAs($this->ctx['user'])->postJson(
            route('client.inbox.reply', $conversation->uuid),
            [
                'body' => 'Vezi cele trei documente',
                'attachments' => [
                    UploadedFile::fake()->createWithContent('oferta.txt', 'unu'),
                    UploadedFile::fake()->createWithContent('contract.txt', 'doi'),
                    UploadedFile::fake()->createWithContent('anexa.txt', 'trei'),
                ],
            ],
        )->assertOk();

        $stored = Message::where('conversation_id', $conversation->id)->latest('id')->first()->payload['attachments'];

        $this->assertCount(3, $stored);
        $this->assertSame(['oferta.txt', 'contract.txt', 'anexa.txt'], array_column($stored, 'name'));
        foreach ($stored as $entry) {
            $this->assertTrue($entry['stored']);
        }
    }

    public function test_the_message_cap_is_measured_across_the_whole_set(): void
    {
        $conversation = $this->conversation();
        $chunk = str_repeat('x', 9 * 1024 * 1024);

        $response = $this->actingAs($this->ctx['user'])->postJson(
            route('client.inbox.reply', $conversation->uuid),
            [
                'body' => 'Trei bucăți mari',
                // Each one is under the per-file limit; together they are not.
                'attachments' => [
                    UploadedFile::fake()->createWithContent('a.txt', $chunk),
                    UploadedFile::fake()->createWithContent('b.txt', $chunk),
                    UploadedFile::fake()->createWithContent('c.txt', $chunk),
                ],
            ],
        );

        $response->assertStatus(422);
        $this->assertNotNull($response->json('error'));
        // And the two that did fit are not left behind on the disk.
        $this->assertSame([], $this->privateDisk()->allFiles('email-attachments'));
        $this->assertSame(0, Message::where('conversation_id', $conversation->id)->count());
    }

    public function test_a_channel_that_carries_one_file_says_so_instead_of_dropping_the_rest(): void
    {
        $whatsapp = ChannelAccount::create([
            'workspace_id' => $this->ctx['workspace']->id,
            'channel' => 'whatsapp',
            'provider' => 'meta',
            'status' => 'active',
            'display_name' => '+40712345678',
        ]);
        $conversation = $this->conversation(mailbox: $whatsapp);

        $response = $this->actingAs($this->ctx['user'])->postJson(
            route('client.inbox.reply', $conversation->uuid),
            [
                'body' => 'Două fișiere',
                'attachments' => [
                    UploadedFile::fake()->createWithContent('a.txt', 'unu'),
                    UploadedFile::fake()->createWithContent('b.txt', 'doi'),
                ],
            ],
        );

        // Meta's media API carries one per message; silently sending only the
        // first would be the worst of the three options.
        $response->assertStatus(422);
        $this->assertNotNull($response->json('error'));
    }
}
