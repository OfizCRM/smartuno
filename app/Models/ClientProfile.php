<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ClientProfile extends Model
{
    protected $fillable = [
        'client_id',
        'legal_name',
        'industry',
        'industry_other',
        'company_size',
        'short_description',
        'cui',
        'vat_status',
        'vat_rate',
        'trade_register_no',
        'share_capital',
        'iban',
        'bank_name',
        'mobile_phone',
        'website',
        'contact_person_name',
        'contact_person_role',
        'address_street',
        'address_city',
        'address_county',
        'address_postcode',
        'address_country',
        'timezone',
        'delivery_zones',
        'delivery_time',
        'facebook_url',
        'instagram_url',
        'google_maps_url',
        'online_shop_url',
    ];

    protected $casts = [
        'vat_rate' => 'decimal:2',
        'share_capital' => 'decimal:2',
    ];

    /** @return BelongsTo<Client, $this> */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }
}
