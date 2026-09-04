<?php

namespace Database\Seeders;

use App\Models\Currency;
use Illuminate\Database\Seeder;

class CurrencySeeder extends Seeder
{
    public function run(): void
    {
        // exchange_rate = USD per 1 unit of this currency. See CurrencyService::convert().
        $currencies = [
            // RON is the home currency of the market this is sold into, and the
            // settings page defaults to it — without a row here the Select has no
            // matching option and Rule::in() rejects the save.
            ['code' => 'RON', 'symbol' => 'lei', 'decimals' => 2, 'exchange_rate' => 0.22, 'is_default' => false, 'enabled' => true],
            ['code' => 'USD', 'symbol' => '$', 'decimals' => 2, 'exchange_rate' => 1, 'is_default' => true, 'enabled' => true],
            ['code' => 'EUR', 'symbol' => '€', 'decimals' => 2, 'exchange_rate' => 1.087, 'is_default' => false, 'enabled' => true],
            ['code' => 'GBP', 'symbol' => '£', 'decimals' => 2, 'exchange_rate' => 1.266, 'is_default' => false, 'enabled' => true],
            ['code' => 'BDT', 'symbol' => '৳', 'decimals' => 2, 'exchange_rate' => 0.0091, 'is_default' => false, 'enabled' => true],
        ];

        foreach ($currencies as $row) {
            Currency::updateOrCreate(
                ['code' => $row['code']],
                $row
            );
        }
    }
}
