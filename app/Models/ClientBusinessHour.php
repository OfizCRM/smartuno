<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One opening interval, not one day — a day split across lunch is two rows.
 * day_of_week is 1=Monday .. 7=Sunday (ISO-8601).
 */
class ClientBusinessHour extends Model
{
    protected $fillable = [
        'client_id',
        'day_of_week',
        'opens_at',
        'closes_at',
        'is_closed',
        'sort_order',
    ];

    protected $casts = [
        'is_closed' => 'bool',
    ];

    /** @return BelongsTo<Client, $this> */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }
}
