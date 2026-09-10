<?php

namespace Tests\Feature\Email;

use App\Modules\Shared\Models\ChannelAccount;
use App\Modules\Shared\Models\Contact;
use App\Modules\Shared\Models\Conversation;
use App\Modules\Shared\Models\Message;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Who gets to say which file an attachment route serves.
 *
 * The attachment route's two checks are that the conversation belongs to the
 * caller's workspace and that the message belongs to the conversation. Both are
 * real and both pass when a person replies in their own thread — and neither
 * says anything about which FILE the message's payload names.
 *
 * `payload` was validated only as "an array", and the stored value was whatever
 * the caller sent. So a plain-text reply in one's own conversation could carry
 * a hand-written attachments entry naming any path, and the route would read
 * those bytes back. Adding a `disk` key to those entries widened it from one
 * disk to every configured disk.
 *
 * Two independent fixes, and this pins both: the caller can no longer set those
 * keys, and the route refuses an address it would not have written itself.
 */
class AttachmentAddressingTest extends TestCase
{
    use RefreshDatabase;

    private array $ctx;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Storage::fake('public');
        $this->ctx = $this->createWorkspaceContext();
    }

    private function conversation(array $ctx): Conversation
    {
        $account = ChannelAccount::create([
            'workspace_id' => $ctx['workspace']->id,
            'channel' => 'email',
            'status' => 'active',
            'display_name' => 'Cutie',
            'phone_number_id' => null,
            'credentials' => [],
        ]);

        return Conversation::create([
            'workspace_id' => $ctx['workspace']->id,
            'contact_id' => Contact::factory()->create(['workspace_id' => $ctx['workspace']->id])->id,
            'channel_account_id' => $account->id,
            'status' => 'open',
        ]);
    }

    /** A message whose payload names a file, written directly — the state a poisoned row is in. */
    private function messageNaming(Conversation $conversation, string $path, ?string $disk): Message
    {
        $entry = ['name' => 'factura.pdf', 'mime' => 'application/pdf', 'size' => 9, 'path' => $path, 'stored' => true];

        if ($disk !== null) {
            $entry['disk'] = $disk;
        }

        return Message::create([
            'conversation_id' => $conversation->id,
            'direction' => 'out',
            'channel' => 'email',
            'type' => 'text',
            'body' => 'salut',
            'payload' => ['attachments' => [$entry]],
            'status' => 'sent',
            'sent_at' => now(),
        ]);
    }

    // ─── the write side: the caller cannot address a file ────────────────

    public function test_a_reply_cannot_carry_an_attachment_the_caller_wrote_by_hand(): void
    {
        $conversation = $this->conversation($this->ctx);

        $this->actingAs($this->ctx['user'])->postJson(
            route('client.inbox.reply', $conversation),
            [
                'body' => 'text obisnuit',
                'type' => 'text',
                'payload' => ['attachments' => [
                    ['name' => 'furat.pdf', 'path' => 'email-attachments/al-altcuiva.pdf', 'disk' => 'local', 'stored' => true],
                ]],
            ],
        );

        $message = Message::where('conversation_id', $conversation->id)->where('direction', 'out')->first();

        $this->assertNotNull($message);
        // The server builds this key when it stores files. It is never taken
        // from the request, so a hand-written entry has nowhere to land.
        $this->assertArrayNotHasKey('attachments', (array) ($message->payload ?? []));
    }

    public function test_the_other_file_addressing_keys_are_stripped_too(): void
    {
        $conversation = $this->conversation($this->ctx);

        $this->actingAs($this->ctx['user'])->postJson(
            route('client.inbox.reply', $conversation),
            [
                'body' => 'text',
                'type' => 'text',
                'payload' => ['media_id' => 'furat', 'preview_url' => 'https://x/y', 'link' => 'https://x/z', 'path' => 'a/b'],
            ],
        );

        $payload = (array) (Message::where('conversation_id', $conversation->id)
            ->where('direction', 'out')->first()?->payload ?? []);

        foreach (['media_id', 'preview_url', 'link', 'path', 'disk'] as $key) {
            $this->assertArrayNotHasKey($key, $payload, "[{$key}] reached the stored payload from the request.");
        }
    }

    // ─── the read side: an address we would not have written is refused ──

    public function test_a_path_outside_the_attachment_directory_is_refused(): void
    {
        Storage::disk('local')->put('documents/contract-secret.pdf', 'CONTRACT');
        $conversation = $this->conversation($this->ctx);
        $message = $this->messageNaming($conversation, 'documents/contract-secret.pdf', 'local');

        // The document library has its own route with its own checks. This one
        // serves email attachments and nothing else.
        $this->actingAs($this->ctx['user'])
            ->get(route('client.email.attachment', [$conversation, $message, 0]))
            ->assertNotFound();
    }

    public function test_a_path_that_walks_out_of_the_directory_is_refused(): void
    {
        $conversation = $this->conversation($this->ctx);
        $message = $this->messageNaming($conversation, 'email-attachments/../documents/secret.pdf', 'local');

        $this->actingAs($this->ctx['user'])
            ->get(route('client.email.attachment', [$conversation, $message, 0]))
            ->assertNotFound();
    }

    public function test_a_disk_we_never_write_private_files_to_is_refused(): void
    {
        Storage::disk('public')->put('email-attachments/oricare.pdf', 'PUBLIC');
        $conversation = $this->conversation($this->ctx);
        $message = $this->messageNaming($conversation, 'email-attachments/oricare.pdf', 'public');

        // 'public' is a real, configured disk. It is not one private files live
        // on, so naming it is not something we ever wrote.
        $this->actingAs($this->ctx['user'])
            ->get(route('client.email.attachment', [$conversation, $message, 0]))
            ->assertNotFound();
    }

    public function test_an_ordinary_attachment_still_opens(): void
    {
        Storage::disk('local')->put('email-attachments/factura.pdf', 'FACTURA');
        $conversation = $this->conversation($this->ctx);
        $message = $this->messageNaming($conversation, 'email-attachments/factura.pdf', 'local');

        // The guards must not have closed the door on the ordinary case.
        $this->actingAs($this->ctx['user'])
            ->get(route('client.email.attachment', [$conversation, $message, 0]))
            ->assertOk();
    }

    public function test_an_entry_from_before_the_disk_column_still_opens(): void
    {
        Storage::disk('local')->put('email-attachments/vechi.pdf', 'VECHI');
        $conversation = $this->conversation($this->ctx);
        $message = $this->messageNaming($conversation, 'email-attachments/vechi.pdf', null);

        $this->actingAs($this->ctx['user'])
            ->get(route('client.email.attachment', [$conversation, $message, 0]))
            ->assertOk();
    }

    // ─── what the browser is told ────────────────────────────────────────

    public function test_the_browser_is_not_told_where_the_file_sits(): void
    {
        $conversation = $this->conversation($this->ctx);
        $message = $this->messageNaming($conversation, 'email-attachments/factura.pdf', 'local');

        $entry = $message->toArray()['payload']['attachments'][0];

        // Both halves of an address, in page source anyone reading the thread
        // can read. The route addresses the file by message and index instead.
        $this->assertArrayNotHasKey('path', $entry);
        $this->assertArrayNotHasKey('disk', $entry);

        // What the UI actually asked `path`: was it stored, and what is it.
        $this->assertTrue($entry['stored']);
        $this->assertSame('factura.pdf', $entry['name']);
    }

    public function test_a_file_too_large_to_store_still_says_so(): void
    {
        $conversation = $this->conversation($this->ctx);
        $message = $this->messageNaming($conversation, 'email-attachments/x.pdf', 'local');
        $message->payload = ['attachments' => [['name' => 'urias.zip', 'size' => 99, 'path' => null, 'stored' => false]]];

        $entry = $message->toArray()['payload']['attachments'][0];

        // Derived from the path, not read from the row: `stored` post-dates
        // some rows, a null path has always meant the same thing.
        $this->assertFalse($entry['stored']);
    }

    public function test_php_still_sees_the_full_payload(): void
    {
        $conversation = $this->conversation($this->ctx);
        $message = $this->messageNaming($conversation, 'email-attachments/factura.pdf', 'local');

        // The hiding is for serialisation only. Take it further than that and
        // the attachment route loses the very thing it needs to serve bytes.
        $this->assertSame('email-attachments/factura.pdf', $message->payload['attachments'][0]['path']);
        $this->assertSame('local', $message->payload['attachments'][0]['disk']);
    }

    public function test_a_message_with_no_attachments_is_untouched(): void
    {
        $conversation = $this->conversation($this->ctx);
        $message = Message::create([
            'conversation_id' => $conversation->id,
            'direction' => 'in',
            'channel' => 'whatsapp',
            'type' => 'interactive',
            'body' => 'salut',
            'payload' => ['buttons' => [['id' => 'da', 'title' => 'Da']]],
            'status' => 'delivered',
            'sent_at' => now(),
        ]);

        // Interactive payloads are most of what this cast carries, and they
        // have to reach the browser whole or the message does not render.
        $this->assertSame(
            [['id' => 'da', 'title' => 'Da']],
            $message->toArray()['payload']['buttons'],
        );
    }

    public function test_another_firms_conversation_is_still_refused(): void
    {
        Storage::disk('local')->put('email-attachments/al-lor.pdf', 'AL LOR');
        $intruder = $this->createWorkspaceContext();
        $conversation = $this->conversation($intruder);
        $message = $this->messageNaming($conversation, 'email-attachments/al-lor.pdf', 'local');

        $this->actingAs($this->ctx['user'])
            ->get(route('client.email.attachment', [$conversation, $message, 0]))
            ->assertForbidden();
    }
}
