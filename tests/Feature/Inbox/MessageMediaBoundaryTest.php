<?php

namespace Tests\Feature\Inbox;

use App\Modules\Shared\Models\ChannelAccount;
use App\Modules\Shared\Models\Contact;
use App\Modules\Shared\Models\Conversation;
use App\Modules\Shared\Models\Message;
use App\Services\StorageManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Which file the chat-media route hands back.
 *
 * The route's two checks are that the conversation is the caller's and that the
 * message is in it. Both are real. Neither says anything about the FILE, and the
 * lookup used to be a prefix scan over one directory shared by every firm on the
 * platform:
 *
 *     $files = $disk->files('message-media');
 *     $cached = collect($files)->first(fn ($f) => str_starts_with($f, "message-media/{$message->id}"));
 *
 * `messages` has a single global auto-increment id and no workspace_id, so
 * 'message-media/481' is a prefix of 'message-media/4812.jpg' — another firm's
 * picture — and the route redirected to its public URL.
 *
 * These pin the replacement: the path is read from the row, checked against the
 * caller's workspace, and never searched for.
 */
class MessageMediaBoundaryTest extends TestCase
{
    use RefreshDatabase;

    private array $ctx;

    private array $other;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        $this->ctx = $this->createWorkspaceContext();
        $this->other = $this->createWorkspaceContext();
    }

    private function conversation(array $ctx): Conversation
    {
        $account = ChannelAccount::create([
            'workspace_id' => $ctx['workspace']->id,
            'channel' => 'whatsapp',
            'status' => 'active',
            'display_name' => 'Numar',
            'phone_number_id' => '123',
            'credentials' => [],
        ]);

        return Conversation::create([
            'workspace_id' => $ctx['workspace']->id,
            'contact_id' => Contact::factory()->create(['workspace_id' => $ctx['workspace']->id])->id,
            'channel_account_id' => $account->id,
            'status' => 'open',
        ]);
    }

    private function message(Conversation $conversation, array $payload): Message
    {
        return Message::create([
            'conversation_id' => $conversation->id,
            'direction' => 'in',
            'channel' => 'whatsapp',
            'type' => 'image',
            'body' => 'poza',
            'payload' => $payload,
            'status' => 'delivered',
            'sent_at' => now(),
        ]);
    }

    private function mediaUrl(Conversation $conversation, Message $message): string
    {
        return route('client.inbox.message-media', ['conversation' => $conversation, 'message' => $message]);
    }

    // ─── the collision that used to be served ────────────────────────────

    public function test_a_neighbours_file_is_not_found_by_a_shorter_id(): void
    {
        // Exactly the old failure: the other firm's file is named with an id
        // this message's id is a prefix of, and this message has no file.
        $theirs = 'message-media/'.$this->other['workspace']->id.'/x.jpg';
        Storage::disk('public')->put($theirs, 'RADIOGRAFIA PACIENTULUI');
        Storage::disk('public')->put('message-media/'.$this->ctx['workspace']->id.'99.jpg', 'VECIN');

        $conversation = $this->conversation($this->ctx);
        $message = $this->message($conversation, ['media_id' => null]);

        // Nothing recorded and nothing to download: 404, not somebody else's file.
        $this->actingAs($this->ctx['user'])
            ->get($this->mediaUrl($conversation, $message))
            ->assertNotFound();
    }

    public function test_a_recorded_path_in_another_workspace_is_refused(): void
    {
        $theirs = 'message-media/'.$this->other['workspace']->id.'/secret.jpg';
        Storage::disk('public')->put($theirs, 'AL LOR');

        $conversation = $this->conversation($this->ctx);
        // A row naming a real file that exists — just not this firm's.
        $message = $this->message($conversation, ['media_path' => $theirs]);

        $response = $this->actingAs($this->ctx['user'])->get($this->mediaUrl($conversation, $message));

        $response->assertNotFound();
        $this->assertStringNotContainsString('secret.jpg', (string) $response->headers->get('Location'));
    }

    public function test_a_recorded_path_that_walks_out_is_refused(): void
    {
        $conversation = $this->conversation($this->ctx);
        $message = $this->message($conversation, [
            'media_path' => 'message-media/'.$this->ctx['workspace']->id.'/../../secret.jpg',
        ]);

        $this->actingAs($this->ctx['user'])
            ->get($this->mediaUrl($conversation, $message))
            ->assertNotFound();
    }

    // ─── the ordinary paths still work ───────────────────────────────────

    public function test_our_own_recorded_file_is_served(): void
    {
        $ours = 'message-media/'.$this->ctx['workspace']->id.'/poza.jpg';
        Storage::disk('public')->put($ours, 'A NOASTRA');

        $conversation = $this->conversation($this->ctx);
        $message = $this->message($conversation, ['media_path' => $ours]);

        // A row from before stage 5, so the key is on the public disk — and it
        // is handed back as BYTES rather than as a redirect to its URL. The file
        // is world-readable either way while it sits there; what changed is that
        // the thread is no longer the thing that publishes the address. New
        // media does not go to this disk at all: see PrivateMessageMediaTest.
        $response = $this->actingAs($this->ctx['user'])->get($this->mediaUrl($conversation, $message));

        $response->assertOk();
        $this->assertFalse($response->isRedirect());
        $this->assertSame('A NOASTRA', $response->getContent());
    }

    public function test_a_row_from_before_media_path_is_served_from_its_own_url(): void
    {
        $conversation = $this->conversation($this->ctx);
        // The legacy shape: preview_url was minted by ->url() on this disk at
        // the moment the bytes were written, so it already names the file and
        // there is nothing to look up.
        $message = $this->message($conversation, ['preview_url' => 'https://cdn.exemplu.ro/message-media/9.jpg']);

        $this->actingAs($this->ctx['user'])
            ->get($this->mediaUrl($conversation, $message))
            ->assertRedirect('https://cdn.exemplu.ro/message-media/9.jpg');
    }

    public function test_another_firms_conversation_is_still_refused(): void
    {
        $conversation = $this->conversation($this->other);
        $message = $this->message($conversation, ['media_path' => 'message-media/'.$this->other['workspace']->id.'/x.jpg']);

        $this->actingAs($this->ctx['user'])
            ->get($this->mediaUrl($conversation, $message))
            ->assertForbidden();
    }

    // ─── what an uploaded file may be called ─────────────────────────────

    /**
     * The extension decides what the browser DOES with the file, and these are
     * served from the application's own origin.
     */
    public function test_a_mime_type_that_would_execute_in_our_origin_is_stored_as_bin(): void
    {
        foreach (['text/html', 'application/xhtml+xml', 'text/javascript', 'application/javascript'] as $mime) {
            $this->assertSame('bin', StorageManager::extensionForMime($mime), "[{$mime}] kept an executable extension.");
        }
    }

    public function test_ordinary_media_keeps_its_real_extension(): void
    {
        $this->assertSame('jpg', StorageManager::extensionForMime('image/jpeg'));
        $this->assertSame('pdf', StorageManager::extensionForMime('application/pdf'));
        $this->assertSame('png', StorageManager::extensionForMime('image/png; charset=binary'));
    }

    public function test_an_unrecognised_mime_type_does_not_become_its_own_subtype(): void
    {
        // The old line was explode('/', $mimeType)[1], which turned
        // image/svg+xml into the extension "svg+xml" and text/html into "html".
        $this->assertSame('bin', StorageManager::extensionForMime('application/x-invented'));
        $this->assertNotSame('svg+xml', StorageManager::extensionForMime('image/svg+xml'));
    }
}
