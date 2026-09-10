<?php

namespace App\Modules\Catalog\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Catalog\Models\CatalogItem;
use App\Modules\Catalog\Models\CatalogItemLink;
use App\Modules\Catalog\Models\CatalogItemTag;
use App\Modules\Catalog\Support\Money;
use App\Modules\Offers\Services\OfferStats;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The hand-written catalogue: what the firm sells and what it charges.
 *
 * Every query here filters workspace_id by hand, because this application has
 * no global scope and no tenant trait — one missing clause is another firm's
 * price list on the screen.
 *
 * Money is kept in bani, as integers. price_cents = 24000 is 240,00 lei. The
 * form speaks lei because a person types lei; the conversion happens once, on
 * the way in.
 */
class CatalogItemController extends Controller
{
    /** What the picker in the inbox is allowed to pull back in one go. */
    private const PICKER_LIMIT = 20;

    /** Rows on a page of the list. */
    private const PER_PAGE = 30;

    /** The two ways the list can be narrowed by stock. */
    private const STOCK_FILTERS = ['low', 'out'];

    /**
     * How long the sentence the agent reads out may be.
     *
     * The column is TEXT and would take far more; this is a description a
     * customer reads inside a chat message, so a cap that says "one or two
     * sentences" belongs here rather than at 65,535.
     */
    private const DESCRIPTION_MAX = 2000;

    /** As long as the label column, so the message is about the words and not the storage. */
    private const TAG_LABEL_MAX = 64;

    /** Chips per colour. Past a dozen the panel has stopped being a summary. */
    private const MAX_TAGS = 20;

    /** "Merge bine împreună cu" suggestions on one item. */
    private const MAX_CROSS_SELL = 20;

    /** Lines in one bundle. Every one of them becomes a line on an offer. */
    private const MAX_COMPONENTS = 50;

    /**
     * How many ansambluri the item page lists as containing this item.
     *
     * A capped list rather than all of them: this is a sidebar note saying
     * "careful, changing the price here moves these too", and a firm that has
     * put one screw into forty kits does not need forty links to read that.
     */
    private const MAX_BUNDLE_PARENTS = 12;

    /**
     * Where every number about how an item actually sells comes from.
     *
     * Injected rather than reached statically so the aggregates can be faked in
     * a test without a fixture of four thousand offers, and so the one place
     * that owns the minimum-sample gate stays one place.
     */
    public function __construct(private readonly OfferStats $offerStats) {}

    public function index(Request $request): Response
    {
        $workspaceId = (int) $request->user()->workspace_id;

        $filters = $this->filters($request);

        $page = $this->scoped($workspaceId)
            ->with('creator:id,name')
            // Counted, not loaded: the list needs to know whether an item has
            // any tags, not what they say.
            ->withCount('tags')
            ->narrowed($filters)
            ->orderBy('name')
            ->paginate(self::PER_PAGE)
            ->withQueryString();

        // ONE grouped query for the whole page, asked before through() turns the
        // models into arrays. A count per row is thirty round trips on a page of
        // thirty and four thousand on a catalogue of four thousand — and this
        // column exists on every row, so it is exactly the place an N+1 hides.
        $offers = $this->offerStats->forCatalogueList(
            $workspaceId,
            $page->getCollection()->map(static fn (CatalogItem $item): int => (int) $item->id)->all(),
        );

        $items = $page->through(fn (CatalogItem $item): array => $this->row($item) + [
            // Zero here is not a missing number, it is "niciodată" — an item
            // nobody has quoted yet. Counting is not dividing, so this one is
            // not behind the sample gate: one offer is one offer.
            'offer_count' => (int) ($offers[(int) $item->id] ?? 0),
        ]);

        return Inertia::render('Catalog/Index', [
            'items' => $items,
            // Counts and stats are aggregates, not a walk over the rows: a shop
            // with forty products must cost the same number of queries as one
            // with four thousand.
            'counts' => $this->counts($workspaceId),
            'stats' => $this->stats($workspaceId),
            'categories' => $this->categories($workspaceId),
            'filters' => $filters,
        ]);
    }

    /**
     * A new line in the catalogue.
     *
     * Optionally a whole ansamblu in one press: `components` alongside
     * `type: 'bundle'` creates the kit AND its contents in one transaction, and
     * lands the person on it so they can name and price it. That is what the
     * agent's cross-sell observation offers — "fă un ansamblu din ele" — and it
     * cannot go through client.catalog.knowledge, which replaces the components
     * of an item that already exists and cannot bring one into being.
     *
     * Components are read only when the new row is a bundle. On anything else a
     * bundle_component link is inert — no screen draws it and no offer expands
     * it — so the key is not a second, silent way to write one.
     */
    public function store(Request $request): RedirectResponse
    {
        $workspaceId = (int) $request->user()->workspace_id;

        $data = $request->validate($this->rules(true));

        $components = $this->listIn($data, 'components');

        $item = DB::transaction(function () use ($request, $workspaceId, $data, $components): CatalogItem {
            // The payload wins over the defaults on the right: a field the form
            // left out falls back here rather than reaching the column as null.
            $item = CatalogItem::create($this->payload($data) + [
                'workspace_id' => $workspaceId,
                'type' => 'product',
                'unit' => 'buc',
                'price_cents' => 0,
                'is_active' => true,
                'created_by' => (int) $request->user()->id,
            ]);

            if ($components !== [] && $item->type === 'bundle') {
                // targets() resolves every far end inside this workspace and
                // throws when one of them is not ours. Thrown from in here on
                // purpose: the exception rolls the transaction back, so a
                // refused component leaves no half-made ansamblu behind.
                $this->replaceLinks(
                    $workspaceId,
                    $item,
                    CatalogItemLink::KIND_BUNDLE_COMPONENT,
                    $this->targets($workspaceId, $item, $components, 'components'),
                );
            }

            return $item;
        });

        $success = __('":name" was added to the catalogue.', ['name' => $item->name]);

        // A kit made in one press is not finished — it has no price and a name
        // the screen guessed — so the person is taken to it. Everything else
        // still goes back to the list it was added from.
        if ($components !== [] && $item->type === 'bundle') {
            return redirect()->route('client.catalog.show', $item->uuid)->with('success', $success);
        }

        return back()->with('success', $success);
    }

    public function show(Request $request, CatalogItem $item): Response
    {
        $workspaceId = (int) $request->user()->workspace_id;
        $this->authorise($request, $item);

        $item->loadMissing('creator:id,name');

        // Always an array, never null: OfferStats hands back the counts even
        // when the sample is too thin to divide, and flags it. Turning that flag
        // into an absent prop is this controller's job — see the note on the
        // 'sells' key below.
        $sells = $this->offerStats->forItem($workspaceId, (int) $item->id);

        // Read once and split, rather than twice with a kind clause: the
        // composition panel and the cross-sell chips are two views of the same
        // handful of rows, and a bundle rarely has more than a dozen.
        $links = $this->linkRows($workspaceId, $item);

        return Inertia::render('Catalog/Show', [
            // The knowledge panel's fields sit beside the name-and-price ones
            // rather than inside row(): the list does not need any of them, and
            // thirty descriptions on a page of the index is payload nobody reads.
            'item' => $this->row($item) + [
                'description' => $item->description,
                'description_source' => $item->description_source,
                'min_price_cents' => $item->min_price_cents === null ? null : (int) $item->min_price_cents,
                'tags' => $this->tagRows($workspaceId, $item),
                'links' => $links,
            ],
            // What this bundle is made of. Empty for anything else, because
            // nothing but a bundle has components.
            'components' => array_values(array_filter(
                $links,
                static fn (array $link): bool => $link['kind'] === CatalogItemLink::KIND_BUNDLE_COMPONENT,
            )),
            'categories' => $this->categories($workspaceId),

            // ── How it actually sells ────────────────────────────────────────
            //
            // NULL below OfferStats::MIN_DECIDED_OFFERS, and the screen then
            // draws NOTHING — no tile, no dash, no 0%. Every figure in this
            // block is a ratio, and a ratio over three answered offers is not a
            // fact about the product, it is a fact about the week. "Acceptat 0%"
            // on day three reports a firm that is failing when nothing has
            // happened yet, and nobody reading it can tell the two apart.
            //
            // The counts underneath it are true even then, and are still thrown
            // away: a panel that shows half of itself invites the reader to
            // wonder what happened to the other half, and the honest answer —
            // "not yet" — is what an absent panel already says.
            //
            // The threshold lives in OfferStats, beside the arithmetic it
            // guards, so there is one copy of it and not one per screen.
            'sells' => $sells['sample_short'] ? null : $sells,

            // "Când propun acest produs împreună cu X, rata de acceptare urcă de
            // la N% la M%." Null far more often than not: it is a correlation
            // between two items across answered offers, and it stays hidden
            // until each side of the comparison has enough offers in it for the
            // difference to mean anything.
            'insight' => $this->offerStats->crossSellInsight($workspaceId, (int) $item->id),

            // "Apare în ansamblurile" — the other way down the link table from
            // the composition panel above. Catalogue data, not sales data, so it
            // is not behind the gate and it is not OfferStats' business.
            'bundles' => $this->bundlesContaining($workspaceId, $item),
        ]);
    }

    public function update(Request $request, CatalogItem $item): RedirectResponse
    {
        $this->authorise($request, $item);

        $data = $request->validate($this->rules(false));

        $item->update($this->payload($data));

        return back()->with('success', __('Catalog item updated.'));
    }

    public function destroy(Request $request, CatalogItem $item): RedirectResponse
    {
        $this->authorise($request, $item);

        // Soft, like the document library: a price list is a day of typing, and
        // a row removed by a mis-click should be recoverable rather than gone.
        $item->delete();

        // Not back(): the delete is as likely to come from the item's own page,
        // and that page no longer exists.
        return redirect()->route('client.catalog.index')
            ->with('success', __('Catalog item deleted.'));
    }

    /**
     * What a bundle is made of, in the shape the offer editor's picker reads.
     *
     * "Adaugă ansamblu" appends each of these as its own line, so this returns
     * the same row as search() — a name, a unit price, a stock — plus the
     * quantity the firm put in the bundle. The editor then prices the lines the
     * way it prices any other: from the catalogue, on the server.
     *
     * An item that is not a bundle simply has no components and gets an empty
     * list. A component the firm has since deleted from the catalogue is left
     * out — the row is gone, and there is nothing left to quote. A component
     * that is merely inactive is kept: the firm composed this bundle on purpose,
     * and dropping a line out of a quote without saying so is worse than
     * offering one they can delete.
     */
    public function components(Request $request, CatalogItem $item): JsonResponse
    {
        $workspaceId = (int) $request->user()->workspace_id;
        $this->authorise($request, $item);

        $links = CatalogItemLink::query()
            ->where('workspace_id', $workspaceId)
            ->where('catalog_item_id', $item->id)
            ->where('kind', CatalogItemLink::KIND_BUNDLE_COMPONENT)
            ->orderBy('position')
            ->get(['id', 'related_item_id', 'quantity']);

        // Resolved through a second scoped query rather than the relation: the
        // belongsTo carries the foreign key alone, and every read of another
        // firm's table in this application says workspace_id out loud.
        $related = $this->itemsById($workspaceId, $links->pluck('related_item_id')->all());

        $rows = [];

        foreach ($links as $link) {
            $other = $related[(int) $link->related_item_id] ?? null;

            if ($other === null) {
                continue;
            }

            $rows[] = $this->pickerRow($other) + ['quantity' => $this->quantity($link->quantity)];
        }

        return response()->json($rows);
    }

    /**
     * "Ce trebuie să știe agentul", saved.
     *
     * A separate PUT from update() on purpose: the panel above it edits what the
     * thing is and what it costs, this one edits what the agent should say about
     * it, and a person who opened one of them must not be made to submit the
     * other.
     *
     * What it accepts, all of it optional — a key that is not sent is not
     * touched, and a key that is sent REPLACES its whole list, the way the offer
     * editor replaces its lines:
     *
     *   description   the sentence a customer reads. Null or blank clears it.
     *   min_price     lei, as a person types them. Blank or 0 means no floor.
     *   fits          ["pentru birouri", …]  the green chips
     *   excludes      ["nu pentru exterior", …]  the red chips
     *   links         [{ related_item_id }]  "merge bine împreună cu"
     *   components    [{ related_item_id, quantity }]  what a bundle is made of
     *
     * Everything on the far end of a link is resolved inside this workspace and
     * REFUSED when it is not found. A bundle is the natural place for another
     * firm's product id to arrive, and dropping it silently would leave the
     * person looking at a list that saved without the row they added.
     */
    public function knowledge(Request $request, CatalogItem $item): RedirectResponse
    {
        $workspaceId = (int) $request->user()->workspace_id;
        $this->authorise($request, $item);

        $data = $request->validate($this->knowledgeRules());

        // Everything is validated before the transaction opens, so a refusal
        // costs no writes and the person is told about every bad row at once.
        $values = $this->knowledgeValues($item, $data);

        $tags = [];
        $links = [];

        if (array_key_exists('fits', $data)) {
            $tags[CatalogItemTag::KIND_FITS] = $this->labels($this->listIn($data, 'fits'), 'fits');
        }

        if (array_key_exists('excludes', $data)) {
            $tags[CatalogItemTag::KIND_EXCLUDES] = $this->labels($this->listIn($data, 'excludes'), 'excludes');
        }

        if (array_key_exists('links', $data)) {
            $links[CatalogItemLink::KIND_CROSS_SELL] = $this->targets($workspaceId, $item, $this->listIn($data, 'links'), 'links');
        }

        if (array_key_exists('components', $data)) {
            $links[CatalogItemLink::KIND_BUNDLE_COMPONENT] = $this->targets($workspaceId, $item, $this->listIn($data, 'components'), 'components');
        }

        DB::transaction(function () use ($item, $workspaceId, $values, $tags, $links): void {
            if ($values !== []) {
                // forceFill: description_source and min_price_cents are written
                // by this controller on purpose, and must not depend on what a
                // $fillable list happens to allow.
                $item->forceFill($values)->save();
            }

            foreach ($tags as $kind => $labels) {
                $this->replaceTags($workspaceId, $item, (string) $kind, $labels);
            }

            foreach ($links as $kind => $targets) {
                $this->replaceLinks($workspaceId, $item, (string) $kind, $targets);
            }
        });

        return back()->with('success', __('Saved what the agent needs to know.'));
    }

    /**
     * What the inbox picker searches.
     *
     * Returns the same row shape as the Ecommerce product search so the picker
     * can show both sources through one renderer. `image_url` is always null in
     * Stage 1 — the catalogue has no photos yet, and the picker already draws a
     * placeholder for a row without one.
     */
    public function search(Request $request): JsonResponse
    {
        $workspaceId = (int) $request->user()->workspace_id;
        $term = $this->input($request, 'q');
        $type = $this->input($request, 'type');

        $query = $this->scoped($workspaceId)->where('is_active', true);

        // The offer editor asks for ?type=bundle so "adaugă ansamblu" searches
        // ansambluri and not the whole price list. Anything not in TYPES is not
        // a filter, so a hand-edited URL narrows the list or does nothing.
        if (in_array($type, CatalogItem::TYPES, true)) {
            $query->where('type', $type);
        }

        if ($term !== '') {
            $like = Money::likeTerm($term);
            $query->where(function (Builder $inner) use ($like): void {
                $inner->where('name', 'like', $like)->orWhere('code', 'like', $like);
            });
        }

        $items = $query->orderBy('name')->limit(self::PICKER_LIMIT)->get();

        return response()->json($items->map(fn (CatalogItem $item): array => $this->pickerRow($item))->all());
    }

    /**
     * One row as every picker in the application reads it.
     *
     * The same key set the Ecommerce product search returns, so the picker can
     * draw a hand-typed service and a synced shop product through one renderer.
     * `image_url` is always null — the catalogue still has no photos, and the
     * picker already draws a placeholder for a row without one.
     *
     * @return array<string, mixed>
     */
    private function pickerRow(CatalogItem $item): array
    {
        return [
            'id' => (int) $item->id,
            // The route key, because /{item}/components binds by uuid and the
            // offer editor asks this row for it. Sending the integer id there
            // 404s, and the caller reports the bundle as empty.
            'uuid' => $item->uuid,
            'name' => $item->name,
            'sku' => $item->code,
            // Without this every catalogue line on an offer fell back to "buc",
            // and a unit is half of what a price means.
            'unit' => $item->unit,
            'type' => $item->type,
            'price' => number_format($item->price_cents / 100, 2, '.', ''),
            'currency' => 'RON',
            'inventory_quantity' => $item->stock,
            'image_url' => null,
            'source' => 'catalog',
        ];
    }

    /**
     * The filters the list understands, cleaned.
     *
     * Anything not in the allow-lists is dropped rather than passed through, so
     * a hand-edited URL narrows the list or does nothing — it never reaches a
     * query as a column name or an order direction.
     *
     * @return array<string, string|null>
     */
    private function filters(Request $request): array
    {
        $type = $this->input($request, 'type');
        $stock = $this->input($request, 'stock');

        return [
            'type' => in_array($type, CatalogItem::TYPES, true) ? $type : null,
            'category' => $this->input($request, 'category') ?: null,
            'search' => $this->input($request, 'search') ?: null,
            'stock' => in_array($stock, self::STOCK_FILTERS, true) ? $stock : null,
        ];
    }

    /**
     * The items the agent has nothing to say about.
     *
     * No description AND no chips of either colour — one or the other is enough
     * for the agent to work with, so an item with a description but no tags is
     * not on this list and is not what the banner is counting.
     *
     * Written as a NOT EXISTS rather than a left join and a HAVING: the count
     * behind the banner is of the whole workspace, and a join that fans out over
     * tags would need a DISTINCT to stop counting an item once per tag.
     *
     * @param  Builder<CatalogItem>  $query
     */
    private function narrowToNeedsHelp(Builder $query, int $workspaceId): void
    {
        $query->where('is_active', true)
            ->where(function (Builder $inner): void {
                $inner->whereNull('description')->orWhere('description', '');
            })
            ->whereNotExists(function (QueryBuilder $tags) use ($workspaceId): void {
                $tags->selectRaw('1')
                    ->from('catalog_item_tags')
                    ->whereColumn('catalog_item_tags.catalog_item_id', 'catalog_items.id')
                    // The join column would be enough for correctness, but this
                    // application has no global scope and every read of a
                    // tenant-owned table says workspace_id itself.
                    ->where('catalog_item_tags.workspace_id', $workspaceId);
            });
    }

    /**
     * One query parameter, as a trimmed string.
     *
     * Deliberately not $request->string(), which casts through Stringable and
     * raises "Array to string conversion" on ?search[]=x — a 500 on a URL
     * anybody can type. Anything that is not a string is simply not a filter.
     */
    private function input(Request $request, string $key): string
    {
        $value = $request->input($key);

        return is_string($value) ? trim($value) : '';
    }

    /**
     * Every read starts here.
     *
     * There is no global scope in this application, so the workspace clause is
     * not optional and not implied — it is this method, and every query goes
     * through it.
     *
     * @return Builder<CatalogItem>
     */
    private function scoped(int $workspaceId): Builder
    {
        return CatalogItem::query()->where('workspace_id', $workspaceId);
    }

    /**
     * One row, as the two screens read it.
     *
     * A fixed key set rather than the model: a column added later — a cost
     * price, a supplier — must not start appearing in an Inertia prop because
     * somebody ran a migration.
     *
     * @return array<string, mixed>
     */
    private function row(CatalogItem $item): array
    {
        return [
            // The AI batch is built from these rows. Without the id it was
            // always empty, and the nudge banner's only action opened a modal
            // showing an untranslated Laravel validation error.
            'id' => (int) $item->id,
            'uuid' => $item->uuid,
            'type' => $item->type,
            // Not the text, only whether there is one: the list does not show
            // descriptions, and shipping every one of them into a paginated
            // prop would be a page of prose nobody reads.
            'has_description' => trim((string) $item->description) !== '',
            'tag_count' => (int) ($item->tags_count ?? 0),
            'name' => $item->name,
            'code' => $item->code,
            'category' => $item->category,
            'unit' => $item->unit,
            'price_cents' => (int) $item->price_cents,
            'stock' => $item->stock,
            'low_stock_threshold' => $item->low_stock_threshold,
            'is_active' => (bool) $item->is_active,
            'created_at' => $item->getAttribute('created_at'),
            'creator' => $item->creator ? ['id' => $item->creator->id, 'name' => $item->creator->name] : null,
        ];
    }

    /**
     * How many of each type, for the tabs.
     *
     * One grouped query, not four: the tabs are always all shown.
     *
     * @return array<string, int>
     */
    private function counts(int $workspaceId): array
    {
        $byType = $this->scoped($workspaceId)
            ->selectRaw('type, count(*) as aggregate')
            ->groupBy('type')
            ->pluck('aggregate', 'type');

        $counts = ['all' => (int) $byType->sum()];

        foreach (CatalogItem::TYPES as $type) {
            $counts[$type] = (int) ($byType[$type] ?? 0);
        }

        return $counts;
    }

    /**
     * The tiles above the list, and the number the nudge banner reads.
     *
     * `low_stock` uses the same rule as the list filter — stock at or below the
     * item's own threshold — so the number in the tile and the rows behind it
     * can never disagree.
     *
     * @return array<string, int>
     */
    private function stats(int $workspaceId): array
    {
        $needsHelp = $this->scoped($workspaceId);
        $this->narrowToNeedsHelp($needsHelp, $workspaceId);

        return [
            // What the amber strip on the list says out loud. Of the whole
            // workspace, like every other number on this screen — the tabs are
            // how you narrow, so a count that shrank as you typed would be
            // describing a list you had already left.
            'needs_help' => (int) $needsHelp->count(),
            'total' => (int) $this->scoped($workspaceId)->count(),
            'categories' => (int) $this->scoped($workspaceId)->distinct()->count('category'),
            'services' => (int) $this->scoped($workspaceId)->where('type', 'service')->count(),
            'low_stock' => (int) $this->scoped($workspaceId)
                ->whereNotNull('stock')
                ->whereNotNull('low_stock_threshold')
                ->whereColumn('stock', '<=', 'low_stock_threshold')
                ->count(),
            'no_price' => (int) $this->scoped($workspaceId)->where('price_cents', 0)->count(),
        ];
    }

    /**
     * The categories this workspace actually uses, for the filter and the
     * datalist on the item page.
     *
     * A free-text column with an autocomplete rather than a second table: a firm
     * with a dozen categories does not need one, and a table would need its own
     * scoping test to earn its keep.
     *
     * @return array<int, string>
     */
    private function categories(int $workspaceId): array
    {
        return $this->scoped($workspaceId)
            ->whereNotNull('category')
            ->where('category', '!=', '')
            ->distinct()
            ->orderBy('category')
            ->pluck('category')
            ->all();
    }

    /**
     * @return array<string, array<int, string>>
     */
    private function rules(bool $creating): array
    {
        $required = $creating ? 'required' : 'sometimes';

        // What a bundle is made of, accepted on creation only. update() edits
        // the name and the price of a row that exists; its contents are the
        // knowledge panel's, and offering two ways to write them is how the two
        // start disagreeing.
        $components = $creating ? [
            'components' => ['sometimes', 'nullable', 'array', 'max:'.self::MAX_COMPONENTS],
            'components.*.related_item_id' => ['required', 'integer', 'min:1'],
            // decimal(12,3), and a component nobody gets any of is not a
            // component. Same rule as the knowledge panel's, deliberately.
            'components.*.quantity' => ['nullable', 'numeric', 'min:0.001', 'max:999999'],
        ] : [];

        return $components + [
            'name' => [$required, 'string', 'max:255'],
            // The three kinds and nothing else. A fourth is refused here, on the
            // form, rather than at the column, where it would be a 500.
            'type' => ['sometimes', 'in:'.implode(',', CatalogItem::TYPES)],
            'code' => ['sometimes', 'nullable', 'string', 'max:255'],
            'category' => ['sometimes', 'nullable', 'string', 'max:255'],
            // "buc", "ora", "kg", "mp" — a unit is a word, not a sentence.
            'unit' => ['sometimes', 'nullable', 'string', 'max:32'],
            // Lei, as the form writes them: "240" or "240.50".
            'price' => ['sometimes', 'nullable', 'string', 'max:20', 'regex:/^\d{1,8}([.,]\d{1,2})?$/'],
            'stock' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'low_stock_threshold' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:1000000'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * Turn validated form input into columns.
     *
     * Only the keys that were actually sent are touched, so a partial update —
     * the price alone, the stock alone — leaves the rest of the row as it was.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function payload(array $data): array
    {
        $values = [];

        if (array_key_exists('name', $data)) {
            $values['name'] = $this->text($data['name']) ?? '';
        }

        if (array_key_exists('type', $data)) {
            $values['type'] = $data['type'];
        }

        if (array_key_exists('code', $data)) {
            $values['code'] = $this->text($data['code']);
        }

        if (array_key_exists('category', $data)) {
            $values['category'] = $this->text($data['category']);
        }

        if (array_key_exists('unit', $data)) {
            $values['unit'] = $this->text($data['unit']) ?? 'buc';
        }

        if (array_key_exists('price', $data)) {
            $values['price_cents'] = Money::bani($data['price']);
        }

        if (array_key_exists('stock', $data)) {
            $values['stock'] = $data['stock'] === null ? null : (int) $data['stock'];
        }

        if (array_key_exists('low_stock_threshold', $data)) {
            $values['low_stock_threshold'] = $data['low_stock_threshold'] === null
                ? null
                : (int) $data['low_stock_threshold'];
        }

        if (array_key_exists('is_active', $data)) {
            $values['is_active'] = (bool) $data['is_active'];
        }

        return $values;
    }

    /** A trimmed string, or null for a box the person left empty. */
    private function text(mixed $value): ?string
    {
        $value = is_string($value) ? trim($value) : '';

        return $value === '' ? null : $value;
    }

    /**
     * What the knowledge panel may send.
     *
     * Only the shape is checked here. Whose items the ids are, and whether the
     * floor is under the price, are questions this cannot answer — they are
     * asked below, where the answer can name the row that was wrong.
     *
     * @return array<string, array<int, string>>
     */
    private function knowledgeRules(): array
    {
        return [
            'description' => ['sometimes', 'nullable', 'string', 'max:'.self::DESCRIPTION_MAX],
            // Lei, as the form writes them: "180" or "180,50".
            // A regex rather than `numeric`, which refuses "199,50" — the
            // separator a Romanian keyboard produces. Money::bani reads either.
            'min_price' => ['sometimes', 'nullable', 'string', 'max:20', 'regex:/^\d{1,8}([.,]\d{1,2})?$/'],

            'fits' => ['sometimes', 'nullable', 'array', 'max:'.self::MAX_TAGS],
            'fits.*' => ['nullable', 'string', 'max:'.self::TAG_LABEL_MAX],
            'excludes' => ['sometimes', 'nullable', 'array', 'max:'.self::MAX_TAGS],
            'excludes.*' => ['nullable', 'string', 'max:'.self::TAG_LABEL_MAX],

            'links' => ['sometimes', 'nullable', 'array', 'max:'.self::MAX_CROSS_SELL],
            'links.*.related_item_id' => ['required', 'integer', 'min:1'],

            'components' => ['sometimes', 'nullable', 'array', 'max:'.self::MAX_COMPONENTS],
            'components.*.related_item_id' => ['required', 'integer', 'min:1'],
            // decimal(12,3), and a component nobody gets any of is not a
            // component. 0,001 is the smallest the column can tell from nothing.
            'components.*.quantity' => ['nullable', 'numeric', 'min:0.001', 'max:999999'],
        ];
    }

    /**
     * The two columns on catalog_items the panel writes, or nothing at all when
     * it sent neither.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     *
     * @throws ValidationException
     */
    private function knowledgeValues(CatalogItem $item, array $data): array
    {
        $values = [];

        if (array_key_exists('description', $data)) {
            $description = $this->text($data['description']);
            $values['description'] = $description;

            // Provenance changes only when the words do. The panel resends the
            // description on every save, so relabelling it here would turn an
            // agent's draft into "written by a person" the moment somebody typed
            // a minimum price — and there would be no way back to the truth.
            if ($description === null) {
                $values['description_source'] = null;
            } elseif ($description !== $item->description) {
                $values['description_source'] = 'human';
            }
        }

        if (array_key_exists('min_price', $data)) {
            $floor = Money::bani($data['min_price']);

            if ($floor > 0) {
                $price = (int) $item->price_cents;

                if ($price <= 0) {
                    throw ValidationException::withMessages([
                        'min_price' => __('Set a price for this item before setting a minimum.'),
                    ]);
                }

                if ($floor > $price) {
                    // Refused rather than clamped. A floor above the list price
                    // is a typo — a comma in the wrong place — and left in it
                    // would silently forbid every discount on this item for as
                    // long as nobody reopened the panel.
                    throw ValidationException::withMessages([
                        'min_price' => __('The minimum price cannot be higher than the price.'),
                    ]);
                }
            }

            // Zero is not a floor, it is the absence of one.
            $values['min_price_cents'] = $floor > 0 ? $floor : null;
        }

        return $values;
    }

    /**
     * One of the four lists, as an array.
     *
     * `nullable` lets the panel send null for "I have none of these", which is
     * a list of nothing and not a request to leave the list alone — that is what
     * leaving the key out means.
     *
     * @param  array<string, mixed>  $data
     * @return array<int, mixed>
     */
    private function listIn(array $data, string $key): array
    {
        $value = $data[$key] ?? null;

        return is_array($value) ? array_values($value) : [];
    }

    /**
     * The chips of one colour, cleaned.
     *
     * A blank chip is nothing typed and is dropped. The same word twice is not
     * dropped, it is refused, and named: the panel is showing both chips, so
     * keeping one of them silently would leave the person looking at a list that
     * is not what was saved.
     *
     * "The same word" is the unique index's idea of it, not PHP's.
     * catalog_item_tags is utf8mb4_unicode_ci, which is case- AND
     * accent-insensitive, so "Fără boiler", "Fara Boiler" and "fără boiler" are
     * one value to the key. mb_strtolower folds the case and leaves the
     * diacritics alone, which lets the second spelling through to a
     * duplicate-key QueryException — a 500 on a form, from somebody typing a
     * Romanian word once with its diacritics and once without, which is simply
     * what a keyboard without them produces. Str::ascii() flattens the other
     * half, so what is compared here is what MySQL would have compared.
     *
     * @param  array<int, mixed>  $input
     * @return array<int, string>
     *
     * @throws ValidationException
     */
    private function labels(array $input, string $field): array
    {
        $seen = [];
        $labels = [];
        $errors = [];

        foreach ($input as $index => $value) {
            $label = $this->text($value);

            if ($label === null) {
                continue;
            }

            $key = mb_strtolower(Str::ascii($label));

            if (isset($seen[$key])) {
                $errors["{$field}.{$index}"] = __('":label" is already on this list.', ['label' => $label]);

                continue;
            }

            $seen[$key] = true;
            $labels[] = $label;
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return $labels;
    }

    /**
     * The far end of every link in one list, resolved inside this workspace.
     *
     * Three refusals, none of them silent: an id this workspace does not own, an
     * item pointing at itself, and the same item twice in one list. The first is
     * the tenancy one — a bundle is where another firm's product id would arrive
     * if it arrived anywhere — and dropping it quietly would save a list that is
     * not the list the person is looking at.
     *
     * The third is the unique key's: (catalog_item_id, related_item_id, kind)
     * would refuse the second row with a duplicate-key 500 on a form. Named per
     * line, like every other refusal in this panel, because two mentions of one
     * part might have meant five of them or might have been a mis-click, and the
     * person with the screen in front of them is the one who knows which.
     *
     * @param  array<int, mixed>  $input
     * @return array<int, array{related_item_id: int, quantity: float}>
     *
     * @throws ValidationException
     */
    private function targets(int $workspaceId, CatalogItem $item, array $input, string $field): array
    {
        $wanted = [];

        foreach ($input as $row) {
            $id = is_array($row) ? $this->id($row['related_item_id'] ?? null) : null;

            if ($id !== null) {
                $wanted[$id] = $id;
            }
        }

        // One query for the whole list rather than one per row: a fifty-line
        // bundle is one round trip, and the answer is the set of ids this
        // workspace actually owns.
        $owned = $wanted === []
            ? []
            : array_flip(array_map('intval', $this->scoped($workspaceId)
                ->whereIn('id', array_values($wanted))
                ->pluck('id')
                ->all()));

        $errors = [];
        $targets = [];
        $seen = [];

        foreach ($input as $index => $row) {
            $id = is_array($row) ? $this->id($row['related_item_id'] ?? null) : null;
            $key = "{$field}.{$index}.related_item_id";

            if ($id === null) {
                $errors[$key] = __('This line could not be read.');

                continue;
            }

            if ($id === (int) $item->id) {
                $errors[$key] = __('An item cannot be linked to itself.');

                continue;
            }

            if (! isset($owned[$id])) {
                $errors[$key] = __('This catalogue item does not belong to your workspace.');

                continue;
            }

            if (isset($seen[$id])) {
                $errors[$key] = __('This item is already on the list.');

                continue;
            }

            $seen[$id] = true;

            $targets[] = [
                'related_item_id' => $id,
                'quantity' => $this->quantity(is_array($row) ? ($row['quantity'] ?? null) : null),
            ];
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return $targets;
    }

    /**
     * Replace one colour of chips.
     *
     * Wholesale, like the offer editor replaces its lines: the panel sends the
     * list it has, and what is not in it is gone. The one thing carried across
     * is `source` — a word the agent drafted and a person confirmed is still the
     * agent's word after the panel is saved again with it untouched.
     *
     * @param  array<int, string>  $labels
     */
    private function replaceTags(int $workspaceId, CatalogItem $item, string $kind, array $labels): void
    {
        /** @var array<string, string> $before */
        $before = CatalogItemTag::query()
            ->where('workspace_id', $workspaceId)
            ->where('catalog_item_id', $item->id)
            ->where('kind', $kind)
            ->pluck('source', 'label')
            ->all();

        CatalogItemTag::query()
            ->where('workspace_id', $workspaceId)
            ->where('catalog_item_id', $item->id)
            ->where('kind', $kind)
            ->delete();

        if ($labels === []) {
            return;
        }

        $now = now();
        $rows = [];

        foreach ($labels as $label) {
            $rows[] = [
                'workspace_id' => $workspaceId,
                'catalog_item_id' => (int) $item->id,
                'kind' => $kind,
                'label' => $label,
                'source' => ($before[$label] ?? 'human') === 'ai' ? 'ai' : 'human',
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        // One insert rather than one per chip.
        CatalogItemTag::query()->insert($rows);
    }

    /**
     * Replace one kind of link.
     *
     * Position is the order they arrived in, restarting at 0 for each kind:
     * a bundle's components go onto an offer in the order the firm arranged
     * them, and the cross-sell chips are read left to right.
     *
     * @param  array<int, array{related_item_id: int, quantity: float}>  $targets
     */
    private function replaceLinks(int $workspaceId, CatalogItem $item, string $kind, array $targets): void
    {
        CatalogItemLink::query()
            ->where('workspace_id', $workspaceId)
            ->where('catalog_item_id', $item->id)
            ->where('kind', $kind)
            ->delete();

        if ($targets === []) {
            return;
        }

        $now = now();
        $rows = [];

        foreach ($targets as $position => $target) {
            $rows[] = [
                'workspace_id' => $workspaceId,
                'catalog_item_id' => (int) $item->id,
                'related_item_id' => $target['related_item_id'],
                'kind' => $kind,
                'quantity' => $target['quantity'],
                'position' => $position,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        CatalogItemLink::query()->insert($rows);
    }

    /**
     * The chips on one item, both colours, in the order they were typed.
     *
     * Not ordered by kind: 'excludes' sorts before 'fits', so ordering by it
     * would put the red list first. The panel splits them itself.
     *
     * @return array<int, array<string, mixed>>
     */
    private function tagRows(int $workspaceId, CatalogItem $item): array
    {
        return CatalogItemTag::query()
            ->where('workspace_id', $workspaceId)
            ->where('catalog_item_id', $item->id)
            ->orderBy('id')
            ->get(['id', 'kind', 'label', 'source'])
            ->map(static fn (CatalogItemTag $tag): array => [
                'id' => (int) $tag->id,
                'kind' => $tag->kind,
                'label' => $tag->label,
                'source' => $tag->source,
            ])
            ->all();
    }

    /**
     * Everything this item points at — components and cross-sells together, each
     * carrying the name and code of the item on the far end so the panel has
     * something to draw without a second request per chip.
     *
     * A link whose far end has been deleted from the catalogue is left out. The
     * row survives in the database, harmless; the next save of the panel drops
     * it, because the panel sends the list it was shown.
     *
     * @return array<int, array<string, mixed>>
     */
    private function linkRows(int $workspaceId, CatalogItem $item): array
    {
        $links = CatalogItemLink::query()
            ->where('workspace_id', $workspaceId)
            ->where('catalog_item_id', $item->id)
            ->orderBy('kind')
            ->orderBy('position')
            ->get(['id', 'related_item_id', 'kind', 'quantity']);

        $related = $this->itemsById($workspaceId, $links->pluck('related_item_id')->all());

        $rows = [];

        foreach ($links as $link) {
            $other = $related[(int) $link->related_item_id] ?? null;

            if ($other === null) {
                continue;
            }

            $rows[] = [
                'id' => (int) $link->id,
                'related_item_id' => (int) $link->related_item_id,
                'related_name' => $other->name,
                'related_code' => $other->code,
                'kind' => $link->kind,
                'quantity' => $this->quantity($link->quantity),
            ];
        }

        return $rows;
    }

    /**
     * The ansambluri this item is a part of.
     *
     * The composition panel reads catalog_item_links forwards — what is inside
     * this bundle. This reads the same table backwards — which bundles contain
     * this item — because a price typed on one row quietly moves every kit it
     * sits in, and the person changing it should be able to see which.
     *
     * ONE query, joined rather than walked: a link row carries an id and nothing
     * a person can read, so resolving the names through the relation would be a
     * query per bundle. Both sides say workspace_id, because in this application
     * a join column is not a tenancy clause — catalog_item_links.workspace_id is
     * the row's own, and catalog_items.workspace_id is the far end's.
     *
     * Only rows that are still ansambluri: the knowledge endpoint will store a
     * component link against any type, and a bundle_component hanging off a
     * plain product is inert — listing it under "apare în ansamblurile" would
     * name something that is not one.
     *
     * @return array<int, array<string, mixed>>
     */
    private function bundlesContaining(int $workspaceId, CatalogItem $item): array
    {
        $rows = DB::table('catalog_item_links')
            ->join('catalog_items', 'catalog_items.id', '=', 'catalog_item_links.catalog_item_id')
            ->where('catalog_item_links.workspace_id', $workspaceId)
            ->where('catalog_items.workspace_id', $workspaceId)
            ->where('catalog_item_links.related_item_id', (int) $item->id)
            ->where('catalog_item_links.kind', CatalogItemLink::KIND_BUNDLE_COMPONENT)
            ->where('catalog_items.type', 'bundle')
            // catalog_items is soft-deleted and DB::table knows nothing about
            // that. Without this a bundle the firm deleted last month is still
            // listed, and the link on it 404s.
            ->whereNull('catalog_items.deleted_at')
            ->orderBy('catalog_items.name')
            ->limit(self::MAX_BUNDLE_PARENTS)
            ->get(['catalog_items.uuid', 'catalog_items.name', 'catalog_item_links.quantity']);

        return $rows->map(static fn (object $row): array => [
            'uuid' => (string) $row->uuid,
            'name' => (string) $row->name,
            // How many of this item go into that kit. A string, at the scale the
            // column keeps it — see the note on OfferItem::$casts.
            'quantity' => (string) $row->quantity,
        ])->all();
    }

    /**
     * The far ends of a set of links, keyed by id.
     *
     * Scoped, like every read in this controller: the foreign key says which row
     * it is, and the where clause says whose.
     *
     * @param  array<int, mixed>  $ids
     * @return array<int|string, CatalogItem>
     */
    private function itemsById(int $workspaceId, array $ids): array
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));

        if ($ids === []) {
            return [];
        }

        return $this->scoped($workspaceId)
            ->whereIn('id', $ids)
            ->get()
            ->keyBy('id')
            ->all();
    }

    /**
     * A component quantity, at the three decimals the column keeps.
     *
     * Missing means one. A quantity is never zero or negative — the rules refuse
     * it on the way in, and this is the second line of the same defence, because
     * these numbers multiply into a total a customer reads.
     */
    private function quantity(mixed $value): float
    {
        if ($value === null || $value === '') {
            return 1.0;
        }

        $quantity = round((float) $value, 3);

        return $quantity > 0 ? $quantity : 1.0;
    }

    /**
     * A positive integer, or null for anything that is not one.
     *
     * "12" and 12 are the same id; "12abc", -1, 0 and an array are not ids at
     * all, and become a message about the row rather than a cast to 12.
     */
    private function id(mixed $value): ?int
    {
        if (! is_int($value) && ! (is_string($value) && ctype_digit($value))) {
            return null;
        }

        $id = (int) $value;

        return $id > 0 ? $id : null;
    }

    /**
     * The uuid route binding finds the row in any workspace — the check that it
     * belongs to this one is ours to make, every time.
     */
    private function authorise(Request $request, CatalogItem $item): void
    {
        abort_unless((int) $item->workspace_id === (int) $request->user()->workspace_id, 403);
    }
}
