<?php

namespace App\Modules\Documents;

use Illuminate\Support\ServiceProvider;

/**
 * The document library: contracts, offers, invoices and price lists in one
 * place, tied to the client they are about and counted against the plan's
 * storage allowance.
 *
 * Auto-registered by ModuleServiceProvider, like every other module.
 */
class DocumentsServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/database/migrations');
        $this->loadRoutesFrom(__DIR__.'/routes/web.php');

        if ($this->app->runningInConsole()) {
            $this->commands([
                Console\Commands\PurgeDeletedDocumentsCommand::class,
                Console\Commands\SendDocumentRemindersCommand::class,
            ]);
        }
    }
}
