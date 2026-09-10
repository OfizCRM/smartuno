<?php

namespace App\Modules\Offers\Services;

use App\Modules\Catalog\Models\CatalogItem;
use App\Modules\Catalog\Models\CatalogItemLink;
use App\Modules\Catalog\Models\CatalogItemTag;
use App\Modules\Catalog\Support\Money;
use Illuminate\Database\Eloquent\Builder;

/**
 * The firm's own catalogue, as the drafting agent is allowed to see it: the rows
 * that go into the prompt, and the index every id the model answers with is
 * checked against.
 *
 * Named after CompanyProfileContext, which does the same job for the chatbot —
 * load a tenant's own facts once and render them as one prompt block.
 *
 * ONE SET, TWO USES, deliberately. The rows rendered into the prompt and the
 * index validation accepts are the same loaded objects, so an id coming back
 * from the model is either something we showed it or something it invented.
 * There is no third case, and no path by which an item this workspace does not
 * own can be priced onto an offer.
 *
 * NOT EMBEDDINGS. The obvious-looking route — EmbeddingStore — is the wrong one
 * here on three separate counts: its default path loads every chunk into PHP and
 * computes cosine in a loop, its cosine() returns 0.0 on a dimension mismatch
 * without saying so, and ai_kb_chunks carries no workspace_id at all, so there is
 * nothing to filter a tenant by. A hundred and fifty catalogue rows with their
 * descriptions and tags are twelve to fifteen thousand tokens; they fit in one
 * prompt, they are exactly current, and plain indexed SQL keeps them tenant-safe.
 * Above NARROW_ABOVE items the answer is still SQL — a keyword pre-filter on the
 * columns and the tags — and still not embeddings.
 *
 * LOADED WITH EXPLICIT SCOPED QUERIES rather than through the Eloquent relations,
 * following CatalogItemController::components(): tenancy in this application is
 * manual, and every read of a tenant-owned table says workspace_id out loud.
 */
class CatalogueContext
{
    /**
     * The most rows that may go into one prompt.
     *
     * A ceiling on the prompt, not on the catalogue: past this many items the
     * cost of the call stops being worth what the extra rows add, and the
     * keyword pre-filter below is what keeps the right ones in.
     */
    public const PROMPT_LIMIT = 400;

    /**
     * Above this many active items the catalogue is pre-filtered with SQL before
     * it is rendered. Below it, the whole catalogue goes in — which is the case
     * for every firm this product is sold to, and it is why the drafter can be
     * exactly current rather than eventually consistent with an index.
     */
    public const NARROW_ABOVE = 500;

    /** Words shorter than this are too weak to filter on ("cu", "sau", "mai"). */
    private const MIN_TERM = 4;

    /** The most keywords one message contributes to the pre-filter. */
    private const MAX_TERMS = 8;

    /** Per item, per kind. The panel keeps a handful; a wall of them is prompt noise. */
    private const MAX_TAGS = 6;

    /** Cross-sell ids suggested per item. */
    private const MAX_CROSS_SELLS = 5;

    /** Description characters kept per row. Two sentences of Romanian fit inside this. */
    private const DESCRIPTION_CHARS = 240;

    /**
     * An excludes tag shorter than this is not matched against the customer's
     * own words at all. "ten gras" is a safe needle; "3+" is not.
     */
    private const MIN_TAG_MATCH = 4;

    /**
     * What each type is called in the prompt. Romanian, because everything else
     * the model reads here — names, descriptions, tags — was typed in Romanian
     * by the firm.
     *
     * @var array<string, string>
     */
    private const TYPE_LABELS = [
        'product' => 'produs',
        'service' => 'serviciu',
        'bundle' => 'pachet',
    ];

    /**
     * Romanian words that carry no product meaning. Only words that could never
     * be part of a catalogue name belong here — "livrare" and "montaj" are real
     * services somebody sells, and dropping them would filter out the very rows
     * the customer asked for.
     *
     * @var list<string>
     */
    private const STOPWORDS = [
        'buna', 'bună', 'ziua', 'salut', 'multumesc', 'mersi', 'rog', 'putea',
        'poate', 'puteti', 'sunt', 'este', 'aveti', 'avem', 'vreau', 'doresc',
        'vrea', 'care', 'ceva', 'cumva', 'costa', 'cost', 'pret', 'preturi',
        'oferta', 'oferte', 'oferti', 'spuneti', 'trebuie', 'dumneavoastra',
        'astept', 'astepta', 'zile', 'urgent', 'intrebare', 'legatura',
    ];

    /**
     * @param  array<int, CatalogItem>  $items  everything the agent may choose, by id
     * @param  array<int, list<CatalogItemTag>>  $tags  item id => its tags, including component items
     * @param  array<int, list<array{item: CatalogItem, quantity: string}>>  $components  bundle id => what it is made of
     * @param  array<int, list<int>>  $crossSells  item id => ids worth suggesting alongside
     * @param  int  $total  active items this workspace has, before any narrowing
     * @param  bool  $narrowed  whether the prompt shows a keyword-filtered slice
     */
    public function __construct(
        private readonly array $items,
        private readonly array $tags,
        private readonly array $components,
        private readonly array $crossSells,
        public readonly int $total,
        public readonly bool $narrowed,
    ) {}

    /**
     * Read one workspace's catalogue, ready to be shown to a model.
     *
     * $customerText is used only to narrow an oversized catalogue. It never
     * decides what may be quoted: on a catalogue of ordinary size nothing is
     * filtered at all, and the model does the choosing.
     */
    public static function load(int $workspaceId, string $customerText): self
    {
        // Soft-deleted rows are excluded by the model's own global scope, and
        // inactive ones are excluded here: "nu mai vindem asta" has to mean the
        // agent cannot propose it either.
        $base = CatalogItem::query()
            ->where('workspace_id', $workspaceId)
            ->where('is_active', true);

        $total = (clone $base)->count();

        $query = (clone $base)->orderBy('category')->orderBy('name');
        $narrowed = false;

        if ($total > self::NARROW_ABOVE) {
            $narrowed = self::narrow($query, $workspaceId, $customerText);
        }

        // is_active is selected even though every row here has it true: a
        // component of a bundle is read back out of this same set, and an
        // attribute that was never loaded reads as null, which is not false but
        // behaves like it at every call site that asks.
        $rows = $query->limit(self::PROMPT_LIMIT)->get([
            'id', 'type', 'name', 'code', 'category', 'unit',
            'price_cents', 'min_price_cents', 'stock', 'description', 'is_active',
        ]);

        /** @var array<int, CatalogItem> $items */
        $items = $rows->keyBy('id')->all();

        if ($items === []) {
            return new self([], [], [], [], $total, $narrowed);
        }

        [$components, $crossSells] = self::links($workspaceId, array_keys($items), $items);

        // Tags are loaded for the component items too, not only for the rows in
        // the prompt: a bundle expands into components that are never shown to
        // the model, and an "nu îl propune dacă" on one of those still has to be
        // able to stop the line.
        $tagged = array_keys($items);
        foreach ($components as $parts) {
            foreach ($parts as $part) {
                $tagged[] = (int) $part['item']->id;
            }
        }

        return new self($items, self::tags($workspaceId, array_values(array_unique($tagged))), $components, $crossSells, $total, $narrowed);
    }

    /** Nothing to choose from. The caller stops here rather than paying for a call. */
    public function isEmpty(): bool
    {
        return $this->items === [];
    }

    /** How many rows the prompt actually carries. */
    public function count(): int
    {
        return count($this->items);
    }

    /**
     * The item behind an id the model returned, or null when it made the id up.
     *
     * The lookup is against what was loaded for this workspace, so it is also
     * the tenancy check: another firm's id is simply not in the array.
     */
    public function item(int $id): ?CatalogItem
    {
        return $this->items[$id] ?? null;
    }

    /**
     * What a bundle is made of, with each component resolved inside this
     * workspace. Empty for anything that is not a bundle, and for a bundle whose
     * components have all been deleted from the catalogue.
     *
     * @return list<array{item: CatalogItem, quantity: string}>
     */
    public function componentsOf(int $id): array
    {
        return $this->components[$id] ?? [];
    }

    /**
     * The firm's own words for "do not propose this", checked against the
     * customer's own words — the label of the first excludes tag that appears in
     * the message, or null.
     *
     * A literal net under the model, not a second classifier. The prompt tells
     * the model these are hard constraints and the model is normally the one
     * that applies them; this catches the case where it did not. Both sides are
     * folded first, because Romanian on WhatsApp is written without diacritics
     * and "ten uscat si sensibil" has to match a tag typed "ten uscat și
     * sensibil".
     *
     * It is deliberately biased towards blocking. A false positive — "nu am
     * copii" matching a tag "copii" — costs one proposal a person can add back
     * by hand in the editor. A false negative sends a customer with oily skin a
     * cream the firm has written down as wrong for them.
     *
     * @param  string  $foldedText  the customer's messages through fold()
     */
    public function blockedBy(int $id, string $foldedText): ?string
    {
        if ($foldedText === '') {
            return null;
        }

        foreach ($this->tags[$id] ?? [] as $tag) {
            if ($tag->kind !== CatalogItemTag::KIND_EXCLUDES) {
                continue;
            }

            $needle = self::fold((string) $tag->label);

            if (mb_strlen($needle) < self::MIN_TAG_MATCH) {
                continue;
            }

            if (str_contains($foldedText, $needle)) {
                return (string) $tag->label;
            }
        }

        return null;
    }

    /**
     * The catalogue as one JSON array, for the prompt.
     *
     * Compact and not pretty-printed, with every empty field left out: this is
     * the largest thing in the call by an order of magnitude, and whitespace and
     * nulls are paid for per token on every inbound message that reaches the
     * agent.
     *
     * Prices are in lei rather than bani. The model is told not to compute money
     * and does not return any — the figure is there so it can respect a budget
     * the customer stated out loud, and "240" is what that customer said, not
     * "24000".
     */
    public function promptBlock(): string
    {
        $rows = [];

        foreach ($this->items as $id => $item) {
            $row = [
                'id' => (int) $id,
                'nume' => (string) $item->name,
                'tip' => self::TYPE_LABELS[$item->type] ?? 'produs',
                'pret' => self::lei((int) $item->price_cents),
            ];

            if ($item->min_price_cents !== null && (int) $item->min_price_cents < (int) $item->price_cents) {
                $row['pret_minim'] = self::lei((int) $item->min_price_cents);
            }

            // 'buc' is the default on every row and says nothing; anything else
            // is half of what a price means and goes in.
            if ($item->unit !== '' && $item->unit !== 'buc') {
                $row['um'] = (string) $item->unit;
            }

            if (! empty($item->code)) {
                $row['cod'] = (string) $item->code;
            }

            if (! empty($item->category)) {
                $row['categorie'] = (string) $item->category;
            }

            if (! empty($item->description)) {
                $row['descriere'] = self::shorten((string) $item->description, self::DESCRIPTION_CHARS);
            }

            $fits = $this->labels($id, CatalogItemTag::KIND_FITS);
            if ($fits !== []) {
                $row['pentru'] = $fits;
            }

            $excludes = $this->labels($id, CatalogItemTag::KIND_EXCLUDES);
            if ($excludes !== []) {
                $row['nu_daca'] = $excludes;
            }

            $components = $this->componentsOf((int) $id);
            if ($components !== []) {
                $contains = [];

                foreach ($components as $part) {
                    $contains[] = self::quantity($part['quantity']).' x '.$part['item']->name;
                }

                $row['contine'] = $contains;
            }

            // Only ids that are in this prompt. A cross-sell narrowed out of the
            // shown slice would be an id the model can read but not look up —
            // and the one thing this prompt cannot afford is an id with no row.
            $related = array_values(array_filter(
                $this->crossSells[$id] ?? [],
                fn (int $other): bool => isset($this->items[$other]),
            ));

            if ($related !== []) {
                $row['merge_cu'] = array_slice($related, 0, self::MAX_CROSS_SELLS);
            }

            // Present only when the firm tracks stock AND there is none. A null
            // stock is "not tracked", which is every service and plenty of
            // products, and saying so on every row would cost tokens to say
            // nothing.
            if ($item->stock !== null && (int) $item->stock <= 0) {
                $row['stoc'] = 'indisponibil';
            }

            $rows[] = $row;
        }

        $json = json_encode($rows, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return $json === false ? '[]' : $json;
    }

    /**
     * A string reduced to what two pieces of Romanian can be compared by:
     * lower case, no diacritics, no punctuation, single spaces.
     *
     * Both the comma-below forms (ș U+0219, ț U+021B) and the cedilla ones
     * (ş U+015F, ţ U+0163) are folded. They look identical in most UI fonts, a
     * Romanian keyboard can produce either, and a model emits both — sometimes
     * in the same sentence — so a comparison that knows only one of them fails
     * on text that looks correct to the eye.
     *
     * Kept here rather than in a helper of its own because the SQL pre-filter
     * and the excludes-tag net must fold identically; two copies is how they
     * quietly stop agreeing.
     */
    public static function fold(string $value): string
    {
        $value = str_replace(
            [
                'ă', 'â', 'î', "\u{0219}", "\u{021B}", "\u{015F}", "\u{0163}",
                'Ă', 'Â', 'Î', "\u{0218}", "\u{021A}", "\u{015E}", "\u{0162}",
            ],
            ['a', 'a', 'i', 's', 't', 's', 't', 'a', 'a', 'i', 's', 't', 's', 't'],
            $value,
        );

        $value = mb_strtolower($value);
        $value = (string) preg_replace('/[^\p{L}\p{N}]+/u', ' ', $value);

        return trim((string) preg_replace('/\s+/u', ' ', $value));
    }

    /**
     * Narrow an oversized catalogue to what the customer's words point at.
     *
     * Returns whether anything was applied: a message with no usable keyword
     * ("buna ziua, o oferta va rog") narrows nothing, and the prompt then takes
     * the first PROMPT_LIMIT rows rather than a slice chosen by an empty filter.
     *
     * The LIKE patterns cannot use an index — they are unanchored by necessity,
     * since "cim" has to find "ciment" — but they only ever run inside one
     * workspace, and only for a firm with more than NARROW_ABOVE active items.
     * Diacritics are handled by the column collation (utf8mb4_unicode_ci is
     * accent-insensitive, so "vara" matches "vară"); this is a recall
     * optimisation, never a correctness gate.
     *
     * @param  Builder<CatalogItem>  $query
     */
    private static function narrow(Builder $query, int $workspaceId, string $customerText): bool
    {
        $terms = self::terms($customerText);

        if ($terms === []) {
            return false;
        }

        $tagged = self::taggedItemIds($workspaceId, $terms);

        $query->where(function (Builder $q) use ($terms, $tagged): void {
            foreach ($terms as $term) {
                $like = Money::likeTerm($term);
                $q->orWhere('name', 'like', $like)
                    ->orWhere('code', 'like', $like)
                    ->orWhere('category', 'like', $like)
                    ->orWhere('description', 'like', $like);
            }

            if ($tagged !== []) {
                $q->orWhereIn('id', $tagged);
            }
        });

        return true;
    }

    /**
     * The words in the customer's message worth searching on: long enough to
     * mean something, not a greeting, longest first because the longest word is
     * the most specific one.
     *
     * @return list<string>
     */
    private static function terms(string $customerText): array
    {
        $words = explode(' ', self::fold($customerText));
        $terms = [];

        foreach ($words as $word) {
            if (mb_strlen($word) < self::MIN_TERM || in_array($word, self::STOPWORDS, true)) {
                continue;
            }

            $terms[$word] = $word;
        }

        $terms = array_values($terms);
        usort($terms, static fn (string $a, string $b): int => mb_strlen($b) <=> mb_strlen($a));

        return array_slice($terms, 0, self::MAX_TERMS);
    }

    /**
     * Items whose "pentru cine este" chips match one of the keywords.
     *
     * Only the positive kind: an item is not more relevant to a request because
     * one of its "do not propose when" conditions happens to share a word with
     * it — that is the opposite of relevant.
     *
     * @param  list<string>  $terms
     * @return list<int>
     */
    private static function taggedItemIds(int $workspaceId, array $terms): array
    {
        $ids = CatalogItemTag::query()
            ->where('workspace_id', $workspaceId)
            ->where('kind', CatalogItemTag::KIND_FITS)
            ->where(function (Builder $q) use ($terms): void {
                foreach ($terms as $term) {
                    $q->orWhere('label', 'like', Money::likeTerm($term));
                }
            })
            ->limit(self::PROMPT_LIMIT)
            ->pluck('catalog_item_id')
            ->all();

        return array_values(array_unique(array_map('intval', $ids)));
    }

    /**
     * Every tag of every item in play, grouped by item.
     *
     * @param  list<int>  $itemIds
     * @return array<int, list<CatalogItemTag>>
     */
    private static function tags(int $workspaceId, array $itemIds): array
    {
        if ($itemIds === []) {
            return [];
        }

        $tags = CatalogItemTag::query()
            ->where('workspace_id', $workspaceId)
            ->whereIn('catalog_item_id', $itemIds)
            ->orderBy('id')
            ->get(['id', 'catalog_item_id', 'kind', 'label']);

        $byItem = [];

        foreach ($tags as $tag) {
            $byItem[(int) $tag->catalog_item_id][] = $tag;
        }

        return $byItem;
    }

    /**
     * Bundle components (resolved to the items they point at) and cross-sell
     * ids, in one query over both kinds.
     *
     * A component is resolved through a second workspace-scoped query rather
     * than through the belongsTo, for the reason CatalogItemController states:
     * the foreign key alone carries no tenancy, and this row decides what goes
     * on a document with a price on it. Inactive components are loaded rather
     * than filtered out — the drafter has to be able to tell "this bundle is
     * incomplete" apart from "this bundle has no components", and the two are
     * indistinguishable once the row is missing.
     *
     * @param  list<int>  $itemIds
     * @param  array<int, CatalogItem>  $items
     * @return array{0: array<int, list<array{item: CatalogItem, quantity: string}>>, 1: array<int, list<int>>}
     */
    private static function links(int $workspaceId, array $itemIds, array $items): array
    {
        $links = CatalogItemLink::query()
            ->where('workspace_id', $workspaceId)
            ->whereIn('catalog_item_id', $itemIds)
            ->orderBy('position')
            ->get(['catalog_item_id', 'related_item_id', 'kind', 'quantity']);

        if ($links->isEmpty()) {
            return [[], []];
        }

        $componentIds = [];
        $crossSells = [];

        foreach ($links as $link) {
            if ($link->kind === CatalogItemLink::KIND_CROSS_SELL) {
                $crossSells[(int) $link->catalog_item_id][] = (int) $link->related_item_id;

                continue;
            }

            $componentIds[(int) $link->related_item_id] = (int) $link->related_item_id;
        }

        // Only the components not already in the prompt need fetching; the rest
        // are the same objects the model is looking at.
        $missing = array_values(array_diff(array_keys($componentIds), array_keys($items)));
        $resolved = $items;

        if ($missing !== []) {
            $extra = CatalogItem::query()
                ->where('workspace_id', $workspaceId)
                ->whereIn('id', $missing)
                ->get(['id', 'type', 'name', 'code', 'category', 'unit', 'price_cents', 'min_price_cents', 'stock', 'is_active']);

            foreach ($extra as $item) {
                $resolved[(int) $item->id] = $item;
            }
        }

        $components = [];

        foreach ($links as $link) {
            if ($link->kind !== CatalogItemLink::KIND_BUNDLE_COMPONENT) {
                continue;
            }

            $related = $resolved[(int) $link->related_item_id] ?? null;

            // Soft-deleted out of the catalogue since the bundle was composed.
            // The link survives; the component does not, and the drafter refuses
            // the whole bundle rather than quoting a hole in it.
            if ($related === null) {
                continue;
            }

            $components[(int) $link->catalog_item_id][] = [
                'item' => $related,
                'quantity' => (string) $link->quantity,
            ];
        }

        return [$components, $crossSells];
    }

    /**
     * One item's chips of a single kind, capped.
     *
     * @return list<string>
     */
    private function labels(int $id, string $kind): array
    {
        $labels = [];

        foreach ($this->tags[$id] ?? [] as $tag) {
            if ($tag->kind !== $kind) {
                continue;
            }

            $labels[] = (string) $tag->label;

            if (count($labels) >= self::MAX_TAGS) {
                break;
            }
        }

        return $labels;
    }

    /** Bani as lei, the way a person says the number out loud. */
    private static function lei(int $cents): float
    {
        return round($cents / 100, 2);
    }

    /** decimal(12,3) as a person writes it: "2.000" is two, not two thousand. */
    private static function quantity(string $quantity): string
    {
        if (! str_contains($quantity, '.')) {
            return $quantity;
        }

        return rtrim(rtrim($quantity, '0'), '.');
    }

    /** One paragraph of plain text, cut to a length the prompt can afford. */
    private static function shorten(string $value, int $limit): string
    {
        $value = trim((string) preg_replace('/\s+/u', ' ', $value));

        return mb_substr($value, 0, $limit);
    }
}
