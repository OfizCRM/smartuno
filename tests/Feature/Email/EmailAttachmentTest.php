<?php

namespace Tests\Feature\Email;

use App\Modules\Email\Services\AttachmentStore;
use App\Modules\Shared\Models\ChannelAccount;
use App\Modules\Shared\Models\Contact;
use App\Modules\Shared\Models\Conversation;
use App\Modules\Shared\Models\Message;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

/**
 * Files that arrive with an email, and files sent back with a reply.
 *
 * The parts worth pinning are the ones that bite later: a stored name that could
 * be executed, a public URL that leaks a tenant's invoice, and an unbounded disk.
 */
class EmailAttachmentTest extends TestCase
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
}
