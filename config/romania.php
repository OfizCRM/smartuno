<?php

/*
|--------------------------------------------------------------------------
| Romanian business reference data
|--------------------------------------------------------------------------
|
| Everything here is legislation, not preference, so it changes on dates we
| do not control. The `vat_rates` list is deliberately dated and ordered
| newest-first: when the standard rate moved from 19% to 21% on 2025-08-01
| (and the 9%/5% reduced rates merged into 11% on the same day), the whole
| change should be one new entry at the top of that array — not a hunt
| through controllers, blades and invoice templates for a hardcoded 19.
|
| Read it through App\Support\Romania, never by inlining config() calls in a
| controller: the "which rate applied on the invoice date" walk belongs in
| one place, because a reissued invoice must carry the rate valid *then*.
|
*/

return [

    /*
    |--------------------------------------------------------------------------
    | Counties (judete)
    |--------------------------------------------------------------------------
    | The 41 counties plus Bucuresti, keyed by the official 2-letter
    | abbreviation used on vehicle plates and fiscal documents. Bucuresti is a
    | single letter ("B") — anything validating these codes must not assume a
    | fixed length of two.
    */
    'counties' => [
        'AB' => 'Alba',
        'AR' => 'Arad',
        'AG' => 'Argeș',
        'BC' => 'Bacău',
        'BH' => 'Bihor',
        'BN' => 'Bistrița-Năsăud',
        'BT' => 'Botoșani',
        'BV' => 'Brașov',
        'BR' => 'Brăila',
        'B' => 'București',
        'BZ' => 'Buzău',
        'CS' => 'Caraș-Severin',
        'CL' => 'Călărași',
        'CJ' => 'Cluj',
        'CT' => 'Constanța',
        'CV' => 'Covasna',
        'DB' => 'Dâmbovița',
        'DJ' => 'Dolj',
        'GL' => 'Galați',
        'GR' => 'Giurgiu',
        'GJ' => 'Gorj',
        'HR' => 'Harghita',
        'HD' => 'Hunedoara',
        'IL' => 'Ialomița',
        'IS' => 'Iași',
        'IF' => 'Ilfov',
        'MM' => 'Maramureș',
        'MH' => 'Mehedinți',
        'MS' => 'Mureș',
        'NT' => 'Neamț',
        'OT' => 'Olt',
        'PH' => 'Prahova',
        'SM' => 'Satu Mare',
        'SJ' => 'Sălaj',
        'SB' => 'Sibiu',
        'SV' => 'Suceava',
        'TR' => 'Teleorman',
        'TM' => 'Timiș',
        'TL' => 'Tulcea',
        'VS' => 'Vaslui',
        'VL' => 'Vâlcea',
        'VN' => 'Vrancea',
    ],

    /*
    |--------------------------------------------------------------------------
    | VAT rates, by the date they came into force
    |--------------------------------------------------------------------------
    | Newest first — the lookup walks down and takes the first entry whose
    | `from` is on or before the date being asked about. Add a new rate change
    | at the TOP; never edit a historical entry, because invoices already
    | issued under it must keep reproducing the same numbers.
    */
    'vat_rates' => [
        [
            'from' => '2025-08-01',
            'standard' => 21.0,
            'reduced' => [11.0],
        ],
        [
            'from' => '2017-01-01',
            'standard' => 19.0,
            'reduced' => [9.0, 5.0],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | VAT status
    |--------------------------------------------------------------------------
    | A three-state gate, not a percentage. It decides what legal mention the
    | invoice carries, which is why it is stored separately from the rate:
    |   none          — neplatitor de TVA (under the 395.000 lei threshold);
    |                   the invoice carries an exemption mention and no VAT line
    |   standard      — charges VAT normally
    |   on_collection — TVA la incasare; a mandatory mention on the invoice
    */
    'vat_statuses' => ['none', 'standard', 'on_collection'],

    /*
    |--------------------------------------------------------------------------
    | Company size buckets
    |--------------------------------------------------------------------------
    | Stored as the literal bucket string, not an enum id, so a CSV export is
    | readable without a lookup. Our customer sits in the first four.
    */
    'company_sizes' => ['1', '2-10', '11-30', '31-50', '50+'],

    /*
    |--------------------------------------------------------------------------
    | Industries
    |--------------------------------------------------------------------------
    | A curated shortlist of who we actually sell to — deliberately NOT the
    | ~600-entry CAEN nomenclature, which would be a scrolling nightmare in a
    | settings form and tells us nothing we would act on. Anything outside the
    | list falls back to 'other' plus the free-text `industry_other` column.
    |
    | Slugs only. Labels are translated on the frontend under settings.company.
    */
    'industries' => [
        'ecommerce',
        'retail',
        'dental_clinic',
        'medical_clinic',
        'beauty_salon',
        'real_estate',
        'car_dealer',
        'restaurant',
        'hotel',
        'plumbing_hvac',
        'electrical',
        'furniture_fitting',
        'construction',
        'professional_services',
        'education',
        'auto_service',
        'other',
    ],

];
