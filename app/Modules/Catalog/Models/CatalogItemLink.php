<?php

namespace App\Modules\Catalog\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One catalogue item pointing at another: a component of a bundle, or a
 * "merge bine împreună cu" suggestion. `kind` says which.
 *
 * A bundle is a CatalogItem with type = 'bundle' whose components are its
 * bundle_component links — not a separate kind of record. See the migration.
 *
 * Neither end is scoped by the database. A related_item_id arriving from the
 * browser must be resolved inside the caller's workspace and REFUSED when it is
 * not found, never stored on trust.
 *
 * @property int $id
 * @property int $workspace_id
 * @property int $catalog_item_id
 * @property int $related_item_id
 * @property string $kind
 * @property string $quantity
 * @property int $position
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class CatalogItemLink extends Model
{
    /**
     * What the edge means.
     *
     * @var list<string>
     */
    public const KINDS = ['bundle_component', 'cross_sell'];

    /** The other item is part of this bundle; `quantity` says how many. */
    public const KIND_BUNDLE_COMPONENT = 'bundle_component';

    /** The other item is worth suggesting alongside. `quantity` is meaningless. */
    public const KIND_CROSS_SELL = 'cross_sell';

    /**
     * Every writable column. An omission here silently drops the value on save,
     * which is a bug that looks like the form not working.
     */
    protected $fillable = [
        'workspace_id', 'catalog_item_id', 'related_item_id', 'kind',
        'quantity', 'position',
    ];

    /**
     * quantity is deliberately NOT cast, exactly as on OfferItem. MySQL returns
     * decimal(12,3) as a fixed-scale string ("2.250"), which is what was stored;
     * a float cast would round-trip it through binary and hand back a quantity
     * that drifts, and these quantities multiply into an offer total the
     * customer can see.
     */
    protected $casts = [
        'position' => 'integer',
    ];

    /**
     * The owner — the bundle, or the item the suggestion hangs off.
     *
     * @return BelongsTo<CatalogItem, $this>
     */
    public function item(): BelongsTo
    {
        return $this->belongsTo(CatalogItem::class, 'catalog_item_id');
    }

    /**
     * The far end: the component, or the item being suggested.
     *
     * Eager-load this wherever a link is rendered — the composition panel and
     * the cross-sell chips both need the other item's name, code and price, and
     * loading them one row at a time is how a ten-line bundle becomes eleven
     * queries.
     *
     * @return BelongsTo<CatalogItem, $this>
     */
    public function relatedItem(): BelongsTo
    {
        return $this->belongsTo(CatalogItem::class, 'related_item_id');
    }
}
