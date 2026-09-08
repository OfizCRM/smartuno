<?php

namespace App\Modules\Email;

use App\Modules\Email\Services\EmailDriver;
use App\Modules\Shared\Services\ChannelManager;
use Illuminate\Support\ServiceProvider;

/**
 * The email channel: a mailbox the tenant owns, read over IMAP and replied to
 * over its own SMTP, so the customer sees one ordinary thread with the firm.
 *
 * Auto-registered by ModuleServiceProvider, like every other module.
 */
class EmailServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->loadRoutesFrom(__DIR__.'/routes/web.php');

        // Without this, ChannelManager::driver('email') throws and the inbox
        // refuses to send on an email conversation.
        $this->app->make(ChannelManager::class)->register('email', EmailDriver::class);

        if ($this->app->runningInConsole()) {
            $this->commands([Console\Commands\PollMailboxesCommand::class]);
        }
    }
}
