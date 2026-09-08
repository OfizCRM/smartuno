<?php

namespace Tests\Feature\Inbox;

use App\Modules\Shared\Models\ChannelAccount;
use App\Modules\Shared\Models\Contact;
use App\Modules\Shared\Models\Conversation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The number beside Inbox in the sidebar rail.
 *
 * It is shared on every client request, so the two things worth pinning are that
 * it counts the right rows and that it is never computed for someone who has no
 * workspace to count.
 */
class InboxNavBadgeTest extends TestCase
{
    use RefreshDatabase;

    private function conversation(int $workspaceId, ChannelAccount $account, string $status): Conversation
    {
        return Conversation::create([
            'workspace_id' => $workspaceId,
            'channel_account_id' => $account->id,
            'contact_id' => Contact::factory()->create(['workspace_id' => $workspaceId])->id,
            'status' => $status,
            'last_message_at' => now(),
        ]);
    }

    private function account(int $workspaceId): ChannelAccount
    {
        return ChannelAccount::create([
            'workspace_id' => $workspaceId,
            'channel' => 'whatsapp',
            'provider' => 'meta',
            'status' => 'active',
            'display_name' => 'WA',
        ]);
    }

    private function badge($response): int
    {
        return $response->viewData('page')['props']['inboxOpenCount'];
    }

    public function test_it_counts_open_and_pending_but_not_resolved_or_snoozed(): void
    {
        $ctx = $this->createWorkspaceContext();
        $account = $this->account($ctx['workspace']->id);

        $this->conversation($ctx['workspace']->id, $account, 'open');
        $this->conversation($ctx['workspace']->id, $account, 'open');
        $this->conversation($ctx['workspace']->id, $account, 'pending');
        $this->conversation($ctx['workspace']->id, $account, 'resolved');
        $this->conversation($ctx['workspace']->id, $account, 'snoozed');

        $response = $this->actingAs($ctx['user'])->get(route('client.dashboard'));

        // Same predicate as the "Toate" view, so the badge and the list agree.
        $this->assertSame(3, $this->badge($response));
    }

    public function test_it_never_counts_another_workspace(): void
    {
        $mine = $this->createWorkspaceContext();
        $theirs = $this->createWorkspaceContext();

        $this->conversation($mine['workspace']->id, $this->account($mine['workspace']->id), 'open');

        $theirAccount = $this->account($theirs['workspace']->id);
        $this->conversation($theirs['workspace']->id, $theirAccount, 'open');
        $this->conversation($theirs['workspace']->id, $theirAccount, 'open');

        $response = $this->actingAs($mine['user'])->get(route('client.dashboard'));

        $this->assertSame(1, $this->badge($response));
    }

    public function test_it_is_zero_rather_than_absent_when_there_is_nothing_to_count(): void
    {
        $ctx = $this->createWorkspaceContext();

        $response = $this->actingAs($ctx['user'])->get(route('client.dashboard'));

        $this->assertSame(0, $this->badge($response));
    }
}
