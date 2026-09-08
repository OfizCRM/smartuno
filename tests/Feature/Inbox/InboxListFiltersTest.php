<?php

namespace Tests\Feature\Inbox;

use App\Modules\Inbox\Models\InboxLabel;
use App\Modules\Shared\Models\ChannelAccount;
use App\Modules\Shared\Models\Contact;
use App\Modules\Shared\Models\Conversation;
use App\Modules\Shared\Models\Message;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The inbox list: folders, search, and the counts beside them.
 *
 * The three things under test used to be three separate hand-written queries
 * (index, the sidebar on the conversation page, and the mobile API) that had
 * already drifted apart. Every assertion here that compares a count against the
 * rendered list exists because a number that disagrees with the rows under it is
 * worse than no number.
 */
class InboxListFiltersTest extends TestCase
{
    use RefreshDatabase;

    private array $ctx;

    private ChannelAccount $account;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ctx = $this->createWorkspaceContext();
        $this->account = $this->channelAccount($this->ctx['workspace']->id, 'whatsapp');
    }

    private function channelAccount(int $workspaceId, string $channel): ChannelAccount
    {
        return ChannelAccount::create([
            'workspace_id' => $workspaceId,
            'channel' => $channel,
            'provider' => 'meta',
            'status' => 'active',
            'display_name' => strtoupper($channel),
        ]);
    }

    private function conversation(array $attrs = [], ?int $workspaceId = null, ?ChannelAccount $account = null): Conversation
    {
        $workspaceId ??= $this->ctx['workspace']->id;
        $account ??= $this->account;

        return Conversation::create(array_merge([
            'workspace_id' => $workspaceId,
            'channel_account_id' => $account->id,
            'contact_id' => Contact::factory()->create(['workspace_id' => $workspaceId])->id,
            'status' => 'open',
            'last_message_at' => now(),
        ], $attrs));
    }

    /** @return array{counts: array, ids: array<int>} */
    private function load(array $query = []): array
    {
        $response = $this->actingAs($this->ctx['user'])->get(route('client.inbox.index', $query));
        $response->assertOk();
        $props = $response->viewData('page')['props'];

        return [
            'counts' => $props['counts'],
            'ids' => array_column($props['conversations']['data'], 'id'),
        ];
    }

    // ─── the pending black hole ──────────────────────────────────────────

    public function test_a_pending_conversation_is_visible_instead_of_vanishing(): void
    {
        $pending = $this->conversation(['status' => 'pending']);

        // Before, every view forced status='open', so a conversation the status
        // dropdown had set to "pending" disappeared from the whole product with
        // no screen left to reach it from.
        $this->assertContains($pending->id, $this->load()['ids']);
        $this->assertContains($pending->id, $this->load(['folder' => 'pending'])['ids']);
    }

    public function test_resolved_and_snoozed_stay_out_of_the_default_view(): void
    {
        $resolved = $this->conversation(['status' => 'resolved']);
        $snoozed = $this->conversation(['status' => 'snoozed']);
        $open = $this->conversation();

        $ids = $this->load()['ids'];

        $this->assertContains($open->id, $ids);
        $this->assertNotContains($resolved->id, $ids);
        $this->assertNotContains($snoozed->id, $ids);
        $this->assertContains($resolved->id, $this->load(['folder' => 'resolved'])['ids']);
        $this->assertContains($snoozed->id, $this->load(['folder' => 'snoozed'])['ids']);
    }

    // ─── counts agree with the rows ──────────────────────────────────────

    public function test_every_view_count_equals_the_number_of_rows_that_view_returns(): void
    {
        $user = $this->ctx['user'];

        $this->conversation(['assigned_user_id' => $user->id]);
        $this->conversation(['assigned_user_id' => $user->id, 'unread_count' => 3]);
        $this->conversation();                               // unassigned
        $this->conversation(['status' => 'pending']);
        $this->conversation(['status' => 'resolved']);
        $this->conversation(['status' => 'snoozed']);

        $counts = $this->load()['counts']['views'];

        foreach (['all' => null, 'mine' => 'mine', 'unassigned' => 'unassigned',
            'unread' => 'unread', 'pending' => 'pending',
            'resolved' => 'resolved', 'snoozed' => 'snoozed'] as $key => $folder) {
            $rows = $this->load($folder ? ['folder' => $folder] : [])['ids'];
            $this->assertSame(
                count($rows),
                $counts[$key],
                "count '{$key}' says {$counts[$key]} but the list returns ".count($rows)
            );
        }
    }

    public function test_channel_counts_sum_to_the_total_of_the_active_view(): void
    {
        $instagram = $this->channelAccount($this->ctx['workspace']->id, 'instagram');

        $this->conversation();
        $this->conversation();
        $this->conversation([], null, $instagram);

        $counts = $this->load()['counts'];

        $this->assertSame(2, $counts['channels']['whatsapp']);
        $this->assertSame(1, $counts['channels']['instagram']);
        $this->assertSame($counts['views']['all'], array_sum($counts['channels']));
    }

    public function test_channel_and_label_counts_follow_the_active_folder(): void
    {
        $label = InboxLabel::create(['workspace_id' => $this->ctx['workspace']->id, 'name' => 'VIP', 'color' => '#000']);

        $open = $this->conversation();
        $resolved = $this->conversation(['status' => 'resolved']);
        $open->labels()->attach($label->id);
        $resolved->labels()->attach($label->id);

        $this->assertSame(1, $this->load()['counts']['labels'][$label->id]);
        $this->assertSame(1, $this->load(['folder' => 'resolved'])['counts']['labels'][$label->id]);
    }

    public function test_counts_never_include_another_workspace(): void
    {
        $other = $this->createWorkspaceContext();
        $otherAccount = $this->channelAccount($other['workspace']->id, 'whatsapp');
        $this->conversation([], $other['workspace']->id, $otherAccount);
        $this->conversation([], $other['workspace']->id, $otherAccount);

        $mine = $this->conversation();

        $result = $this->load();
        $this->assertSame([$mine->id], $result['ids']);
        $this->assertSame(1, $result['counts']['views']['all']);
        $this->assertSame(1, $result['counts']['channels']['whatsapp']);
    }

    // ─── search ──────────────────────────────────────────────────────────

    public function test_search_finds_a_conversation_by_something_said_in_it(): void
    {
        $needle = $this->conversation();
        Message::create([
            'conversation_id' => $needle->id,
            'direction' => 'in',
            'channel' => 'whatsapp',
            'type' => 'text',
            'body' => 'as vrea sa schimb adresa de livrare',
            'status' => 'delivered',
            'sent_at' => now(),
        ]);

        $other = $this->conversation();
        Message::create([
            'conversation_id' => $other->id,
            'direction' => 'in',
            'channel' => 'whatsapp',
            'type' => 'text',
            'body' => 'multumesc frumos pentru ajutor',
            'status' => 'delivered',
            'sent_at' => now(),
        ]);

        $ids = $this->load(['search' => 'livrare'])['ids'];

        $this->assertContains($needle->id, $ids);
        $this->assertNotContains($other->id, $ids);
    }

    public function test_search_still_finds_a_contact_by_name_and_by_phone(): void
    {
        $contact = Contact::factory()->create([
            'workspace_id' => $this->ctx['workspace']->id,
            'first_name' => 'Maria',
            'last_name' => 'Ionescu',
            'phone_e164' => '+40741220118',
        ]);
        $conv = $this->conversation(['contact_id' => $contact->id]);
        $this->conversation();

        $this->assertContains($conv->id, $this->load(['search' => 'Ionescu'])['ids']);
        $this->assertContains($conv->id, $this->load(['search' => 'Maria Ionescu'])['ids']);
        $this->assertContains($conv->id, $this->load(['search' => '741220118'])['ids']);
    }

    public function test_search_cannot_reach_another_workspace(): void
    {
        $other = $this->createWorkspaceContext();
        $otherAccount = $this->channelAccount($other['workspace']->id, 'whatsapp');
        $theirs = $this->conversation([], $other['workspace']->id, $otherAccount);
        Message::create([
            'conversation_id' => $theirs->id,
            'direction' => 'in',
            'channel' => 'whatsapp',
            'type' => 'text',
            'body' => 'as vrea sa schimb adresa de livrare',
            'status' => 'delivered',
            'sent_at' => now(),
        ]);

        $this->assertSame([], $this->load(['search' => 'livrare'])['ids']);
    }

    public function test_a_two_letter_fragment_still_matches(): void
    {
        $contact = Contact::factory()->create([
            'workspace_id' => $this->ctx['workspace']->id,
            'first_name' => 'Ana',
            'last_name' => 'Pop',
        ]);
        $conv = $this->conversation(['contact_id' => $contact->id]);

        $this->assertContains($conv->id, $this->load(['search' => 'Po'])['ids']);
    }

    public function test_a_fragment_of_a_word_matches_a_message(): void
    {
        $conv = $this->conversation();
        Message::create([
            'conversation_id' => $conv->id,
            'direction' => 'in',
            'channel' => 'whatsapp',
            'type' => 'text',
            'body' => 'as vrea sa schimb adresa de livrare',
            'status' => 'delivered',
            'sent_at' => now(),
        ]);

        // The reason this search is a LIKE and not a fulltext index: someone types
        // the part of the word they remember, and expects to be shown the message.
        $this->assertContains($conv->id, $this->load(['search' => 'livr'])['ids']);
        $this->assertContains($conv->id, $this->load(['search' => 'adresa de liv'])['ids']);
    }

    // ─── the list is the same one, wherever it is rendered ───────────────

    public function test_the_sidebar_list_matches_the_index_list_for_the_same_filters(): void
    {
        $this->conversation(['status' => 'pending']);
        $this->conversation(['unread_count' => 2]);
        $open = $this->conversation();

        $indexIds = $this->load()['ids'];

        $response = $this->actingAs($this->ctx['user'])->get(route('client.inbox.show', $open->uuid));
        $response->assertOk();
        $sidebarIds = array_column($response->viewData('page')['props']['conversations']['data'], 'id');

        // These were two separate queries; the list used to change under the user
        // the moment they clicked a row.
        $this->assertSame($indexIds, $sidebarIds);
    }

    public function test_both_inbox_screens_are_fed_the_same_list_and_the_same_counts(): void
    {
        $this->conversation(['status' => 'pending']);
        $this->conversation(['unread_count' => 2]);
        $open = $this->conversation();

        foreach ([[], ['folder' => 'unread'], ['search' => 'a']] as $query) {
            $index = $this->load($query);

            $show = $this->actingAs($this->ctx['user'])
                ->get(route('client.inbox.show', array_merge(['conversation' => $open->uuid], $query)));
            $show->assertOk();
            $props = $show->viewData('page')['props'];

            // Both screens render the same components now; they must also be handed
            // the same data, or the rail would count one thing and list another.
            $this->assertSame($index['ids'], array_column($props['conversations']['data'], 'id'), json_encode($query));
            $this->assertSame($index['counts'], $props['counts'], json_encode($query));
        }
    }

    public function test_the_row_carries_the_assigned_agent_name_and_nothing_else_about_them(): void
    {
        $user = $this->ctx['user'];
        $this->conversation(['assigned_user_id' => $user->id]);

        $response = $this->actingAs($user)->get(route('client.inbox.index'));
        $row = $response->viewData('page')['props']['conversations']['data'][0];

        $this->assertSame($user->name, $row['assigned_user']['name']);
        $this->assertSame(['id', 'name'], array_keys($row['assigned_user']));
    }
}
