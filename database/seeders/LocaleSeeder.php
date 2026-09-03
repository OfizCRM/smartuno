<?php

namespace Database\Seeders;

use App\Models\Locale;
use Illuminate\Database\Seeder;

class LocaleSeeder extends Seeder
{
    /**
     * Seed English (default) and Romanian. Idempotent: updateOrCreate by code.
     */
    public function run(): void
    {
        $locales = [
            [
                'code' => 'en',
                'name' => 'English',
                'native_name' => 'English',
                'flag' => null,
                'enabled' => true,
                'is_default' => true,
                'is_rtl' => false,
                'sort_order' => 1,
            ],
            [
                'code' => 'ro',
                'name' => 'Romanian',
                'native_name' => 'Română',
                'flag' => null,
                'enabled' => true,
                'is_default' => false,
                'is_rtl' => false,
                'sort_order' => 2,
            ],
        ];

        Locale::where('is_default', true)->update(['is_default' => false]);

        foreach ($locales as $row) {
            Locale::updateOrCreate(
                ['code' => $row['code']],
                $row
            );
        }

        Locale::whereNotIn('code', ['en', 'ro'])->delete();
    }
}
