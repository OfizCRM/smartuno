<?php

namespace Tests\Feature\Catalog;

use App\Models\Client;
use App\Models\User;
use App\Models\Workspace;
use App\Modules\Catalog\Models\CatalogItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * "Ce trebuie să știe agentul" — the panel on a catalogue item where the firm
 * writes down what a salesperson would have said out loud: a description for
 * the customer, the green "Pentru cine este" words, the red "Nu îl propune
 * dacă" words, what it goes well with, and the lowest price it may leave at.
 *
 * Two tables carry it — catalog_item_tags and catalog_item_links — and both
 * carry their own workspace_id, because tenancy in this application is manual
 * and a query that has to join to find the workspace is a query somebody will
 * eventually write without the join.
 *
 * THE REQUEST CONTRACT THESE TESTS PIN. The panel saves in one PUT:
 *
 *   description  string|null    what the customer is told
 *   min_price    numeric|null   lei, as `price` is on store/update — not bani
 *   fits         string[]       replaces every 'fits' tag on this item
 *   excludes     string[]       replaces every 'excludes' tag
 *   links        [{related_item_id: int}]   "merge bine împreună cu"; replaces
 *                               every cross_sell link on this item
 *   components   [{related_item_id: int, quantity: numeric}]   bundles only
 *
 * The two failures worth more than the rest: a related_item_id belonging to
 * another firm has to be REFUSED rather than dropped — a bundle is the one
 * place in this feature where another firm's product id arrives from a browser
 * — and the tags have to be replaced wholesale without taking anybody else's
 * with them.
 */
class CatalogKnowledgeTest extends TestCase
{
    use RefreshDatabase;

    /** @var array{user: User, workspace: Workspace, client: Client} */
    private array $ctx;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ctx = $this->createWorkspaceContext();
    }

    private function workspaceId(): int
    {
        return (int) $this->ctx['workspace']->id;
    }

    /** @param array<string, mixed> $attrs */
    private function item(array $attrs = [], ?int $workspaceId = null): CatalogItem
    {
        static $n = 0;
        $n++;

        return CatalogItem::create(array_merge([
            'workspace_id' => $workspaceId ?? $this->workspaceId(),
            'type' => 'product',
            'name' => 'Centrală termică Bosch 24kW',
            'code' => 'CT-'.$n,
            'category' => 'Centrale',
            'unit' => 'buc',
            'price_cents' => 450000,
            'is_active' => true,
        ], $attrs));
    }

    /**
     * Save the panel.
     *
     * @param  array<string, mixed>  $payload
     */
    private function save(CatalogItem $item, array $payload, ?User $as = null): TestResponse
    {
        return $this->actingAs($as ?? $this->ctx['user'])
            ->from(route('client.catalog.show', $item->uuid))
            ->put(route('client.catalog.knowledge', $item->uuid), $payload);
    }

    /** @return array<string, mixed> */
    private function showProps(CatalogItem $item): array
    {
        return $this->actingAs($this->ctx['user'])
            ->get(route('client.catalog.show', $item->uuid))
            ->viewData('page')['props'];
    }

    /** @return array<string, mixed> */
    private function indexProps(): array
    {
        return $this->actingAs($this->ctx['user'])
            ->get(route('client.catalog.index'))
            ->viewData('page')['props'];
    }

    /** @return array<int, array<string, mixed>> */
    private function tagRows(CatalogItem $item, ?string $kind = null): array
    {
        $query = DB::table('catalog_item_tags')->where('catalog_item_id', $item->id);

        if ($kind !== null) {
            $query->where('kind', $kind);
        }

        return array_map(fn ($row): array => (array) $row, $query->orderBy('id')->get()->all());
    }

    /** @return array<int, array<string, mixed>> */
    private function linkRows(CatalogItem $item, ?string $kind = null): array
    {
        $query = DB::table('catalog_item_links')->where('catalog_item_id', $item->id);

        if ($kind !== null) {
            $query->where('kind', $kind);
        }

        return array_map(fn ($row): array => (array) $row, $query->orderBy('position')->orderBy('id')->get()->all());
    }

    // ─── what the panel saves ────────────────────────────────────────────

    public function test_the_panel_saves_the_description_the_two_tag_kinds_and_the_floor_price(): void
    {
        $item = $this->item(['price_cents' => 450000]);

        $this->save($item, [
            'description' => 'Centrală în condensație pentru apartament, cu boiler inclus.',
            'min_price' => '4200',
            'fits' => ['apartament la bloc', 'familie cu copii'],
            'excludes' => ['casă fără racord la gaz'],
        ])->assertSessionHasNoErrors();

        $item->refresh();

        $this->assertSame('Centrală în condensație pentru apartament, cu boiler inclus.', $item->description);
        // A person wrote it, and the column has to say so — the AI helper writes
        // 'ai' into the same field, and a later stage tells the firm which of its
        // knowledge it wrote itself.
        $this->assertSame('human', $item->description_source);
        // Lei in, bani out, through the one converter: 4200 lei is 420 000 bani,
        // and never 4200.
        $this->assertSame(420000, $item->min_price_cents);

        $fits = $this->tagRows($item, 'fits');
        $excludes = $this->tagRows($item, 'excludes');

        $this->assertSame(['apartament la bloc', 'familie cu copii'], array_column($fits, 'label'));
        $this->assertSame(['casă fără racord la gaz'], array_column($excludes, 'label'));

        foreach (array_merge($fits, $excludes) as $row) {
            // The tenancy column is carried, not left to a join that nobody
            // wrote. A tag with a null workspace_id is a row no scoped query
            // will ever find again.
            $this->assertSame($this->workspaceId(), (int) $row['workspace_id']);
            $this->assertSame('human', $row['source']);
        }
    }

    public function test_the_same_label_may_carry_both_signs(): void
    {
        $item = $this->item();

        // Nonsense to write, but the firm's nonsense to make: the unique key is
        // per kind, so the panel must not fall over on it.
        $this->save($item, [
            'fits' => ['apartament la bloc'],
            'excludes' => ['apartament la bloc'],
        ])->assertSessionHasNoErrors();

        $this->assertSame(['apartament la bloc'], array_column($this->tagRows($item, 'fits'), 'label'));
        $this->assertSame(['apartament la bloc'], array_column($this->tagRows($item, 'excludes'), 'label'));
    }

    public function test_an_empty_description_box_stores_nothing_rather_than_an_empty_string(): void
    {
        $item = $this->item();

        $this->save($item, ['description' => 'ceva'])->assertSessionHasNoErrors();
        $this->save($item, ['description' => '   '])->assertSessionHasNoErrors();

        // Null and not '': the nudge banner counts items with no description,
        // and an empty string that reads as "written" hides the item from the
        // one screen that was going to get it filled in.
        $this->assertNull($item->fresh()->description);
    }

    public function test_the_floor_price_can_be_taken_off_again(): void
    {
        $item = $this->item(['price_cents' => 450000]);

        $this->save($item, ['min_price' => '4200'])->assertSessionHasNoErrors();
        $this->assertSame(420000, $item->fresh()->min_price_cents);

        $this->save($item, ['min_price' => ''])->assertSessionHasNoErrors();

        // Null is "no floor". Zero would be a floor of zero lei, which is a
        // different promise — and the one the seller would find out about when
        // the discount box let a line through at nothing.
        $this->assertNull($item->fresh()->min_price_cents);
    }

    // ─── replacing, without touching anybody else's rows ─────────────────

    public function test_saving_the_panel_replaces_the_tags_wholesale(): void
    {
        $item = $this->item();

        $this->save($item, ['fits' => ['apartament la bloc', 'familie cu copii']])->assertSessionHasNoErrors();
        $this->save($item, ['fits' => ['familie cu copii', 'casă nouă']])->assertSessionHasNoErrors();

        // The panel holds the list and sends the list it holds: a chip removed
        // on the screen is a chip absent from the payload, not one anybody has
        // to identify.
        $this->assertSame(['familie cu copii', 'casă nouă'], array_column($this->tagRows($item, 'fits'), 'label'));
        $this->assertCount(2, $this->tagRows($item));
    }

    public function test_saving_one_item_leaves_every_other_items_tags_alone(): void
    {
        $mine = $this->item(['name' => 'Centrala mea']);
        $sibling = $this->item(['name' => 'Boiler', 'code' => 'BO-9']);

        $intruder = $this->createWorkspaceContext();
        $theirs = $this->item(['name' => 'Centrala lor'], (int) $intruder['workspace']->id);

        $this->save($sibling, ['fits' => ['casă cu etaj']])->assertSessionHasNoErrors();
        $this->actingAs($intruder['user'])
            ->put(route('client.catalog.knowledge', $theirs->uuid), ['fits' => ['secretul lor']])
            ->assertSessionHasNoErrors();

        $this->save($mine, ['fits' => ['apartament la bloc']])->assertSessionHasNoErrors();
        $this->save($mine, ['fits' => []])->assertSessionHasNoErrors();

        // The delete that clears this item's tags carries the item id and the
        // workspace. Without the first it empties the catalogue; without the
        // second it empties another firm's.
        $this->assertSame([], $this->tagRows($mine));
        $this->assertSame(['casă cu etaj'], array_column($this->tagRows($sibling), 'label'));
        $this->assertSame(['secretul lor'], array_column($this->tagRows($theirs), 'label'));
    }

    public function test_the_same_word_typed_twice_is_refused_rather_than_stored_twice(): void
    {
        $item = $this->item();

        // (catalog_item_id, kind, label) is unique, so a second row is not a chip
        // the person sees twice — it is an integrity error and a 500 on a form.
        // Caught before the insert, and answered per chip: the person is told
        // which one to change rather than having one of them quietly dropped.
        $this->save($item, ['fits' => ['fără boiler', 'Fără Boiler']])
            ->assertSessionHasErrors('fits.1');

        $this->assertSame([], $this->tagRows($item));

        $this->save($item, ['fits' => ['fără boiler']])->assertSessionHasNoErrors();
        $this->assertCount(1, $this->tagRows($item, 'fits'));
    }

    /**
     * catalog_item_tags is utf8mb4_unicode_ci, which is case- AND
     * ACCENT-insensitive: to the unique key "fara boiler" and "fără boiler" are
     * one value. PHP's mb_strtolower is not — it folds case and leaves the
     * diacritics alone — so a duplicate check on a lowercased label alone lets
     * both through, and the insert then dies on the index.
     *
     * Typing a Romanian word once with its diacritics and once without is not an
     * exotic input; it is what a keyboard without them produces, and half the
     * firms this is sold to type that way.
     */
    public function test_a_word_typed_with_and_without_diacritics_is_the_same_word(): void
    {
        $item = $this->item();

        $response = $this->save($item, ['fits' => ['fără boiler', 'fara boiler']]);

        $this->assertNotSame(
            500,
            $response->status(),
            'two labels that differ only by diacritics collide on the unique index',
        );
        $response->assertSessionHasErrors('fits.1');

        $this->assertSame([], $this->tagRows($item));
    }

    public function test_a_label_longer_than_the_column_is_refused_rather_than_cut_in_half(): void
    {
        $item = $this->item();

        $this->save($item, ['fits' => [str_repeat('a', 65)]])
            ->assertSessionHasErrors();

        $this->assertSame([], $this->tagRows($item));
    }

    // ─── "merge bine împreună cu" ────────────────────────────────────────

    public function test_the_cross_sell_chips_are_stored_as_links_this_workspace_owns(): void
    {
        $item = $this->item(['name' => 'Centrală termică']);
        $pump = $this->item(['name' => 'Pompă de recirculare', 'code' => 'PR-1']);
        $kit = $this->item(['name' => 'Kit de montaj', 'code' => 'KM-1']);

        $this->save($item, ['links' => [['related_item_id' => $pump->id], ['related_item_id' => $kit->id]]])->assertSessionHasNoErrors();

        $links = $this->linkRows($item, 'cross_sell');

        $this->assertSame([$pump->id, $kit->id], array_map('intval', array_column($links, 'related_item_id')));

        foreach ($links as $row) {
            $this->assertSame($this->workspaceId(), (int) $row['workspace_id']);
        }

        // The chips are drawn in the order the person arranged them, so the
        // order has to be a column and not "whatever the insert did".
        $this->assertSame([0, 1], array_map('intval', array_column($links, 'position')));
    }

    public function test_a_chip_pointing_at_another_firms_item_is_refused_and_nothing_is_written(): void
    {
        $item = $this->item(['name' => 'Centrala mea']);
        $mine = $this->item(['name' => 'Pompă', 'code' => 'PR-2']);

        $intruder = $this->createWorkspaceContext();
        $theirs = $this->item(['name' => 'Produsul lor'], (int) $intruder['workspace']->id);

        $this->save($item, ['links' => [['related_item_id' => $mine->id], ['related_item_id' => $theirs->id]]])
            ->assertSessionHasErrors();

        // Refused, not quietly reduced to the ids we recognised. A silently
        // dropped chip is a firm believing it saved something it did not — and
        // the same code path, one release later, is how the other firm's id
        // gets stored instead.
        $this->assertSame([], $this->linkRows($item));
    }

    public function test_a_chip_pointing_at_an_item_that_does_not_exist_is_refused(): void
    {
        $item = $this->item();

        $this->save($item, ['links' => [['related_item_id' => 909090]]])->assertSessionHasErrors();

        $this->assertSame([], $this->linkRows($item));
    }

    public function test_an_item_cannot_be_suggested_alongside_itself(): void
    {
        $item = $this->item();

        $this->save($item, ['links' => [['related_item_id' => $item->id]]])->assertSessionHasErrors();

        $this->assertSame([], $this->linkRows($item));
    }

    public function test_the_chips_are_replaced_wholesale_like_the_tags(): void
    {
        $item = $this->item();
        $pump = $this->item(['name' => 'Pompă', 'code' => 'PR-3']);
        $kit = $this->item(['name' => 'Kit', 'code' => 'KM-3']);

        $this->save($item, ['links' => [['related_item_id' => $pump->id], ['related_item_id' => $kit->id]]])->assertSessionHasNoErrors();
        $this->save($item, ['links' => [['related_item_id' => $kit->id]]])->assertSessionHasNoErrors();

        $this->assertSame([$kit->id], array_map('intval', array_column($this->linkRows($item, 'cross_sell'), 'related_item_id')));
    }

    // ─── the floor price ─────────────────────────────────────────────────

    public function test_a_floor_above_the_list_price_is_refused(): void
    {
        $item = $this->item(['price_cents' => 450000]);

        $this->save($item, ['min_price' => '5000'])->assertSessionHasErrors();

        // A minimum nobody can sell above is not a bound, it is a typo — and the
        // seller finds out when the offer editor refuses every line.
        $this->assertNull($item->fresh()->min_price_cents);
    }

    public function test_a_floor_equal_to_the_list_price_is_allowed(): void
    {
        $item = $this->item(['price_cents' => 450000]);

        $this->save($item, ['min_price' => '4500'])->assertSessionHasNoErrors();

        // "Never discount this" is a real instruction, and it is exactly the
        // list price.
        $this->assertSame(450000, $item->fresh()->min_price_cents);
    }

    // ─── what the item page reads ────────────────────────────────────────

    public function test_the_item_page_carries_the_panel_the_screen_draws(): void
    {
        $item = $this->item(['name' => 'Centrală termică']);
        $pump = $this->item(['name' => 'Pompă de recirculare', 'code' => 'PR-4']);

        $this->save($item, [
            'description' => 'Pentru un apartament de două camere.',
            'min_price' => '4200',
            'fits' => ['apartament la bloc'],
            'excludes' => ['casă fără gaz'],
            'links' => [['related_item_id' => $pump->id]],
        ])->assertSessionHasNoErrors();

        $props = $this->showProps($item);

        $this->assertSame('Pentru un apartament de două camere.', $props['item']['description']);
        $this->assertSame('human', $props['item']['description_source']);
        $this->assertSame(420000, (int) $props['item']['min_price_cents']);

        $tags = $props['item']['tags'];
        $this->assertCount(2, $tags);

        foreach ($tags as $tag) {
            // Pinned key by key: the panel colours a chip from `kind` and shows a
            // small mark on the ones the agent drafted, so a renamed key is a
            // green chip where a red one belonged.
            $keys = array_keys($tag);
            sort($keys);
            $this->assertSame(['id', 'kind', 'label', 'source'], $keys);
        }

        $byKind = [];
        foreach ($tags as $tag) {
            $byKind[$tag['kind']][] = $tag['label'];
        }
        $this->assertSame(['apartament la bloc'], $byKind['fits']);
        $this->assertSame(['casă fără gaz'], $byKind['excludes']);

        $chips = array_values(array_filter($props['item']['links'], fn ($l): bool => $l['kind'] === 'cross_sell'));
        $this->assertCount(1, $chips);

        $keys = array_keys($chips[0]);
        sort($keys);
        $this->assertSame(
            ['id', 'kind', 'quantity', 'related_code', 'related_item_id', 'related_name'],
            $keys
        );

        // The chip is drawn from the prop and not fetched again, so the other
        // item's name and code have to travel with the link.
        $this->assertSame($pump->id, (int) $chips[0]['related_item_id']);
        $this->assertSame('Pompă de recirculare', $chips[0]['related_name']);
        $this->assertSame('PR-4', $chips[0]['related_code']);
    }

    public function test_an_item_with_no_knowledge_yet_still_renders(): void
    {
        $item = $this->item();

        $props = $this->showProps($item);

        // Every key the panel reads exists on a fresh item; the screen draws an
        // empty panel, not a blank page.
        $this->assertNull($props['item']['description']);
        $this->assertNull($props['item']['description_source']);
        $this->assertNull($props['item']['min_price_cents']);
        $this->assertSame([], $props['item']['tags']);
        $this->assertSame([], $props['item']['links']);
    }

    // ─── the nudge banner's number ───────────────────────────────────────

    public function test_needs_help_counts_the_active_items_with_neither_a_description_nor_a_tag(): void
    {
        $bare = $this->item(['name' => 'Fără nimic', 'code' => 'N-1']);
        $described = $this->item(['name' => 'Cu descriere', 'code' => 'N-2']);
        $tagged = $this->item(['name' => 'Cu etichete', 'code' => 'N-3']);
        $this->item(['name' => 'Retras', 'code' => 'N-4', 'is_active' => false]);
        $deleted = $this->item(['name' => 'Șters', 'code' => 'N-5']);

        $this->save($described, ['description' => 'Are o descriere.'])->assertSessionHasNoErrors();
        $this->save($tagged, ['fits' => ['apartament la bloc']])->assertSessionHasNoErrors();
        $deleted->delete();

        // Only $bare. An item the firm has withdrawn is not work waiting to be
        // done, and neither is one it deleted.
        $this->assertSame(1, $this->indexProps()['stats']['needs_help']);

        $this->save($bare, ['description' => 'Acum are.'])->assertSessionHasNoErrors();
        $this->assertSame(0, $this->indexProps()['stats']['needs_help']);

        // A red tag is knowledge too: an item nobody described but somebody
        // ruled out for a whole class of customer is not blank.
        $this->save($bare, ['description' => '', 'excludes' => ['casă fără gaz']])->assertSessionHasNoErrors();
        $this->assertSame(0, $this->indexProps()['stats']['needs_help']);

        $this->save($bare, ['description' => '', 'excludes' => []])->assertSessionHasNoErrors();
        $this->assertSame(1, $this->indexProps()['stats']['needs_help']);
    }

    public function test_needs_help_never_counts_another_firms_items(): void
    {
        $this->item(['name' => 'Al meu', 'code' => 'M-1']);

        $intruder = $this->createWorkspaceContext();
        for ($i = 1; $i <= 4; $i++) {
            $this->item(['name' => 'Al lor '.$i, 'code' => 'X-'.$i], (int) $intruder['workspace']->id);
        }

        // A tile that counts four items the firm cannot see has leaked the same
        // fact a row would have.
        $this->assertSame(1, $this->indexProps()['stats']['needs_help']);
    }

    // ─── who may write it ────────────────────────────────────────────────

    public function test_another_firms_item_cannot_have_its_knowledge_written(): void
    {
        $intruder = $this->createWorkspaceContext();
        $theirs = $this->item(['name' => 'Secretul lor', 'code' => 'B-1'], (int) $intruder['workspace']->id);

        $this->actingAs($this->ctx['user'])
            ->put(route('client.catalog.knowledge', $theirs->uuid), [
                'description' => 'Furat',
                'fits' => ['orice'],
            ])
            ->assertForbidden();

        $theirs->refresh();
        $this->assertNull($theirs->description);
        $this->assertSame([], $this->tagRows($theirs));
    }

    public function test_a_visitor_who_is_not_signed_in_cannot_write_knowledge(): void
    {
        $item = $this->item();

        $this->put(route('client.catalog.knowledge', $item->uuid), ['description' => 'Furat'])
            ->assertRedirect();

        $this->assertNull($item->fresh()->description);
    }
    // ─── the payloads the browser actually sends ─────────────────────────
    //
    // Every test above posts what the controller validates. That is how five
    // separate contracts drifted in one stage and the suite stayed green: the
    // pages spoke a different language and nothing compared the two. These post
    // the exact shape resources/js/Pages/Catalog/Show.jsx builds.

    public function test_the_knowledge_panel_payload_from_the_screen_saves_everything(): void
    {
        $item = $this->item(['name' => 'Cremă']);
        $other = $this->item(['name' => 'Serum', 'code' => 'SR-1']);

        $this->actingAs($this->ctx['user'])->put(route('client.catalog.knowledge', $item->uuid), [
            'description' => 'Cremă bogată pentru ten uscat.',
            'min_price' => '199,50',
            'fits' => ['ten uscat', 'iarnă'],
            'excludes' => ['ten gras'],
            // The screen sends links as objects, not bare ids. Under any other
            // name validate() drops them and the save reports success anyway.
            'links' => [['related_item_id' => $other->id]],
        ])->assertRedirect()->assertSessionHasNoErrors();

        $item->refresh();
        $this->assertSame('Cremă bogată pentru ten uscat.', $item->description);
        $this->assertSame(19950, (int) $item->min_price_cents);
        $this->assertSame(2, $item->tags()->where('kind', 'fits')->count());
        $this->assertSame(1, $item->tags()->where('kind', 'excludes')->count());
        $this->assertSame(1, $item->crossSells()->count());
    }

    public function test_removing_a_cross_sell_chip_actually_removes_it(): void
    {
        $item = $this->item(['name' => 'Cremă']);
        $other = $this->item(['name' => 'Serum', 'code' => 'SR-1']);

        $this->actingAs($this->ctx['user'])->put(route('client.catalog.knowledge', $item->uuid), [
            'description' => 'ceva', 'fits' => [], 'excludes' => [],
            'links' => [['related_item_id' => $other->id]],
        ])->assertRedirect();

        $this->assertSame(1, $item->crossSells()->count());

        // The chip deleted on screen means an empty list, and an empty list
        // means gone. It used to survive, and nothing told the person.
        $this->actingAs($this->ctx['user'])->put(route('client.catalog.knowledge', $item->uuid), [
            'description' => 'ceva', 'fits' => [], 'excludes' => [], 'links' => [],
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame(0, $item->crossSells()->count());
    }

    public function test_a_bundle_is_composed_with_the_payload_the_screen_sends(): void
    {
        $bundle = $this->item(['name' => 'Pachet ten uscat', 'code' => 'PK-1', 'type' => 'bundle']);
        $part = $this->item(['name' => 'Cremă', 'code' => 'CR-9']);

        $this->actingAs($this->ctx['user'])->put(route('client.catalog.knowledge', $bundle->uuid), [
            'description' => '', 'fits' => [], 'excludes' => [], 'links' => [],
            'components' => [['related_item_id' => $part->id, 'quantity' => '2']],
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame(1, $bundle->components()->count());
        $this->assertSame($part->id, (int) $bundle->components()->first()->related_item_id);
    }

    public function test_a_bundle_can_be_created_from_the_ordinary_new_item_form(): void
    {
        $this->actingAs($this->ctx['user'])->post(route('client.catalog.store'), [
            'name' => 'Set cadou premium',
            'type' => 'bundle',
            'unit' => 'set',
        ])->assertRedirect()->assertSessionHasNoErrors();

        // Stage 3 built the whole consumer surface for bundles — the tab, the
        // composition panel, the offer button — while nothing in the product
        // could produce one.
        $this->assertSame(1, CatalogItem::where('workspace_id', $this->ctx['workspace']->id)
            ->where('type', 'bundle')->count());
    }

    public function test_the_components_endpoint_answers_the_route_key_the_picker_carries(): void
    {
        $bundle = $this->item(['name' => 'Pachet', 'code' => 'PK-2', 'type' => 'bundle']);
        $part = $this->item(['name' => 'Cremă', 'code' => 'CR-8']);
        $this->actingAs($this->ctx['user'])->put(route('client.catalog.knowledge', $bundle->uuid), [
            'description' => '', 'fits' => [], 'excludes' => [], 'links' => [],
            'components' => [['related_item_id' => $part->id, 'quantity' => '1']],
        ]);

        // The offer editor reaches this endpoint with the uuid off a picker row,
        // so the picker row has to carry one. It did not, and every bundle
        // reported itself as empty.
        $search = $this->actingAs($this->ctx['user'])
            ->getJson(route('client.catalog.search', ['q' => 'Pachet']))->json();

        $this->assertArrayHasKey('uuid', $search[0]);

        $this->actingAs($this->ctx['user'])
            ->getJson(route('client.catalog.components', $search[0]['uuid']))
            ->assertOk()
            ->assertJsonCount(1);
    }
}
