<?php

namespace Tests\Feature\Catalog;

use App\Models\Client;
use App\Models\User;
use App\Models\Workspace;
use App\Modules\Catalog\Models\CatalogItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * The catalogue the firm writes by hand.
 *
 * The parts worth pinning are the ones nobody notices until a customer does:
 * a price that lost its bani, a type the schema does not have, an item from
 * another workspace answering a request, and a delete that took the row with
 * it. The picker's JSON shape is pinned key by key because the inbox merges it
 * with the shop's rows through one renderer — a renamed key there is a blank
 * card in a conversation, not a failing page.
 */
class CatalogItemTest extends TestCase
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
        return CatalogItem::create(array_merge([
            'workspace_id' => $workspaceId ?? $this->workspaceId(),
            'type' => 'product',
            'name' => 'Perie interdentară',
            'code' => 'PI-1',
            'category' => 'Materiale',
            'unit' => 'buc',
            'price_cents' => 1590,
            'is_active' => true,
        ], $attrs));
    }

    /**
     * A complete, valid form. Price arrives the way a person types it — lei with
     * a decimal point — and never as bani.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Consultație stomatologică',
            'price' => '240.50',
        ], $overrides);
    }

    /**
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>
     */
    private function indexProps(array $query = []): array
    {
        return $this->actingAs($this->ctx['user'])
            ->get(route('client.catalog.index', $query))
            ->viewData('page')['props'];
    }

    // ─── money ───────────────────────────────────────────────────────────

    public function test_a_price_typed_with_bani_is_stored_as_integer_minor_units(): void
    {
        $this->actingAs($this->ctx['user'])
            ->post(route('client.catalog.store'), $this->payload(['price' => '240.50']))
            ->assertSessionHasNoErrors();

        $item = CatalogItem::where('workspace_id', $this->workspaceId())->firstOrFail();

        $this->assertSame(24050, $item->price_cents);
        $this->assertSame($this->ctx['user']->id, $item->created_by);
    }

    /**
     * The failure this guards is the quiet one: 240 stored as 240 bani prices a
     * consultation at two lei forty, and nothing on the screen says so until the
     * offer goes out.
     */
    public function test_a_whole_price_is_read_as_lei_and_not_as_bani(): void
    {
        $this->actingAs($this->ctx['user'])
            ->post(route('client.catalog.store'), $this->payload(['price' => '240']))
            ->assertSessionHasNoErrors();

        $this->assertSame(24000, CatalogItem::where('workspace_id', $this->workspaceId())->firstOrFail()->price_cents);
    }

    public function test_an_item_saved_without_a_price_costs_nothing_rather_than_failing(): void
    {
        $this->actingAs($this->ctx['user'])
            ->post(route('client.catalog.store'), ['name' => 'Consultație de control'])
            ->assertSessionHasNoErrors();

        $this->assertSame(0, CatalogItem::where('workspace_id', $this->workspaceId())->firstOrFail()->price_cents);
    }

    // ─── type ────────────────────────────────────────────────────────────

    public function test_an_item_saved_without_a_type_is_a_product(): void
    {
        $this->actingAs($this->ctx['user'])
            ->post(route('client.catalog.store'), $this->payload())
            ->assertSessionHasNoErrors();

        $this->assertSame('product', CatalogItem::where('workspace_id', $this->workspaceId())->firstOrFail()->type);
    }

    public function test_only_the_three_declared_types_are_accepted(): void
    {
        $this->assertSame(['product', 'service', 'bundle'], CatalogItem::TYPES);

        foreach (CatalogItem::TYPES as $type) {
            $this->actingAs($this->ctx['user'])
                ->post(route('client.catalog.store'), $this->payload(['name' => 'Poziție '.$type, 'type' => $type]))
                ->assertSessionHasNoErrors();
        }

        $this->assertSame(3, CatalogItem::where('workspace_id', $this->workspaceId())->count());

        // A fourth kind is refused by the request, not by the column — an enum
        // that rejects at the database is a 500, not a message on the form.
        $this->actingAs($this->ctx['user'])
            ->post(route('client.catalog.store'), $this->payload(['name' => 'Abonament', 'type' => 'subscription']))
            ->assertSessionHasErrors('type');

        $this->assertSame(3, CatalogItem::where('workspace_id', $this->workspaceId())->count());
    }

    public function test_an_item_without_a_name_is_refused(): void
    {
        $this->actingAs($this->ctx['user'])
            ->post(route('client.catalog.store'), $this->payload(['name' => '']))
            ->assertSessionHasErrors('name');

        $this->assertSame(0, CatalogItem::count());
    }

    // ─── the list ────────────────────────────────────────────────────────

    public function test_the_list_carries_only_this_workspaces_items_and_the_fields_the_screen_reads(): void
    {
        $mine = $this->item(['stock' => 3]);
        $intruder = $this->createWorkspaceContext();
        $this->item(['name' => 'Nu este al meu', 'code' => 'X-1'], $intruder['workspace']->id);

        $props = $this->indexProps();

        $this->assertCount(1, $props['items']['data']);

        $row = $props['items']['data'][0];
        $this->assertSame($mine->uuid, $row['uuid']);

        foreach ([
            'uuid', 'type', 'name', 'code', 'category', 'unit', 'price_cents',
            'stock', 'low_stock_threshold', 'is_active', 'created_at', 'creator',
        ] as $key) {
            $this->assertArrayHasKey($key, $row, "The list row is missing [{$key}].");
        }

        // The id, and enough to know whether the agent has anything to go on.
        // This assertion used to say the opposite, and it was pinning a defect:
        // without the id the "Completează cu AI" batch was always empty, so the
        // nudge banner's only action opened a modal showing a validation error.
        $this->assertArrayHasKey('id', $row);
        $this->assertArrayHasKey('has_description', $row);
        $this->assertArrayHasKey('tag_count', $row);

        // Not the description itself: the list never shows it.
        $this->assertArrayNotHasKey('description', $row);
    }

    public function test_the_category_list_is_this_workspaces_distinct_non_empty_categories(): void
    {
        $this->item(['name' => 'Perie', 'code' => 'P-1', 'category' => 'Materiale']);
        $this->item(['name' => 'Ață dentară', 'code' => 'P-2', 'category' => 'Materiale']);
        $this->item(['name' => 'Detartraj', 'code' => 'S-1', 'category' => 'Servicii', 'type' => 'service']);
        $this->item(['name' => 'Fără categorie', 'code' => 'P-3', 'category' => null]);

        $intruder = $this->createWorkspaceContext();
        $this->item(['name' => 'Al lor', 'code' => 'X-1', 'category' => 'Altceva'], $intruder['workspace']->id);

        $this->assertSame(['Materiale', 'Servicii'], $this->indexProps()['categories']);
    }

    public function test_the_type_tab_and_the_search_box_narrow_the_list(): void
    {
        $this->item(['name' => 'Perie interdentară', 'code' => 'PI-1']);
        $this->item(['name' => 'Detartraj complet', 'code' => 'DET-1', 'type' => 'service']);

        $byType = $this->indexProps(['type' => 'service']);
        $this->assertCount(1, $byType['items']['data']);
        $this->assertSame('Detartraj complet', $byType['items']['data'][0]['name']);
        $this->assertSame('service', $byType['filters']['type']);

        // The code is what a person has in front of them on a delivery note, so
        // it searches alongside the name.
        $byCode = $this->indexProps(['search' => 'PI-1']);
        $this->assertCount(1, $byCode['items']['data']);
        $this->assertSame('Perie interdentară', $byCode['items']['data'][0]['name']);

        $unfiltered = $this->indexProps();
        $this->assertSame(
            ['type' => null, 'category' => null, 'search' => null, 'stock' => null],
            $unfiltered['filters']
        );
    }

    public function test_the_counts_and_the_stats_never_include_another_workspace(): void
    {
        $this->item([
            'name' => 'Perie interdentară', 'code' => 'PI-1', 'category' => 'Materiale',
            'price_cents' => 1590, 'stock' => 2, 'low_stock_threshold' => 5,
        ]);
        $this->item([
            'name' => 'Detartraj', 'code' => 'DET-1', 'type' => 'service', 'category' => 'Materiale',
            'price_cents' => 0, 'stock' => null,
        ]);

        $before = $this->indexProps();

        $intruder = $this->createWorkspaceContext();
        for ($i = 1; $i <= 4; $i++) {
            $this->item([
                'name' => 'Al lor '.$i, 'code' => 'X-'.$i, 'type' => 'service',
                'category' => 'Altceva', 'price_cents' => 0, 'stock' => 0, 'low_stock_threshold' => 5,
            ], $intruder['workspace']->id);
        }

        $after = $this->indexProps();

        // A tile that counts four items the firm cannot see leaks just as surely
        // as a row would.
        $this->assertEquals($before['counts'], $after['counts']);
        $this->assertEquals($before['stats'], $after['stats']);

        $this->assertSame(2, $after['counts']['all']);
        $this->assertSame(1, $after['counts']['product']);
        $this->assertSame(1, $after['counts']['service']);
        $this->assertSame(0, $after['counts']['bundle']);

        $this->assertSame(2, $after['stats']['total']);
        $this->assertSame(1, $after['stats']['categories']);
        $this->assertSame(1, $after['stats']['low_stock']);
        $this->assertSame(1, $after['stats']['no_price']);

        $this->assertSame(['Materiale'], $after['categories']);
    }

    // ─── who may reach an item ───────────────────────────────────────────

    public function test_an_item_from_another_workspace_cannot_be_opened(): void
    {
        $intruder = $this->createWorkspaceContext();
        $theirs = $this->item(['name' => 'Secretul lor', 'code' => 'B-1'], $intruder['workspace']->id);

        $this->actingAs($this->ctx['user'])
            ->get(route('client.catalog.show', $theirs->uuid))
            ->assertForbidden();
    }

    public function test_an_item_from_another_workspace_cannot_be_updated(): void
    {
        $intruder = $this->createWorkspaceContext();
        $theirs = $this->item(['name' => 'Secretul lor', 'code' => 'B-1'], $intruder['workspace']->id);

        $this->actingAs($this->ctx['user'])
            ->put(route('client.catalog.update', $theirs->uuid), $this->payload(['name' => 'Furat']))
            ->assertForbidden();

        $this->assertSame('Secretul lor', $theirs->fresh()->name);
    }

    public function test_an_item_from_another_workspace_cannot_be_deleted(): void
    {
        $intruder = $this->createWorkspaceContext();
        $theirs = $this->item(['name' => 'Secretul lor', 'code' => 'B-1'], $intruder['workspace']->id);

        $this->actingAs($this->ctx['user'])
            ->delete(route('client.catalog.destroy', $theirs->uuid))
            ->assertForbidden();

        $this->assertNull($theirs->fresh()->deleted_at);
    }

    // ─── delete ──────────────────────────────────────────────────────────

    public function test_deleting_an_item_takes_it_off_the_list_but_keeps_the_row(): void
    {
        $item = $this->item();

        $this->actingAs($this->ctx['user'])
            ->delete(route('client.catalog.destroy', $item->uuid))
            ->assertSessionHasNoErrors();

        $props = $this->indexProps();
        $this->assertSame([], $props['items']['data']);
        $this->assertSame(0, $props['counts']['all']);

        // The row stays: an offer sent last month still names this item, and a
        // hard delete would leave that offer describing nothing.
        $this->assertSoftDeleted('catalog_items', ['id' => $item->id]);
    }

    // ─── the inbox picker ────────────────────────────────────────────────

    public function test_the_picker_search_returns_the_shape_the_inbox_expects(): void
    {
        $item = $this->item([
            'name' => 'Consultație stomatologică',
            'code' => 'CONS-1',
            'type' => 'service',
            'price_cents' => 24000,
            'stock' => 7,
        ]);

        $response = $this->actingAs($this->ctx['user'])
            ->getJson(route('client.catalog.search', ['q' => 'Consult']));

        $response->assertOk()->assertJsonCount(1);

        $row = $response->json('0');
        $keys = array_keys($row);
        sort($keys);

        // Exactly these keys: the picker renders shop rows and catalogue rows
        // through one component, so a key more or a key fewer is a broken card.
        // `uuid` because /{item}/components binds by it and the offer editor
        // asks this row for it; `unit` because without it every catalogue line
        // on an offer was quoted in "buc".
        $this->assertSame(
            ['currency', 'id', 'image_url', 'inventory_quantity', 'name', 'price', 'sku', 'source', 'type', 'unit', 'uuid'],
            $keys
        );

        $this->assertSame($item->id, $row['id']);
        $this->assertSame('Consultație stomatologică', $row['name']);
        $this->assertSame('CONS-1', $row['sku']);
        $this->assertSame('240.00', $row['price']);
        $this->assertSame('RON', $row['currency']);
        $this->assertSame(7, $row['inventory_quantity']);
        $this->assertNull($row['image_url']);
        $this->assertSame('catalog', $row['source']);
    }

    public function test_the_picker_price_keeps_the_bani_as_two_decimals(): void
    {
        $this->item(['name' => 'Detartraj', 'code' => 'DET-1', 'price_cents' => 24050, 'stock' => null]);

        $row = $this->actingAs($this->ctx['user'])
            ->getJson(route('client.catalog.search', ['q' => 'Detartraj']))
            ->assertOk()
            ->json('0');

        $this->assertSame('240.50', $row['price']);
        // Null means "not tracked", which is every service. Zero would mean the
        // picker shows "out of stock" on a consultation.
        $this->assertNull($row['inventory_quantity']);
    }

    public function test_the_picker_search_never_returns_more_than_twenty_rows(): void
    {
        for ($i = 1; $i <= 25; $i++) {
            $this->item(['name' => 'Produs '.$i, 'code' => 'P-'.$i]);
        }

        $this->actingAs($this->ctx['user'])
            ->getJson(route('client.catalog.search'))
            ->assertOk()
            ->assertJsonCount(20);
    }

    public function test_the_picker_search_skips_withdrawn_items_and_other_workspaces(): void
    {
        $this->item(['name' => 'În vânzare', 'code' => 'A-1']);
        $this->item(['name' => 'Retras din ofertă', 'code' => 'R-1', 'is_active' => false]);
        $this->item(['name' => 'Șters', 'code' => 'D-1'])->delete();

        $intruder = $this->createWorkspaceContext();
        $this->item(['name' => 'Al altei firme', 'code' => 'B-1'], $intruder['workspace']->id);

        $this->actingAs($this->ctx['user'])
            ->getJson(route('client.catalog.search'))
            ->assertOk()
            ->assertJsonCount(1)
            ->assertJsonPath('0.name', 'În vânzare');
    }

    // ─── CSV import ──────────────────────────────────────────────────────

    private function importCsv(string $csv): TestResponse
    {
        return $this->actingAs($this->ctx['user'])->post(route('client.catalog.import'), [
            'file' => UploadedFile::fake()->createWithContent('catalog.csv', $csv),
        ]);
    }

    public function test_the_csv_import_creates_the_rows_it_describes(): void
    {
        $this->importCsv(implode("\n", [
            'name,code,category,unit,price,stock',
            'Perie interdentară,PI-1,Materiale,buc,15.90,40',
            'Detartraj,DET-1,Servicii,ședință,240,',
        ]))->assertSessionHasNoErrors();

        $this->assertSame(2, CatalogItem::where('workspace_id', $this->workspaceId())->count());

        $perie = CatalogItem::where('workspace_id', $this->workspaceId())->where('code', 'PI-1')->firstOrFail();
        $this->assertSame('Perie interdentară', $perie->name);
        $this->assertSame('Materiale', $perie->category);
        $this->assertSame('buc', $perie->unit);
        $this->assertSame(1590, $perie->price_cents);
        $this->assertSame(40, $perie->stock);
        $this->assertSame($this->ctx['user']->id, $perie->created_by);
        // insert() bypasses the model's creating hook, so the uuid every URL
        // needs has to be written by the importer itself.
        $this->assertNotEmpty($perie->uuid);

        $detartraj = CatalogItem::where('workspace_id', $this->workspaceId())->where('code', 'DET-1')->firstOrFail();
        $this->assertSame('ședință', $detartraj->unit);
        $this->assertSame(24000, $detartraj->price_cents);
        // An empty stock cell is "not tracked", not "none left".
        $this->assertNull($detartraj->stock);
    }

    /**
     * A Romanian Excel exports "1.234,50" with semicolons between the columns.
     * Read as a plain float that price is one leu, and the firm finds out when a
     * customer has been quoted it.
     */
    public function test_the_csv_import_reads_a_romanian_price_and_a_semicolon_file(): void
    {
        $this->importCsv(implode("\n", [
            'name;code;price',
            'Proteză totală;PRO-1;1.234,50',
            'Consultație;CONS-1;240,00',
        ]))->assertSessionHasNoErrors();

        $items = CatalogItem::where('workspace_id', $this->workspaceId())->pluck('price_cents', 'code');

        $this->assertSame(123450, (int) $items['PRO-1']);
        $this->assertSame(24000, (int) $items['CONS-1']);
    }

    /**
     * Most of the firms this is sold to sell services, not things: a clinic, a
     * plumber, an estate agency. Their price list imports as sixty products,
     * each under the wrong tab and each showing a stock column it has no use
     * for, and the only way back is to open sixty rows by hand.
     */
    public function test_the_csv_import_honours_a_type_column(): void
    {
        $this->importCsv(implode("\n", [
            'name,type,code,price',
            'Perie interdentară,product,PI-1,15.90',
            'Detartraj,service,DET-1,240',
        ]))->assertSessionHasNoErrors();

        $types = CatalogItem::where('workspace_id', $this->workspaceId())->pluck('type', 'code');

        $this->assertSame('product', $types['PI-1']);
        $this->assertSame('service', $types['DET-1']);
    }

    public function test_the_csv_import_skips_a_row_with_no_name(): void
    {
        $this->importCsv(implode("\n", [
            'name,code,price',
            ',FANTOMA,99',
            'Consultație,CONS-1,120',
        ]))->assertSessionHasNoErrors();

        $items = CatalogItem::where('workspace_id', $this->workspaceId())->get();

        $this->assertCount(1, $items);
        $this->assertSame('Consultație', $items->first()->name);
        $this->assertSame(12000, $items->first()->price_cents);
    }

    public function test_the_csv_import_files_every_row_under_the_importers_own_workspace(): void
    {
        $intruder = $this->createWorkspaceContext();

        $this->importCsv(implode("\n", [
            'name,code,price',
            'Perie,PI-1,15.90',
        ]))->assertSessionHasNoErrors();

        $this->assertSame(1, CatalogItem::where('workspace_id', $this->workspaceId())->count());
        $this->assertSame(0, CatalogItem::where('workspace_id', $intruder['workspace']->id)->count());
    }
}
