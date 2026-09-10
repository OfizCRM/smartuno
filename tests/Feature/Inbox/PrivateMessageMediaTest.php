<?php

namespace Tests\Feature\Inbox;

use App\Modules\Inbox\Services\MessageMediaStore;
use App\Modules\Shared\Models\ChannelAccount;
use App\Modules\Shared\Models\Contact;
use App\Modules\Shared\Models\Conversation;
use App\Modules\Shared\Models\Message;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Tests\Concerns\FakesPrivateDisk;
use Tests\TestCase;

/**
 * Chat media stopped being world-readable.
 *
 * Until stage 5 every photo, voice note and file in every conversation sat on
 * the disk nginx publishes, and the thread handed the browser its URL. Anyone
 * who had the link read it: a forwarded screenshot, a support ticket, a
 * bookmark, someone who left the firm. There was no session in the way and no
 * record that it happened.
 *
 * These pin the three things that had to become true together: the bytes are on
 * the private disk, the only way in is a route that resolves the workspace, and
 * the one exception — Meta, which fetches an address rather than accepting a
 * file — is a signature with an expiry rather than a hole.
 */
class PrivateMessageMediaTest extends TestCase
{
    use FakesPrivateDisk, RefreshDatabase;

    private array $ctx;

    private array $other;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakePrivateDisk();
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

    /** A stored photo, exactly as the composer and the downloader write one. */
    private function storedMessage(array $ctx, string $bytes = 'CONTINUT-POZA'): array
    {
        $conversation = $this->conversation($ctx);
        $entry = app(MessageMediaStore::class)->put($ctx['workspace']->id, 'poza.jpg', 'image/jpeg', $bytes);

        $message = Message::create([
            'conversation_id' => $conversation->id,
            'direction' => 'in',
            'channel' => 'whatsapp',
            'type' => 'image',
            'body' => 'poza',
            'payload' => ['media_path' => $entry['path'], 'media_disk' => $entry['disk'], 'filename' => 'poza.jpg'],
            'status' => 'delivered',
            'sent_at' => now(),
        ]);

        return [$conversation, $message, $entry];
    }

    private function mediaUrl(Conversation $conversation, Message $message): string
    {
        return route('client.inbox.message-media', ['conversation' => $conversation, 'message' => $message]);
    }

    // ─── it is on the private disk, and only there ───────────────────────

    public function test_media_is_written_to_the_private_disk_not_the_published_one(): void
    {
        [, , $entry] = $this->storedMessage($this->ctx);

        $this->assertSame($this->privateDiskName(), $entry['disk']);
        $this->assertTrue(Storage::disk($this->privateDiskName())->exists($entry['path']));

        // The disk the web server serves without asking anyone anything.
        $this->assertFalse(
            Storage::disk('public')->exists($entry['path']),
            'A conversation photo is on the published disk, which is where this whole stage was about it not being.'
        );
    }

    public function test_the_browser_is_not_told_where_the_file_is(): void
    {
        [, $message] = $this->storedMessage($this->ctx);
        $payload = $message->toArray()['payload'];

        $this->assertArrayNotHasKey('media_path', $payload);
        $this->assertArrayNotHasKey('media_disk', $payload);

        // And no direct address either — that key is what used to make the file
        // loadable straight from /storage/ with no session.
        $this->assertNull($payload['preview_url'] ?? null);

        // It is told THAT there is one, though. The thread used to infer that
        // from media_id, which only WhatsApp sets; a Messenger picture has a
        // stored file and no media_id, and without this renders nothing.
        $this->assertTrue($payload['has_media']);
    }

    public function test_a_message_with_no_stored_file_is_not_flagged_as_having_one(): void
    {
        $conversation = $this->conversation($this->ctx);
        $message = Message::create([
            'conversation_id' => $conversation->id,
            'direction' => 'out',
            'channel' => 'whatsapp',
            'type' => 'text',
            'body' => 'doar text',
            'payload' => ['caption' => 'x'],
            'status' => 'sent',
            'sent_at' => now(),
        ]);

        // Otherwise every text message would have the thread request a file.
        $this->assertArrayNotHasKey('has_media', $message->toArray()['payload']);
    }

    public function test_php_still_sees_the_path(): void
    {
        [, $message] = $this->storedMessage($this->ctx);

        // The hiding is serialisation only; the route needs the value to serve.
        $this->assertNotEmpty($message->payload['media_path']);
    }

    // ─── the session route ───────────────────────────────────────────────

    public function test_our_own_media_is_served_as_bytes(): void
    {
        [$conversation, $message] = $this->storedMessage($this->ctx, 'OCTETII-POZEI');

        $response = $this->actingAs($this->ctx['user'])->get($this->mediaUrl($conversation, $message));

        $response->assertOk();
        $response->assertHeader('Content-Type', 'image/jpeg');
        $response->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->assertSame('OCTETII-POZEI', $response->getContent());

        // Not a redirect any more. A redirect to a private disk means nothing,
        // and a redirect to a public one is the thing being removed.
        $this->assertFalse($response->isRedirect());
    }

    public function test_another_firms_conversation_is_refused(): void
    {
        [$conversation, $message] = $this->storedMessage($this->other);

        $this->actingAs($this->ctx['user'])
            ->get($this->mediaUrl($conversation, $message))
            ->assertForbidden();
    }

    public function test_a_path_belonging_to_another_firm_is_refused(): void
    {
        $theirs = app(MessageMediaStore::class)->put($this->other['workspace']->id, 'lor.jpg', 'image/jpeg', 'AL LOR');
        $conversation = $this->conversation($this->ctx);

        // Our conversation, our message — naming their file.
        $message = Message::create([
            'conversation_id' => $conversation->id,
            'direction' => 'in',
            'channel' => 'whatsapp',
            'type' => 'image',
            'body' => 'x',
            'payload' => ['media_path' => $theirs['path'], 'media_disk' => $theirs['disk']],
            'status' => 'delivered',
            'sent_at' => now(),
        ]);

        $response = $this->actingAs($this->ctx['user'])->get($this->mediaUrl($conversation, $message));

        $response->assertNotFound();
        $this->assertStringNotContainsString('AL LOR', $response->getContent());
    }

    /**
     * The guard is a shape, not a list of forbidden strings.
     *
     * Rejecting '..' alone let an encoded traversal through. It could not be
     * reached — Flysystem does not decode, and media_path is stripped from every
     * request — but a guard that reads as "inside this firm's directory" while
     * accepting a path that is not is the kind that is later relied on.
     */
    public function test_only_a_single_file_name_inside_our_own_directory_is_addressable(): void
    {
        $store = app(MessageMediaStore::class);
        $ws = $this->ctx['workspace']->id;

        $this->assertTrue($store->addressableBy($store->put($ws, 'a.jpg', 'image/jpeg', 'X')['path'], $ws));

        foreach ([
            'message-media/'.$ws.'/../'.$this->other['workspace']->id.'/x.jpg' => 'traversal',
            'message-media/'.$ws.'/%2e%2e/x.jpg' => 'encoded traversal',
            'message-media/'.$ws.'/..%2fx.jpg' => 'encoded separator',
            'message-media/'.$ws.'/sub/x.jpg' => 'a subdirectory',
            'message-media/'.$ws.'x.jpg' => 'no separator at all',
            '/message-media/'.$ws.'/x.jpg' => 'a leading slash',
            'message-media/'.$ws => 'the directory itself',
        ] as $path => $shape) {
            $this->assertFalse($store->addressableBy($path, $ws), "[{$shape}] was accepted.");
        }
    }

    /**
     * The fault stage 4 removed from serveMedia, checked in its replacement.
     *
     * Workspace 1 and workspace 11 share a prefix. Without the trailing slash on
     * the directory, every file of firm 11 is addressable by firm 1.
     */
    public function test_a_workspace_id_that_is_a_prefix_of_another_does_not_match_it(): void
    {
        $store = app(MessageMediaStore::class);

        $this->assertFalse($store->addressableBy('message-media/11/x.jpg', 1));
        $this->assertFalse($store->addressableBy('message-media/1/x.jpg', 11));
        $this->assertFalse($store->addressableBy('message-media/111/x.jpg', 11));
        $this->assertTrue($store->addressableBy('message-media/11/x.jpg', 11));
    }

    public function test_a_guest_gets_nothing(): void
    {
        [$conversation, $message] = $this->storedMessage($this->ctx);

        $this->get($this->mediaUrl($conversation, $message))->assertRedirect();
    }

    // ─── the signed door Meta uses ───────────────────────────────────────

    public function test_a_signed_url_serves_without_a_session(): void
    {
        [, $message] = $this->storedMessage($this->ctx, 'PENTRU-META');

        $url = URL::temporarySignedRoute('media.outbound', now()->addMinutes(30), ['message' => $message->id]);

        // No actingAs: this is exactly what Meta's fetcher is.
        $response = $this->get($url);

        $response->assertOk();
        $this->assertSame('PENTRU-META', $response->getContent());
    }

    public function test_the_signed_url_stops_working_when_it_expires(): void
    {
        [, $message] = $this->storedMessage($this->ctx);

        $url = URL::temporarySignedRoute('media.outbound', now()->addMinutes(30), ['message' => $message->id]);

        $this->travel(31)->minutes();

        // The whole difference between this and a public file.
        $this->get($url)->assertForbidden();
    }

    public function test_an_unsigned_request_to_the_outbound_route_is_refused(): void
    {
        [, $message] = $this->storedMessage($this->ctx);

        $this->get(route('media.outbound', ['message' => $message->id]))->assertForbidden();
    }

    public function test_a_tampered_signature_is_refused(): void
    {
        [, $message] = $this->storedMessage($this->ctx);
        $theirs = $this->storedMessage($this->other)[1];

        $url = URL::temporarySignedRoute('media.outbound', now()->addMinutes(30), ['message' => $message->id]);

        // Swap the message id for another firm's and keep the signature.
        $this->get(str_replace('/'.$message->id.'?', '/'.$theirs->id.'?', $url))->assertForbidden();
    }

    // ─── rows written before the move ────────────────────────────────────

    public function test_a_file_stored_on_the_public_disk_before_the_move_still_opens(): void
    {
        $conversation = $this->conversation($this->ctx);
        $legacy = 'message-media/'.$this->ctx['workspace']->id.'/veche.jpg';
        Storage::disk('public')->put($legacy, 'POZA-VECHE');

        $message = Message::create([
            'conversation_id' => $conversation->id,
            'direction' => 'in',
            'channel' => 'whatsapp',
            'type' => 'image',
            'body' => 'x',
            // No media_disk: the key did not exist when this row was written.
            'payload' => ['media_path' => $legacy],
            'status' => 'delivered',
            'sent_at' => now(),
        ]);

        $response = $this->actingAs($this->ctx['user'])->get($this->mediaUrl($conversation, $message));

        $response->assertOk();
        $this->assertSame('POZA-VECHE', $response->getContent());
    }
}
