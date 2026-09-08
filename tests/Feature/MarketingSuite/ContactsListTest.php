<?php

namespace Tests\Feature\MarketingSuite;

use App\Modules\Inbox\Models\ConversationActivity;
use App\Modules\Shared\Models\ChannelAccount;
use App\Modules\Shared\Models\Contact;
use App\Modules\Shared\Models\ContactTag;
use App\Modules\Shared\Models\Conversation;
use App\Modules\Shared\Models\Segment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The rebuilt contacts list and record.
 *
 * The two new queries here — the per-row channel/last-seen aggregate and the
 * per-contact timeline — both join across tables with no global scope, so every
 * one of them is pinned against another workspace's data.
 */
class ContactsListTest extends TestCase
{
    use RefreshDatabase;

    private array $ctx;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ctx = $this->createWorkspaceContext();
    }

    private function account(int $workspaceId, string $channel = 'whatsapp'): ChannelAccount
    {
        return ChannelAccount::create([
            'workspace_id' => $workspaceId,
            'channel' => $channel,
            'provider' => 'meta',
            'status' => 'active',
            'display_name' => strtoupper($channel),
        ]);
    }

    private function talked(Contact $contact, ChannelAccount $account, ?string $at = null): Conversation
    {
        return Conversation::create([
            'workspace_id' => $contact->workspace_id,
            'channel_account_id' => $account->id,
            'contact_id' => $contact->id,
            'status' => 'open',
            'last_message_at' => $at ?? now(),
        ]);
    }

    /** @return array{props: array, ids: array<int>} */
    private function loadList(array $query = []): array
    {
        $response = $this->actingAs($this->ctx['user'])->get(route('client.contacts.index', $query));
        $response->assertOk();
        $props = $response->viewData('page')['props'];

        return ['props' => $props, 'ids' => array_column($props['contacts']['data'], 'id')];
    }

    // ─── the channels / last-seen aggregate ──────────────────────────────

    public function test_the_row_shows_the_channels_a_contact_has_actually_written_on(): void
    {
        $wid = $this->ctx['workspace']->id;
        $contact = Contact::factory()->create(['workspace_id' => $wid]);

        $this->talked($contact, $this->account($wid, 'whatsapp'));
        $this->talked($contact, $this->account($wid, 'instagram'));

        $activity = $this->loadList()['props']['activity'][$contact->id];

        sort($activity['channels']);
        $this->assertSame(['instagram', 'whatsapp'], $activity['channels']);
    }

    public function test_last_seen_is_the_newest_conversation_not_the_dead_column(): void
    {
        $wid = $this->ctx['workspace']->id;
        $contact = Contact::factory()->create([
            'workspace_id' => $wid,
            // Nothing in the application writes this column; a list built on it
            // would show a dash for every contact in a real workspace.
            'last_seen_at' => now()->subYears(5),
        ]);
        $account = $this->account($wid);

        $this->talked($contact, $account, now()->subDays(9)->toDateTimeString());
        $newest = $this->talked($contact, $account, now()->subHours(2)->toDateTimeString());

        $activity = $this->loadList()['props']['activity'][$contact->id];

        $this->assertSame(
            $newest->last_message_at->toDateTimeString(),
            (string) $activity['last_at']
        );
    }

    public function test_a_contact_who_has_never_written_has_no_activity_entry(): void
    {
        $contact = Contact::factory()->create(['workspace_id' => $this->ctx['workspace']->id]);

        $this->assertArrayNotHasKey($contact->id, $this->loadList()['props']['activity']);
    }

    public function test_the_aggregate_never_reaches_another_workspace(): void
    {
        $mine = Contact::factory()->create(['workspace_id' => $this->ctx['workspace']->id]);

        $other = $this->createWorkspaceContext();
        $theirContact = Contact::factory()->create(['workspace_id' => $other['workspace']->id]);
        $this->talked($theirContact, $this->account($other['workspace']->id, 'messenger'));

        $props = $this->loadList()['props'];

        $this->assertSame([$mine->id], array_column($props['contacts']['data'], 'id'));
        $this->assertArrayNotHasKey($theirContact->id, $props['activity']);
    }

    // ─── search ──────────────────────────────────────────────────────────

    public function test_search_covers_tags_as_the_placeholder_promises(): void
    {
        $wid = $this->ctx['workspace']->id;
        $tagged = Contact::factory()->create(['workspace_id' => $wid, 'first_name' => 'Ion']);
        $plain = Contact::factory()->create(['workspace_id' => $wid, 'first_name' => 'Vasile']);

        $tag = ContactTag::create(['workspace_id' => $wid, 'name' => 'client fidel', 'color' => '#000']);
        $tagged->tags()->attach($tag->id);

        $ids = $this->loadList(['search' => 'fidel'])['ids'];

        $this->assertContains($tagged->id, $ids);
        $this->assertNotContains($plain->id, $ids);
    }

    public function test_search_matches_a_full_name_across_both_columns(): void
    {
        $wid = $this->ctx['workspace']->id;
        $contact = Contact::factory()->create(['workspace_id' => $wid, 'first_name' => 'Maria', 'last_name' => 'Ionescu']);
        Contact::factory()->create(['workspace_id' => $wid, 'first_name' => 'Ana', 'last_name' => 'Pop']);

        $this->assertContains($contact->id, $this->loadList(['search' => 'Maria Ionescu'])['ids']);
    }

    public function test_the_segment_filter_finally_works(): void
    {
        $wid = $this->ctx['workspace']->id;
        $inside = Contact::factory()->create(['workspace_id' => $wid]);
        $outside = Contact::factory()->create(['workspace_id' => $wid]);

        $segment = Segment::create(['workspace_id' => $wid, 'name' => 'VIP', 'type' => 'static']);
        $segment->contacts()->attach($inside->id);

        // Every segment card has always linked here with ?segment=, and index()
        // never read it — the link silently returned the unfiltered list.
        $ids = $this->loadList(['segment' => $segment->id])['ids'];

        $this->assertSame([$inside->id], $ids);
        $this->assertNotContains($outside->id, $ids);
    }

    // ─── the record ──────────────────────────────────────────────────────

    public function test_the_record_loads_the_channel_of_each_conversation(): void
    {
        $wid = $this->ctx['workspace']->id;
        $contact = Contact::factory()->create(['workspace_id' => $wid]);
        $this->talked($contact, $this->account($wid, 'messenger'));

        $response = $this->actingAs($this->ctx['user'])->get(route('client.contacts.show', $contact->uuid));
        $response->assertOk();
        $props = $response->viewData('page')['props'];

        // The page has always read channel_account.channel; the controller never
        // eager-loaded it, so every conversation rendered as "unknown channel".
        $this->assertSame('messenger', $props['contact']['conversations'][0]['channel_account']['channel']);
        $this->assertSame(1, $props['summary']['conversations']);
        $this->assertNotNull($props['summary']['last_at']);
    }

    public function test_editing_a_record_can_set_tags_and_never_touches_custom_fields(): void
    {
        $wid = $this->ctx['workspace']->id;
        // These two keys are how the Instagram and Messenger drivers match an
        // incoming DM to an existing contact. A form that posted custom_fields
        // would replace the whole blob and fork every repeat sender.
        $contact = Contact::factory()->create([
            'workspace_id' => $wid,
            'custom_fields' => ['instagram_psid' => '17841400000111222', 'messenger_psid' => '9988'],
        ]);

        $this->actingAs($this->ctx['user'])
            ->put(route('client.contacts.update', $contact->uuid), [
                'first_name' => 'Elena',
                'tag_names' => ['client fidel', 'plătește la timp'],
                'custom_fields' => ['instagram_psid' => 'HACKED'],
            ])
            ->assertRedirect();

        $contact->refresh();

        $this->assertSame('Elena', $contact->first_name);
        $this->assertSame('17841400000111222', $contact->custom_fields['instagram_psid']);
        $this->assertSame(['client fidel', 'plătește la timp'], $contact->tags()->orderBy('name')->pluck('name')->all());
    }

    public function test_removing_a_tag_from_the_record_detaches_it(): void
    {
        $wid = $this->ctx['workspace']->id;
        $contact = Contact::factory()->create(['workspace_id' => $wid]);
        $tag = ContactTag::create(['workspace_id' => $wid, 'name' => 'vechi', 'color' => '#000']);
        $contact->tags()->attach($tag->id);

        $this->actingAs($this->ctx['user'])
            ->put(route('client.contacts.update', $contact->uuid), ['tag_names' => []]);

        $this->assertSame([], $contact->fresh()->tags->pluck('name')->all());
    }

    // ─── status and the business fields ──────────────────────────────────

    public function test_a_new_contact_starts_as_a_lead(): void
    {
        $this->actingAs($this->ctx['user'])
            ->post(route('client.contacts.store'), ['first_name' => 'Ana', 'phone_e164' => '+40722000111'])
            ->assertRedirect();

        $this->assertSame('lead', Contact::where('first_name', 'Ana')->value('status'));
    }

    public function test_the_status_chips_count_the_whole_workspace_and_the_filter_matches_them(): void
    {
        $wid = $this->ctx['workspace']->id;
        Contact::factory()->count(2)->create(['workspace_id' => $wid, 'status' => 'client']);
        Contact::factory()->create(['workspace_id' => $wid, 'status' => 'negotiating']);
        Contact::factory()->create(['workspace_id' => $wid, 'status' => 'lead']);

        $counts = $this->loadList()['props']['statusCounts'];

        $this->assertSame(['all' => 4, 'lead' => 1, 'negotiating' => 1, 'client' => 2, 'inactive' => 0], $counts);

        // Every chip must return exactly as many rows as it advertises.
        foreach (['lead', 'negotiating', 'client', 'inactive'] as $status) {
            $this->assertCount($counts[$status], $this->loadList(['status' => $status])['ids'], $status);
        }
    }

    public function test_the_chip_counts_ignore_another_workspace(): void
    {
        Contact::factory()->create(['workspace_id' => $this->ctx['workspace']->id, 'status' => 'client']);

        $other = $this->createWorkspaceContext();
        Contact::factory()->count(3)->create(['workspace_id' => $other['workspace']->id, 'status' => 'client']);

        $this->assertSame(1, $this->loadList()['props']['statusCounts']['client']);
    }

    public function test_the_business_fields_save_and_come_back(): void
    {
        $contact = Contact::factory()->create(['workspace_id' => $this->ctx['workspace']->id]);

        $this->actingAs($this->ctx['user'])
            ->put(route('client.contacts.update', $contact->uuid), [
                'company' => 'Cofetăria Ana',
                'job_title' => 'Proprietar',
                'tax_id' => 'RO14882301',
                'address' => 'Str. Memorandumului 12',
                'city' => 'Cluj-Napoca',
                'birthday' => '1986-03-14',
                'status' => 'client',
            ])
            ->assertRedirect();

        $contact->refresh();

        $this->assertSame('Cofetăria Ana', $contact->company);
        $this->assertSame('RO14882301', $contact->tax_id);
        $this->assertSame('1986-03-14', $contact->birthday->toDateString());
        $this->assertSame('client', $contact->status);
    }

    public function test_an_unknown_status_is_rejected(): void
    {
        $contact = Contact::factory()->create(['workspace_id' => $this->ctx['workspace']->id, 'status' => 'lead']);

        $this->actingAs($this->ctx['user'])
            ->put(route('client.contacts.update', $contact->uuid), ['status' => 'vip'])
            ->assertSessionHasErrors('status');

        $this->assertSame('lead', $contact->fresh()->status);
    }

    public function test_search_covers_the_company_as_the_placeholder_promises(): void
    {
        $wid = $this->ctx['workspace']->id;
        $atCompany = Contact::factory()->create(['workspace_id' => $wid, 'company' => 'Bike Shop Cluj']);
        $elsewhere = Contact::factory()->create(['workspace_id' => $wid, 'company' => 'Clinica Nord']);

        $ids = $this->loadList(['search' => 'Bike'])['ids'];

        $this->assertContains($atCompany->id, $ids);
        $this->assertNotContains($elsewhere->id, $ids);
    }

    public function test_the_timeline_carries_what_the_sentence_needs_to_be_readable(): void
    {
        $wid = $this->ctx['workspace']->id;
        $contact = Contact::factory()->create(['workspace_id' => $wid]);
        $conversation = $this->talked($contact, $this->account($wid, 'messenger'));

        ConversationActivity::create([
            'workspace_id' => $wid,
            'conversation_id' => $conversation->id,
            'user_id' => $this->ctx['user']->id,
            'type' => 'label_added',
            'meta' => ['label' => 'client fidel'],
        ]);

        $response = $this->actingAs($this->ctx['user'])->get(route('client.contacts.show', $contact->uuid));
        $entry = collect($response->viewData('page')['props']['activity'])
            ->firstWhere('type', 'label_added');

        // The page renders "{actor} added the label {label}". Without these two the
        // sentence printed its own placeholders back at the reader.
        $this->assertSame($this->ctx['user']->name, $entry['actor']);
        $this->assertSame('client fidel', $entry['meta']['label']);
        $this->assertSame('messenger', $entry['channel']);
    }

    public function test_a_contact_from_another_workspace_is_not_reachable(): void
    {
        $other = $this->createWorkspaceContext();
        $theirs = Contact::factory()->create(['workspace_id' => $other['workspace']->id]);

        $this->actingAs($this->ctx['user'])
            ->get(route('client.contacts.show', $theirs->uuid))
            ->assertForbidden();
    }

    public function test_a_segment_cannot_be_filled_with_another_workspace_contacts(): void
    {
        $wid = $this->ctx['workspace']->id;
        $segment = Segment::create(['workspace_id' => $wid, 'name' => 'VIP', 'type' => 'static']);

        $other = $this->createWorkspaceContext();
        $theirs = Contact::factory()->create(['workspace_id' => $other['workspace']->id]);

        // exists:contacts,id alone accepted any id in the install.
        $this->actingAs($this->ctx['user'])
            ->post(route('client.segments.contacts.attach', $segment->id), ['contact_ids' => [$theirs->id]])
            ->assertSessionHasErrors('contact_ids.0');

        $this->assertSame(0, $segment->fresh()->contacts()->count());
    }
}
