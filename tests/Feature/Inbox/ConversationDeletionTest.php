<?php

namespace Tests\Feature\Inbox;

use App\Modules\Email\Services\MailboxIngestor;
use App\Modules\Shared\Models\ChannelAccount;
use App\Modules\Shared\Models\Contact;
use App\Modules\Shared\Models\Conversation;
use App\Modules\Shared\Models\Message;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Deleting a thread from the inbox.
 *
 * The row survives on purpose, so the parts worth pinning are the ones a soft
 * delete makes easy to get wrong: a thread that still shows up somewhere, and a
 * deleted thread that walks back in on the next mailbox poll.
 */
class ConversationDeletionTest extends TestCase
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

    private function conversation(?int $workspaceId = null, string $status = 'open'): Conversation
    {
        $workspaceId ??= $this->ctx['workspace']->id;

        return Conversation::create([
            'workspace_id' => $workspaceId,
            'channel_account_id' => $this->mailbox->id,
            'contact_id' => Contact::factory()->create(['workspace_id' => $workspaceId])->id,
            'status' => $status,
            'last_message_at' => now(),
        ]);
    }

    /** @return array<string, mixed> */
    private function mail(string $messageId, ?string $inReplyTo = null): array
    {
        return [
            'message_id' => $messageId,
            'in_reply_to' => $inReplyTo,
            'references' => [],
            'from_email' => 'ana@client.ro',
            'from_name' => 'Ana Pop',
            'subject' => 'Întrebare despre comandă',
            'text' => 'Bună ziua',
            'date' => Carbon::now(),
            'automated' => false,
            'automated_reason' => null,
        ];
    }

    // ─── it really is gone ───────────────────────────────────────────────

    public function test_a_deleted_thread_leaves_the_list_and_the_counts(): void
    {
        $kept = $this->conversation();
        $gone = $this->conversation();

        $this->actingAs($this->ctx['user'])
            ->delete(route('client.inbox.destroy', $gone->uuid))
            ->assertRedirect(route('client.inbox.index'));

        $response = $this->actingAs($this->ctx['user'])->get(route('client.inbox.index'));
        $props = $response->viewData('page')['props'];

        $uuids = collect($props['conversations']['data'])->pluck('uuid')->all();
        $this->assertContains($kept->uuid, $uuids);
        $this->assertNotContains($gone->uuid, $uuids);
        $this->assertSame(1, (int) $props['counts']['views']['all']);

        // Soft: the row is still there to be recovered from.
        $this->assertNotNull($gone->fresh()?->deleted_at);
    }

    public function test_the_deleted_thread_can_no_longer_be_opened(): void
    {
        $conversation = $this->conversation();
        $conversation->delete();

        $this->actingAs($this->ctx['user'])
            ->get(route('client.inbox.show', $conversation->uuid))
            ->assertNotFound();
    }

    public function test_it_leaves_the_nav_badge_too(): void
    {
        $this->conversation();
        $gone = $this->conversation();

        $this->actingAs($this->ctx['user'])->delete(route('client.inbox.destroy', $gone->uuid));

        $props = $this->actingAs($this->ctx['user'])->get(route('client.dashboard'))->viewData('page')['props'];
        $this->assertSame(1, (int) $props['inboxOpenCount']);
    }

    // ─── who may delete what ─────────────────────────────────────────────

    public function test_another_workspace_cannot_delete_a_thread(): void
    {
        $conversation = $this->conversation();
        $intruder = $this->createWorkspaceContext();

        $this->actingAs($intruder['user'])
            ->delete(route('client.inbox.destroy', $conversation->uuid))
            ->assertForbidden();

        $this->assertNull($conversation->fresh()?->deleted_at);
    }

    public function test_a_bulk_delete_ignores_ids_from_another_workspace(): void
    {
        $mine = $this->conversation();
        $intruder = $this->createWorkspaceContext();
        $theirs = Conversation::create([
            'workspace_id' => $intruder['workspace']->id,
            'contact_id' => Contact::factory()->create(['workspace_id' => $intruder['workspace']->id])->id,
            'status' => 'open',
            'last_message_at' => now(),
        ]);

        $this->actingAs($this->ctx['user'])
            ->delete(route('client.inbox.destroy-many'), ['uuids' => [$mine->uuid, $theirs->uuid]])
            ->assertRedirect();

        $this->assertNotNull($mine->fresh()?->deleted_at);
        // Not an error, simply no match: the workspace is a condition of the
        // query rather than a check that could be forgotten.
        $this->assertNull($theirs->fresh()?->deleted_at);
    }

    public function test_a_bulk_delete_removes_every_thread_that_was_ticked(): void
    {
        $a = $this->conversation();
        $b = $this->conversation();
        $untouched = $this->conversation();

        $this->actingAs($this->ctx['user'])
            ->delete(route('client.inbox.destroy-many'), ['uuids' => [$a->uuid, $b->uuid]])
            ->assertRedirect();

        $this->assertNotNull($a->fresh()?->deleted_at);
        $this->assertNotNull($b->fresh()?->deleted_at);
        $this->assertNull($untouched->fresh()?->deleted_at);
    }

    public function test_a_bulk_delete_needs_at_least_one_id(): void
    {
        $this->actingAs($this->ctx['user'])
            ->delete(route('client.inbox.destroy-many'), ['uuids' => []])
            ->assertSessionHasErrors('uuids');
    }

    // ─── and it stays gone when the mailbox is read again ────────────────

    public function test_a_deleted_thread_does_not_come_back_on_the_next_poll(): void
    {
        $ingestor = app(MailboxIngestor::class);
        $first = $ingestor->ingest($this->mailbox, $this->mail('root@client.ro'));
        $first->conversation->delete();

        // A poll that re-offers the same message — a uid reset, or an overlapping
        // run. De-duplication has to see through the tombstone or the whole
        // thread is filed a second time.
        $again = $ingestor->ingest($this->mailbox, $this->mail('root@client.ro'));

        $this->assertNull($again);
        $this->assertSame(1, Message::where('provider_message_id', 'root@client.ro')->count());
        $this->assertSame(0, Conversation::where('workspace_id', $this->ctx['workspace']->id)->count());
    }

    public function test_a_new_reply_after_a_delete_opens_a_new_thread(): void
    {
        $ingestor = app(MailboxIngestor::class);
        $first = $ingestor->ingest($this->mailbox, $this->mail('root@client.ro'));
        $first->conversation->delete();

        $reply = $ingestor->ingest($this->mailbox, $this->mail('reply@client.ro', 'root@client.ro'));

        // Deleting the thread hid what was there; it did not block the sender.
        $this->assertNotNull($reply);
        $this->assertNotSame($first->conversation_id, $reply->conversation_id);
        $this->assertSame(1, Conversation::where('workspace_id', $this->ctx['workspace']->id)->count());
    }

    public function test_a_deleted_thread_is_gone_from_the_contact_record_too(): void
    {
        $conversation = $this->conversation();
        $contact = Contact::find($conversation->contact_id);

        $before = $this->actingAs($this->ctx['user'])->get(route('client.contacts.show', $contact->uuid))
            ->viewData('page')['props'];
        $this->assertSame(1, (int) $before['summary']['conversations']);

        $conversation->delete();

        // The contact page counts through the query builder, which knows nothing
        // about the model's soft delete unless it is told.
        $after = $this->actingAs($this->ctx['user'])->get(route('client.contacts.show', $contact->uuid))
            ->viewData('page')['props'];
        $this->assertSame(0, (int) $after['summary']['conversations']);
        $this->assertNull($after['summary']['last_at']);
    }

    public function test_deleting_the_open_thread_in_bulk_lands_on_the_list(): void
    {
        $open = $this->conversation();

        // Without this the redirect goes back to the thread that was just
        // deleted, and route binding answers a 404 instead of a list.
        $this->actingAs($this->ctx['user'])
            ->from(route('client.inbox.show', $open->uuid))
            ->delete(route('client.inbox.destroy-many'), ['uuids' => [$open->uuid], 'to_index' => true])
            ->assertRedirect(route('client.inbox.index'));
    }

    public function test_a_bulk_delete_from_the_list_stays_where_it_was(): void
    {
        $a = $this->conversation();
        $open = $this->conversation();

        $this->actingAs($this->ctx['user'])
            ->from(route('client.inbox.show', $open->uuid))
            ->delete(route('client.inbox.destroy-many'), ['uuids' => [$a->uuid]])
            ->assertRedirect(route('client.inbox.show', $open->uuid));
    }
}
