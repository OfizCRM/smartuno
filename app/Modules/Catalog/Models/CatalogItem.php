<?php

namespace App\Modules\Catalog\Models;

use App\Models\User;
use App\Models\Workspace;
use App\Modules\Catalog\Support\Money;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

/**
 * One line in the firm's own catalogue — a product it sells, or a service it
 * performs. Typed by hand, and never touched by the store sync.
 *
 * @property int $id
 * @property string $uuid
 * @property int $workspace_id
 * @property string $type
 * @property string $name
 * @property string|null $code
 * @property string|null $category
 * @property string $unit
 * @property int $price_cents
 * @property int|null $min_price_cents
 * @property int|null $stock
 * @property int|null $low_stock_threshold
 * @property string|null $description
 * @property string|null $description_source
 * @property string|null $image_path
 * @property int|null $ecommerce_product_id
 * @property bool $is_active
 * @property int|null $created_by
 */
class CatalogItem extends Model
{
    use SoftDeletes;

    /**
     * The kinds a line can be. 'bundle' is reserved for stage 3; nothing
     * composes one yet, so nothing writes it.
     *
     * @var list<string>
     */
    public const TYPES = ['product', 'service', 'bundle'];

    protected $fillable = [
        'workspace_id', 'type', 'name', 'code', 'category', 'unit',
        'price_cents', 'min_price_cents', 'stock', 'low_stock_threshold',
        'description', 'description_source', 'image_path',
        'ecommerce_product_id', 'is_active', 'created_by',
    ];

    protected $casts = [
        'price_cents' => 'integer',
        'min_price_cents' => 'integer',
        'stock' => 'integer',
        'low_stock_threshold' => 'integer',
        'is_active' => 'boolean',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $model) {
            if (empty($model->uuid)) {
                $model->uuid = (string) Str::uuid();
            }
        });
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    /** @return BelongsTo<Workspace, $this> */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * What the firm wants the agent to know: the green "pentru cine este" and
     * red "nu îl propune dacă" chips, both kinds together.
     *
     * Ordered by id, which is the order they were typed. Not by kind — the
     * panel splits them itself, and 'excludes' sorts before 'fits', so ordering
     * by kind would put the red list first.
     *
     * @return HasMany<CatalogItemTag, $this>
     */
    public function tags(): HasMany
    {
        return $this->hasMany(CatalogItemTag::class)->orderBy('id');
    }

    /**
     * Everything this item points at — components and cross-sells together.
     *
     * Ordered by kind and then position: position starts again at 0 for each
     * kind, so position alone would interleave the two lists in whatever order
     * the database felt like.
     *
     * @return HasMany<CatalogItemLink, $this>
     */
    public function links(): HasMany
    {
        return $this->hasMany(CatalogItemLink::class)
            ->orderBy('kind')
            ->orderBy('position');
    }

    /**
     * What this bundle is made of, in the order it is put on an offer.
     *
     * Empty for anything that is not a bundle. Load relatedItem with it —
     * nothing here carries a name or a price, and a ten-line bundle rendered
     * without the eager load is eleven queries. A component that has been
     * soft-deleted from the catalogue comes back with a null relatedItem; the
     * link survives, and the caller decides what to show.
     *
     * @return HasMany<CatalogItemLink, $this>
     */
    public function components(): HasMany
    {
        return $this->hasMany(CatalogItemLink::class)
            ->where('kind', CatalogItemLink::KIND_BUNDLE_COMPONENT)
            ->orderBy('position');
    }

    /**
     * "Merge bine împreună cu" — the suggestions, in the order the firm
     * arranged them. Their quantity is meaningless and is not read.
     *
     * @return HasMany<CatalogItemLink, $this>
     */
    public function crossSells(): HasMany
    {
        return $this->hasMany(CatalogItemLink::class)
            ->where('kind', CatalogItemLink::KIND_CROSS_SELL)
            ->orderBy('position');
    }

    /**
     * Narrow the list the way the screen does: a type tab, a category, a search
     * term, a stock warning.
     *
     * Does NOT scope the workspace. Tenancy is applied by the caller, on every
     * query, before this is reached.
     *
     * @param  Builder<self>  $query
     * @param  array<string, mixed>  $filters
     * @return Builder<self>
     */
    public function scopeNarrowed(Builder $query, array $filters): Builder
    {
        if (! empty($filters['type']) && in_array($filters['type'], self::TYPES, true)) {
            $query->where('type', $filters['type']);
        }

        if (! empty($filters['category'])) {
            $query->where('category', $filters['category']);
        }

        if (! empty($filters['search'])) {
            // LIKE and not FULLTEXT: a person typing "cim" expects the cement,
            // and a word index would find nothing until the whole word is typed.
            $term = Money::likeTerm((string) $filters['search']);
            $query->where(function (Builder $q) use ($term) {
                $q->where('name', 'like', $term)
                    ->orWhere('code', 'like', $term)
                    ->orWhere('category', 'like', $term);
            });
        }

        // A null stock means "not tracked" — every service, and plenty of
        // products. Neither warning applies to those.
        if (($filters['stock'] ?? null) === 'out') {
            $query->whereNotNull('stock')->where('stock', '<=', 0);
        } elseif (($filters['stock'] ?? null) === 'low') {
            // At or under the threshold the firm set, out-of-stock included:
            // "stoc redus" is one list to work through, not two.
            $query->whereNotNull('stock')
                ->whereNotNull('low_stock_threshold')
                ->whereColumn('stock', '<=', 'low_stock_threshold');
        }

        return $query;
    }
}
