<?php

namespace Tests\Feature\Catalog;

use App\Models\Client;
use App\Models\User;
use App\Models\Workspace;
use App\Modules\Catalog\Models\CatalogItem;
use App\Modules\Offers\Models\Offer;
use App\Modules\Offers\Models\OfferItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Ansambluri — "instalare centrală la cheie": one catalogue row a firm sells as
 * a single thing, made of several it already has.
 *
 * A bundle is not a fourth kind of record. It is a catalog_items row with
 * type = 'bundle' whose components are its bundle_component links, so the item
 * page, the search and the list already work for it.
 *
 * What it must NOT be is an offer line. The customer is quoted the parts, one
 * row each with its own price and quantity, because that is what they will
 * argue about and what the firm will have to deliver. A bundle line would print
 * a single number with nothing behind it, and every later stage — a discount, a
 * missing component, a stock check — would have nothing to work with.
 *
 * The request contract for composing one is the knowledge panel's (see
 * CatalogKnowledgeTest):
 *
 *   components  [{related_item_id: int, quantity: numeric}]
 */
class CatalogBundleTest extends TestCase
{
    use RefreshDatabase;

    /** @var array{user: User, workspace: Workspace, client: Client} */
    private array $ctx;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
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
            'name' => 'Piesă '.$n,
            'code' => 'P-'.$n,
            'unit' => 'buc',
            'price_cents' => 10000,
            'is_active' => true,
        ], $attrs));
    }

    /** @param array<string, mixed> $attrs */
    private function bundle(array $attrs = [], ?int $workspaceId = null): CatalogItem
    {
        return $this->item(array_merge([
            'type' => 'bundle',
            'name' => 'Instalare centrală la cheie',
            'code' => 'ANS-1',
            'unit' => 'set',
            'price_cents' => 500000,
        ], $attrs), $workspaceId);
    }

    /**
     * Compose a bundle through the panel that composes it.
     *
     * @param  array<int, array{related_item_id: int, quantity: float|int|string}>  $components
     */
    private function compose(CatalogItem $bundle, array $components, ?User $as = null): TestResponse
    {
        return $this->actingAs($as ?? $this->ctx['user'])
            ->from(route('client.catalog.show', $bundle->uuid))
            ->put(route('client.catalog.knowledge', $bundle->uuid), ['components' => $components]);
    }

    /** @return array<int, array<string, mixed>> */
    private function components(CatalogItem $bundle): array
    {
        $response = $this->actingAs($this->ctx['user'])
            ->getJson(route('client.catalog.components', $bundle->uuid));

        $response->assertOk();

        return $response->json();
    }

    private function draft(): Offer
    {
        $this->actingAs($this->ctx['user'])->post(route('client.offers.store'), [])->assertRedirect();

        return Offer::latest('id')->firstOrFail();
    }

    // ─── composing one ───────────────────────────────────────────────────

    public function test_a_bundle_is_a_catalogue_row_whose_components_are_links(): void
    {
        $bundle = $this->bundle();
        $boiler = $this->item(['name' => 'Centrală termică', 'price_cents' => 450000]);
        $labour = $this->item(['name' => 'Manoperă montaj', 'type' => 'service', 'unit' => 'oră', 'price_cents' => 8000]);

        $this->compose($bundle, [
            ['related_item_id' => $boiler->id, 'quantity' => 1],
            ['related_item_id' => $labour->id, 'quantity' => 4.5],
        ])->assertSessionHasNoErrors();

        $rows = DB::table('catalog_item_links')
            ->where('catalog_item_id', $bundle->id)
            ->orderBy('position')
            ->get();

        $this->assertCount(2, $rows);

        foreach ($rows as $row) {
            $this->assertSame('bundle_component', $row->kind);
            // The tenancy column is carried on the link itself, not inferred
            // from either end.
            $this->assertSame($this->workspaceId(), (int) $row->workspace_id);
        }

        $this->assertSame($boiler->id, (int) $rows[0]->related_item_id);
        $this->assertSame($labour->id, (int) $rows[1]->related_item_id);

        // decimal(12,3), not a float: 4,5 ore of labour multiplies into a total
        // the customer can see, and a quantity that drifts through binary is a
        // number they can prove is wrong.
        $this->assertSame(4.5, (float) $rows[1]->quantity);
        $this->assertSame([0, 1], array_map('intval', array_column($rows->all(), 'position')));
    }

    public function test_the_same_component_twice_is_refused_rather_than_stored_twice(): void
    {
        $bundle = $this->bundle();
        $part = $this->item();

        // (catalog_item_id, related_item_id, kind) is unique: a second row is not
        // a line the person sees twice, it is an integrity error and a 500 on a
        // form. Answered per line, like every other refusal in this panel.
        $this->compose($bundle, [
            ['related_item_id' => $part->id, 'quantity' => 1],
            ['related_item_id' => $part->id, 'quantity' => 2],
        ])->assertSessionHasErrors('components.1.related_item_id');

        $this->assertSame(0, DB::table('catalog_item_links')->where('catalog_item_id', $bundle->id)->count());

        $this->compose($bundle, [['related_item_id' => $part->id, 'quantity' => 3]])
            ->assertSessionHasNoErrors();

        $rows = DB::table('catalog_item_links')->where('catalog_item_id', $bundle->id)->get();

        $this->assertCount(1, $rows);
        $this->assertSame(3.0, (float) $rows[0]->quantity);
    }

    public function test_a_component_from_another_firm_is_refused_and_nothing_is_written(): void
    {
        $bundle = $this->bundle();
        $mine = $this->item(['name' => 'Piesa mea']);

        $intruder = $this->createWorkspaceContext();
        $theirs = $this->item(['name' => 'Piesa lor'], (int) $intruder['workspace']->id);

        $this->compose($bundle, [
            ['related_item_id' => $mine->id, 'quantity' => 1],
            ['related_item_id' => $theirs->id, 'quantity' => 1],
        ])->assertSessionHasErrors();

        // A bundle is the natural place in this feature for another firm's
        // product id to arrive from a browser, so it is refused outright rather
        // than reduced to the ids we recognised.
        $this->assertSame(0, DB::table('catalog_item_links')->where('catalog_item_id', $bundle->id)->count());
        $this->assertSame(0, DB::table('catalog_item_links')->where('workspace_id', $this->workspaceId())->count());
    }

    public function test_a_bundle_cannot_contain_itself(): void
    {
        $bundle = $this->bundle();

        $this->compose($bundle, [['related_item_id' => $bundle->id, 'quantity' => 1]])
            ->assertSessionHasErrors();

        $this->assertSame(0, DB::table('catalog_item_links')->count());
    }

    // ─── what the offer editor reads ─────────────────────────────────────

    public function test_the_components_endpoint_returns_the_pickers_row_shape_plus_a_quantity(): void
    {
        $bundle = $this->bundle();
        $boiler = $this->item([
            'name' => 'Centrală termică Bosch',
            'code' => 'CT-24',
            'price_cents' => 450050,
            'stock' => 7,
        ]);
        $labour = $this->item([
            'name' => 'Manoperă montaj',
            'code' => 'MAN-1',
            'type' => 'service',
            'unit' => 'oră',
            'price_cents' => 8000,
            'stock' => null,
        ]);

        $this->compose($bundle, [
            ['related_item_id' => $boiler->id, 'quantity' => 1],
            ['related_item_id' => $labour->id, 'quantity' => 4.5],
        ])->assertSessionHasNoErrors();

        $rows = $this->components($bundle);

        $this->assertCount(2, $rows);

        $keys = array_keys($rows[0]);
        sort($keys);

        // Exactly these keys. The offer editor hands a component row to the same
        // ItemPicker renderer that draws a catalogue search result and a shop
        // product, so a key more or a key fewer is a blank card — and the
        // quantity is the only thing a component adds to that shape.
        $this->assertSame(
            ['currency', 'id', 'image_url', 'inventory_quantity', 'name', 'price', 'quantity', 'sku', 'source', 'type', 'unit', 'uuid'],
            $keys
        );

        $this->assertSame($boiler->id, $rows[0]['id']);
        $this->assertSame('Centrală termică Bosch', $rows[0]['name']);
        $this->assertSame('CT-24', $rows[0]['sku']);
        $this->assertSame('4500.50', $rows[0]['price']);
        $this->assertSame('RON', $rows[0]['currency']);
        $this->assertSame(7, $rows[0]['inventory_quantity']);
        $this->assertNull($rows[0]['image_url']);
        $this->assertSame('catalog', $rows[0]['source']);
        $this->assertSame(1.0, (float) $rows[0]['quantity']);

        // Null stock means "not tracked", which is every service. Zero would
        // draw "stoc epuizat" on a line of labour.
        $this->assertNull($rows[1]['inventory_quantity']);
        $this->assertSame(4.5, (float) $rows[1]['quantity']);

        // The order the person arranged, because it is the order the lines are
        // appended to the offer in.
        $this->assertSame([$boiler->id, $labour->id], array_column($rows, 'id'));
    }

    public function test_the_components_endpoint_shows_components_and_not_the_cross_sell_chips(): void
    {
        $bundle = $this->bundle();
        $part = $this->item(['name' => 'Componentă']);
        $suggestion = $this->item(['name' => 'Sugestie']);

        $this->actingAs($this->ctx['user'])
            ->put(route('client.catalog.knowledge', $bundle->uuid), [
                'components' => [['related_item_id' => $part->id, 'quantity' => 1]],
                'links' => [['related_item_id' => $suggestion->id]],
            ])
            ->assertSessionHasNoErrors();

        $rows = $this->components($bundle);

        // One table holds both edges, so the `kind` clause is the whole of the
        // difference — and without it the offer editor would append a
        // suggestion as a line the customer is charged for.
        $this->assertSame([$part->id], array_column($rows, 'id'));
    }

    public function test_an_item_that_is_not_a_bundle_has_no_components(): void
    {
        $product = $this->item();
        $suggestion = $this->item();

        $this->actingAs($this->ctx['user'])
            ->put(route('client.catalog.knowledge', $product->uuid), ['links' => [['related_item_id' => $suggestion->id]]])
            ->assertSessionHasNoErrors();

        $this->assertSame([], $this->components($product));
    }

    public function test_another_firms_bundle_cannot_be_opened_for_its_components(): void
    {
        $intruder = $this->createWorkspaceContext();
        $theirs = $this->bundle([], (int) $intruder['workspace']->id);
        $theirPart = $this->item(['name' => 'Piesa lor'], (int) $intruder['workspace']->id);

        $this->compose($theirs, [['related_item_id' => $theirPart->id, 'quantity' => 1]], $intruder['user'])
            ->assertSessionHasNoErrors();

        // The uuid route binding finds the row in any workspace. This endpoint
        // would hand over another firm's composition, prices included.
        $this->actingAs($this->ctx['user'])
            ->getJson(route('client.catalog.components', $theirs->uuid))
            ->assertForbidden();
    }

    // ─── the tab ─────────────────────────────────────────────────────────

    public function test_the_ansambluri_tab_counts_and_lists_only_bundles(): void
    {
        $this->bundle();
        $this->bundle(['name' => 'Revizie anuală', 'code' => 'ANS-2']);
        $this->item(['name' => 'Un produs oarecare']);

        $intruder = $this->createWorkspaceContext();
        $this->bundle(['name' => 'Ansamblul lor', 'code' => 'ANS-X'], (int) $intruder['workspace']->id);

        $props = $this->actingAs($this->ctx['user'])
            ->get(route('client.catalog.index', ['type' => 'bundle']))
            ->viewData('page')['props'];

        $this->assertSame(2, $props['counts']['bundle']);
        $this->assertCount(2, $props['items']['data']);
        $this->assertSame(
            ['bundle', 'bundle'],
            array_column($props['items']['data'], 'type')
        );
    }

    // ─── and what the offer does with it ─────────────────────────────────

    public function test_a_bundle_cannot_be_put_on_an_offer_as_a_line(): void
    {
        $offer = $this->draft();
        $bundle = $this->bundle();
        $part = $this->item();

        $this->compose($bundle, [['related_item_id' => $part->id, 'quantity' => 2]])
            ->assertSessionHasNoErrors();

        $response = $this->actingAs($this->ctx['user'])
            ->from(route('client.offers.show', $offer->uuid))
            ->put(route('client.offers.update', $offer->uuid), [
                'items' => [['catalog_item_id' => $bundle->id, 'quantity' => 1]],
            ]);

        $response->assertSessionHasErrors();

        // Told which line, the way every other refusal in the editor is: the
        // person has to be able to find the row on the screen.
        $keys = array_keys(session('errors')->getBag('default')->getMessages());
        $this->assertNotEmpty(
            array_filter($keys, fn (string $key): bool => str_starts_with($key, 'items.0')),
            'the refusal has to name the line it is about, not the form as a whole',
        );

        // Refused, and nothing written — not a bundle line, and not its parts
        // quietly expanded on the server either. The expansion is a thing the
        // person does on the screen and can then edit.
        $this->assertSame(0, OfferItem::where('offer_id', $offer->id)->count());
        $this->assertSame(0, (int) $offer->fresh()->total_cents);
    }

    public function test_the_components_added_one_line_each_price_the_offer_correctly(): void
    {
        $offer = $this->draft();
        $bundle = $this->bundle(['price_cents' => 999999]);
        $boiler = $this->item(['name' => 'Centrală termică', 'price_cents' => 450000]);
        $labour = $this->item(['name' => 'Manoperă montaj', 'type' => 'service', 'unit' => 'oră', 'price_cents' => 8000]);

        $this->compose($bundle, [
            ['related_item_id' => $boiler->id, 'quantity' => 1],
            ['related_item_id' => $labour->id, 'quantity' => 4.5],
        ])->assertSessionHasNoErrors();

        // Exactly what "Adaugă ansamblu" does on the screen: read the components
        // and append one line per component, each carrying its own quantity.
        $lines = array_map(fn (array $row): array => [
            'catalog_item_id' => $row['id'],
            'quantity' => (float) $row['quantity'],
        ], $this->components($bundle));

        $this->actingAs($this->ctx['user'])
            ->put(route('client.offers.update', $offer->uuid), ['items' => $lines])
            ->assertSessionHasNoErrors();

        $offer->refresh();
        $written = OfferItem::where('offer_id', $offer->id)->orderBy('position')->get();

        $this->assertCount(2, $written);
        $this->assertSame('Centrală termică', $written[0]->name);
        $this->assertSame('Manoperă montaj', $written[1]->name);
        $this->assertSame('oră', $written[1]->unit);

        // 1 x 4 500,00 + 4,5 x 80,00 = 4 860,00. The bundle's own price is not
        // in it: the customer is quoted the parts.
        $this->assertSame(486000, (int) $offer->subtotal_cents);
        $this->assertSame(450000, (int) $written[0]->unit_price_cents);
        $this->assertSame(8000, (int) $written[1]->unit_price_cents);
        $this->assertSame(4.5, (float) $written[1]->quantity);
    }

    public function test_a_component_can_still_be_priced_by_hand_on_the_offer(): void
    {
        $offer = $this->draft();
        $bundle = $this->bundle();
        $part = $this->item(['price_cents' => 10000]);

        $this->compose($bundle, [['related_item_id' => $part->id, 'quantity' => 2]])
            ->assertSessionHasNoErrors();

        $this->actingAs($this->ctx['user'])
            ->put(route('client.offers.update', $offer->uuid), [
                'items' => [['catalog_item_id' => $part->id, 'quantity' => 2, 'unit_price_cents' => 9000]],
            ])
            ->assertSessionHasNoErrors();

        // A component is an ordinary line once it is on the offer. Nothing about
        // having come from a bundle freezes its price.
        $this->assertSame(18000, (int) $offer->fresh()->subtotal_cents);
    }
}
