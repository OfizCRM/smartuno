<?php

namespace App\Modules\Catalog;

use Illuminate\Support\ServiceProvider;

/**
 * The catalogue a firm writes by hand: the products it sells, the services it
 * performs, and the prices it quotes for them.
 *
 * Deliberately separate from app/Modules/Ecommerce: ecommerce_products is a
 * read-only mirror of a connected shop, whose every sync overwrites name, sku,
 * price and status and hard-deletes rows the shop stopped returning. A dentist
 * and a plumber have no shop to mirror, and their price list must survive a
 * sync it was never part of.
 *
 * Auto-registered by ModuleServiceProvider, like every other module. Nothing is
 * added to bootstrap/app.php or config/app.php.
 */
class CatalogServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/database/migrations');
        $this->loadRoutesFrom(__DIR__.'/routes/web.php');
    }
}
