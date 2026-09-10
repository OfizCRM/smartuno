<?php

namespace App\Modules\Catalog\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One short condition under which a catalogue item is, or is not, the right
 * answer — the green "Pentru cine este" and red "Nu îl propune dacă" chips.
 *
 * The two colours are one concept with opposite sign; `kind` carries the sign.
 * See the migration for why that is one table and not two.
 *
 * @property int $id
 * @property int $workspace_id
 * @property int $catalog_item_id
 * @property string $kind
 * @property string $label
 * @property string $source
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class CatalogItemTag extends Model
{
    /**
     * The sign a tag carries. 'fits' is shown in brand green, 'excludes' in
     * coral — the colours are the whole point of the panel, so nothing else may
     * be stored here.
     *
     * @var list<string>
     */
    public const KINDS = ['fits', 'excludes'];

    public const KIND_FITS = 'fits';

    public const KIND_EXCLUDES = 'excludes';

    /**
     * Where the words came from. 'ai' means the agent drafted them and a person
     * pressed confirm; nothing is written unconfirmed.
     *
     * @var list<string>
     */
    public const SOURCES = ['human', 'ai'];

    /**
     * Every writable column. An omission here silently drops the value on save,
     * which is a bug that looks like the form not working.
     */
    protected $fillable = [
        'workspace_id', 'catalog_item_id', 'kind', 'label', 'source',
    ];

    /**
     * The item these words describe.
     *
     * A back-reference for the rare read that starts from a tag. The panel goes
     * the other way, through CatalogItem::tags().
     *
     * @return BelongsTo<CatalogItem, $this>
     */
    public function item(): BelongsTo
    {
        return $this->belongsTo(CatalogItem::class, 'catalog_item_id');
    }
}
