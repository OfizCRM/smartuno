<?php

/*
|--------------------------------------------------------------------------
| Romanian validation messages
|--------------------------------------------------------------------------
|
| Deliberately not the whole of Laravel's validation file: only the rules the
| company forms can actually trigger, plus the attribute names those messages
| substitute. Anything missing falls through to the framework's English, which
| is where the rest of the app already sits — a wholesale translation of rules
| nothing in the product uses would be lines nobody can ever see are wrong.
|
| Messages written by our own rule objects are literal English strings and live
| in lang/ro.json instead; see App\Rules\ValidCui.
|
| Attribute names are bare nouns with no article, because the messages below
| supply one ("Câmpul :attribute ..."). Changing that convention here means
| re-reading every sentence in this file.
|
*/

return [

    'array' => 'Câmpul :attribute trebuie să fie o listă.',
    'between' => [
        'array' => 'Câmpul :attribute trebuie să aibă între :min și :max elemente.',
        'file' => 'Fișierul :attribute trebuie să aibă între :min și :max kiloocteți.',
        'numeric' => 'Câmpul :attribute trebuie să fie între :min și :max.',
        'string' => 'Câmpul :attribute trebuie să aibă între :min și :max caractere.',
    ],
    'boolean' => 'Câmpul :attribute trebuie să fie adevărat sau fals.',
    'date_format' => 'Câmpul :attribute nu respectă formatul :format.',
    'email' => 'Câmpul :attribute trebuie să fie o adresă de email validă.',
    'image' => 'Fișierul :attribute trebuie să fie o imagine.',
    'in' => 'Valoarea aleasă pentru :attribute nu este validă.',
    'integer' => 'Câmpul :attribute trebuie să fie un număr întreg.',
    'max' => [
        'array' => 'Câmpul :attribute nu poate avea mai mult de :max elemente.',
        'file' => 'Fișierul :attribute nu poate depăși :max kiloocteți.',
        'numeric' => 'Câmpul :attribute nu poate fi mai mare de :max.',
        'string' => 'Câmpul :attribute nu poate avea mai mult de :max caractere.',
    ],
    'mimes' => 'Fișierul :attribute trebuie să fie de tipul: :values.',
    'min' => [
        'array' => 'Câmpul :attribute trebuie să aibă cel puțin :min elemente.',
        'file' => 'Fișierul :attribute trebuie să aibă cel puțin :min kiloocteți.',
        'numeric' => 'Câmpul :attribute nu poate fi mai mic de :min.',
        'string' => 'Câmpul :attribute trebuie să aibă cel puțin :min caractere.',
    ],
    'numeric' => 'Câmpul :attribute trebuie să fie un număr.',
    'regex' => 'Formatul câmpului :attribute nu este valid.',
    'required' => 'Câmpul :attribute este obligatoriu.',
    'size' => [
        'array' => 'Câmpul :attribute trebuie să aibă :size elemente.',
        'file' => 'Fișierul :attribute trebuie să aibă :size kiloocteți.',
        'numeric' => 'Câmpul :attribute trebuie să fie :size.',
        'string' => 'Câmpul :attribute trebuie să aibă exact :size caractere.',
    ],
    'string' => 'Câmpul :attribute trebuie să fie text.',
    'timezone' => 'Câmpul :attribute trebuie să fie un fus orar valid.',
    'url' => 'Câmpul :attribute trebuie să fie o adresă web validă.',

    'attributes' => [
        'client_name' => 'nume firmă',
        'client_email' => 'email',
        'client_phone' => 'telefon',
        'legal_name' => 'denumire juridică',
        'industry' => 'domeniu de activitate',
        'industry_other' => 'domeniu de activitate',
        'company_size' => 'număr de angajați',
        'short_description' => 'descriere',
        'cui' => 'cod fiscal (CUI)',
        'vat_status' => 'statut TVA',
        'vat_rate' => 'cotă TVA',
        'trade_register_no' => 'număr Registrul Comerțului',
        'share_capital' => 'capital social',
        'iban' => 'IBAN',
        'bank_name' => 'bancă',
        'mobile_phone' => 'telefon mobil',
        'website' => 'site web',
        'contact_person_name' => 'persoană de contact',
        'contact_person_role' => 'funcție persoană de contact',
        'address_street' => 'stradă și număr',
        'address_city' => 'localitate',
        'address_county' => 'județ',
        'address_postcode' => 'cod poștal',
        'address_country' => 'țară',
        'timezone' => 'fus orar',
        'delivery_zones' => 'zone de livrare',
        'delivery_time' => 'termen de livrare',
        'facebook_url' => 'pagină Facebook',
        'instagram_url' => 'pagină Instagram',
        'google_maps_url' => 'link Google Maps',
        'online_shop_url' => 'adresă magazin online',
        'logo' => 'logo',
        'hours' => 'program de lucru',
        'hours.*.opens_at' => 'oră de deschidere',
        'hours.*.closes_at' => 'oră de închidere',
    ],

];
