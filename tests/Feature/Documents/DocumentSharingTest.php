<?php

namespace Tests\Feature\Documents;

use App\Modules\Documents\Models\Document;
use App\Modules\Email\Services\AttachmentStore;
use App\Modules\Shared\Models\ChannelAccount;
use App\Modules\Shared\Models\Contact;
use App\Modules\Shared\Models\Conversation;
use App\Modules\Shared\Models\Message;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\FakesPrivateDisk;
use Tests\TestCase;

/**
 * Files moving between a thread and the library.
 *
 * The rule under all of it is that the two keep their own bytes: a document
 * deleted next spring must not empty a mail sent last autumn, and an attachment
 * purged from a thread must not take the filed contract with it.
 */
class DocumentSharingTest extends TestCase
{
    use FakesPrivateDisk, RefreshDatabase;

    private array $ctx;

    private ChannelAccount $mailbox;

    protected function setUp(): void
    {
        parent::setUp();
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

    private function conversation(?int $workspaceId = null): Conversation
    {
        $workspaceId ??= $this->ctx['workspace']->id;

        return Conversation::create([
            'workspace_id' => $workspaceId,
            'channel_account_id' => $this->mailbox->id,
            'contact_id' => Contact::factory()->create(['workspace_id' => $workspaceId, 'email' => 'ana@client.ro'])->id,
            'status' => 'open',
            'last_message_at' => now(),
        ]);
    }

    private function messageWithAttachment(Conversation $conversation): array
    {
        $entry = app(AttachmentStore::class)->put('factura-furnizor.pdf', 'application/pdf', 'PDF-CONTENT');

        $message = Message::create([
            'conversation_id' => $conversation->id,
            'direction' => 'in',
            'channel' => 'email',
            'type' => 'text',
            'body' => 'Vezi factura',
            'payload' => ['subject' => 'Factura', 'attachments' => [$entry]],
            'status' => 'delivered',
            'sent_at' => now(),
        ]);

        return [$message, $entry];
    }

    // ─── thread → library ────────────────────────────────────────────────

    public function test_an_attachment_can_be_filed_and_arrives_with_its_context(): void
    {
        $conversation = $this->conversation();
        [$message, $entry] = $this->messageWithAttachment($conversation);

        $this->actingAs($this->ctx['user'])->postJson(route('client.documents.from-message'), [
            'conversation' => $conversation->uuid,
            'message_id' => $message->id,
            'index' => 0,
        ])->assertOk();

        $document = Document::first();

        $this->assertSame('factura-furnizor.pdf', $document->name);
        $this->assertSame('conversation', $document->source);
        $this->assertSame($message->id, $document->source_message_id);
        // The thread already knew who this was about.
        $this->assertSame($conversation->contact_id, $document->contact_id);
        $this->assertSame($this->ctx['user']->id, $document->created_by);
    }

    public function test_the_two_copies_are_independent(): void
    {
        $conversation = $this->conversation();
        [$message, $entry] = $this->messageWithAttachment($conversation);

        $this->actingAs($this->ctx['user'])->postJson(route('client.documents.from-message'), [
            'conversation' => $conversation->uuid,
            'message_id' => $message->id,
            'index' => 0,
        ]);

        $document = Document::first();

        // Different files on the disk, same contents.
        $this->assertNotSame($entry['path'], $document->path);
        $this->assertSame('PDF-CONTENT', app(AttachmentStore::class)->contents($document->path));

        // Deleting the document for good leaves the mail intact.
        $document->delete();
        $this->artisan('documents:purge', ['--days' => 0]);
        $this->assertTrue($this->privateDisk()->exists($entry['path']));
        // Filing a mail attachment into the library copies it; neither copy may
        // land on the disk the web server publishes.
        $this->assertNotOnTheWebServersDisk($entry['path'], "The mail's attachment");
        $this->assertNotOnTheWebServersDisk($document->path, 'The filed document');
    }

    public function test_a_thread_in_another_workspace_cannot_be_filed_from(): void
    {
        $intruderCtx = $this->createWorkspaceContext();
        $conversation = $this->conversation($intruderCtx['workspace']->id);
        [$message] = $this->messageWithAttachment($conversation);

        $this->actingAs($this->ctx['user'])->postJson(route('client.documents.from-message'), [
            'conversation' => $conversation->uuid,
            'message_id' => $message->id,
            'index' => 0,
        ])->assertNotFound();

        $this->assertSame(0, Document::count());
    }

    public function test_a_message_from_a_different_conversation_is_not_reachable(): void
    {
        $mine = $this->conversation();
        $other = $this->conversation();
        [$message] = $this->messageWithAttachment($other);

        $this->actingAs($this->ctx['user'])->postJson(route('client.documents.from-message'), [
            'conversation' => $mine->uuid,
            'message_id' => $message->id,
            'index' => 0,
        ])->assertNotFound();
    }

    // ─── library → thread ────────────────────────────────────────────────

    private function document(?int $workspaceId = null): Document
    {
        $workspaceId ??= $this->ctx['workspace']->id;
        $entry = app(AttachmentStore::class)->put('oferta.pdf', 'application/pdf', 'OFERTA');

        return Document::create([
            'workspace_id' => $workspaceId,
            'name' => 'oferta.pdf',
            'path' => $entry['path'],
            'mime' => 'application/pdf',
            'extension' => 'pdf',
            'size_bytes' => $entry['size'],
        ]);
    }

    public function test_a_filed_document_can_be_sent_on_an_email_thread(): void
    {
        $conversation = $this->conversation();
        $document = $this->document();

        $this->actingAs($this->ctx['user'])->postJson(route('client.inbox.reply', $conversation->uuid), [
            'body' => 'Vă trimit oferta',
            'document_uuids' => [$document->uuid],
        ])->assertOk();

        $attachment = Message::where('conversation_id', $conversation->id)->latest('id')->first()->payload['attachments'][0];

        $this->assertSame('oferta.pdf', $attachment['name']);
        $this->assertTrue($attachment['stored']);
        // The copy remembers where it came from, without depending on it.
        $this->assertSame($document->id, $attachment['document_id']);
        $this->assertNotSame($document->path, $attachment['path']);
        // And where it went. The copy is a separate write, so it can land on a
        // different disk from the document it was made from — that is the whole
        // reason the entry records one instead of assuming.
        $this->assertSame($this->privateDiskName(), $attachment['disk']);
        $this->assertNotOnTheWebServersDisk($attachment['path'], 'A document sent on a thread');
    }

    public function test_a_document_from_another_workspace_is_not_sent(): void
    {
        $conversation = $this->conversation();
        $intruderCtx = $this->createWorkspaceContext();
        $theirs = $this->document($intruderCtx['workspace']->id);

        $this->actingAs($this->ctx['user'])->postJson(route('client.inbox.reply', $conversation->uuid), [
            'body' => 'Ceva',
            'document_uuids' => [$theirs->uuid],
        ])->assertOk();

        $payload = Message::where('conversation_id', $conversation->id)->latest('id')->first()->payload;

        // Not an error and not attached: ownership is a condition of the lookup.
        $this->assertArrayNotHasKey('attachments', $payload ?? []);
    }

    public function test_a_channel_that_cannot_carry_one_says_so(): void
    {
        $whatsapp = ChannelAccount::create([
            'workspace_id' => $this->ctx['workspace']->id,
            'channel' => 'whatsapp',
            'provider' => 'meta',
            'status' => 'active',
            'display_name' => '+40712345678',
        ]);
        $conversation = $this->conversation();
        $conversation->update(['channel_account_id' => $whatsapp->id]);

        $response = $this->actingAs($this->ctx['user'])->postJson(route('client.inbox.reply', $conversation->uuid), [
            'body' => 'Vezi oferta',
            'document_uuids' => [$this->document()->uuid],
        ]);

        // Meta's media API is not wired up for this, and an empty message would
        // be worse than a refusal.
        $response->assertStatus(422);
        $this->assertNotNull($response->json('error'));
    }
}
