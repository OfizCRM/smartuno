<?php

namespace App\Modules\Offers;

use Illuminate\Support\ServiceProvider;

/**
 * Offers: the quote a firm sends instead of writing one in Word.
 *
 * Stage 2a is the offer a person builds by hand from the catalogue — line
 * items, live totals, VAT, an offer number, a validity date and a PDF. Sending
 * it on a channel, and everything the agent might draft, are later stages; the
 * columns they need are already in the migrations so those stages do not have
 * to alter a table an existing offer is stored in.
 *
 * Money is an integer number of bani in every column and every prop.
 *
 * Auto-registered by ModuleServiceProvider, which discovers
 * app/Modules/{Name}/{Name}ServiceProvider.php. Nothing is added to
 * bootstrap/app.php or config/app.php.
 */
class OffersServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/database/migrations');
        $this->loadRoutesFrom(__DIR__.'/routes/web.php');
    }
}
