<?php

namespace App\Modules\Offers\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * The per-workspace counter behind "OF-0142".
 *
 * Read and written only by OfferNumberAllocator, which takes the row with
 * lockForUpdate() inside a transaction. Nothing else may increment last_number.
 *
 * @property int $id
 * @property int $workspace_id
 * @property string $prefix
 * @property int $year
 * @property int $last_number
 */
class OfferSeries extends Model
{
    /**
     * Set explicitly: "series" is its own plural, so the name Eloquent would
     * infer is right only by luck, and the table this points at is not something
     * to leave to an inflection rule.
     */
    protected $table = 'offer_series';

    protected $fillable = [
        'workspace_id', 'prefix', 'year', 'last_number',
    ];

    protected $casts = [
        'year' => 'integer',
        'last_number' => 'integer',
    ];
}
