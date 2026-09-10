<?php

namespace App\Modules\Offers\Models;

use App\Modules\Catalog\Models\CatalogItem;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One line on an offer.
 *
 * name, unit and unit_price_cents are a SNAPSHOT of the catalogue row as it was
 * when the line was added, and are never refreshed from it. A price change must
 * not alter an offer that has already gone out.
 *
 * @property int $id
 * @property int $workspace_id
 * @property int $offer_id
 * @property int|null $catalog_item_id
 * @property string $name
 * @property string $unit
 * @property string $quantity
 * @property int $unit_price_cents
 * @property int $line_total_cents
 * @property int $position
 * @property string $added_by
 * @property int|null $bundle_parent_id
 */
class OfferItem extends Model
{
    /**
     * Every writable column, including bundle_parent_id, which nothing writes
     * until stage 3. An omission here silently drops the value on save.
     */
    protected $fillable = [
        'workspace_id', 'offer_id', 'catalog_item_id', 'name', 'unit',
        'quantity', 'unit_price_cents', 'line_total_cents', 'position',
        'added_by', 'bundle_parent_id',
    ];

    /**
     * quantity is deliberately NOT cast. MySQL returns decimal(12,3) as a
     * fixed-scale string ("2.250"), which is exactly what was stored; a float
     * cast would round-trip it through binary and hand back a quantity that
     * drifts, and a drifting quantity is a wrong total the customer can see.
     */
    protected $casts = [
        'unit_price_cents' => 'integer',
        'line_total_cents' => 'integer',
        'position' => 'integer',
    ];

    /** @return BelongsTo<Offer, $this> */
    public function offer(): BelongsTo
    {
        return $this->belongsTo(Offer::class);
    }

    /**
     * Where the line came from — a back-reference only. Nothing on the line is
     * read from here; see the snapshot note above.
     *
     * @return BelongsTo<CatalogItem, $this>
     */
    public function catalogItem(): BelongsTo
    {
        return $this->belongsTo(CatalogItem::class);
    }
}
