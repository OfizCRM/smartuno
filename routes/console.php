<?php

use App\Http\Controllers\Admin\CronSetupController;
use App\Modules\Broadcasting\Jobs\LaunchScheduledCampaignsJob;
use App\Modules\Broadcasting\Models\UsageMeter;
use App\Modules\Social\Jobs\DispatchScheduledPostsJob;
use App\Modules\Social\Jobs\RefreshSocialTokensJob;
use App\Modules\Whatsapp\Jobs\TemplateSyncJob;
use App\Modules\Whatsapp\Models\WhatsappBusinessAccount;
use App\Services\WebhookIdempotencyService;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Heartbeat: records the last time the scheduler ran so the admin "Cron Setup"
// guide can confirm the server's cron entry is actually firing.
Schedule::call(function () {
    Cache::put(CronSetupController::HEARTBEAT_KEY, now()->toIso8601String(), now()->addDay());
})->everyMinute()->name('scheduler-heartbeat');

// ─── Marketing Suite Scheduled Tasks ────────────────────────────────────────

// Dispatch any campaigns scheduled for now
Schedule::job(new LaunchScheduledCampaignsJob, 'broadcast')
    ->everyMinute()
    ->name('launch-scheduled-campaigns')
    ->withoutOverlapping();

// Sync WhatsApp templates from Meta (once per day)
Schedule::call(function () {
    WhatsappBusinessAccount::all()->each(function ($waba) {
        TemplateSyncJob::dispatch($waba->id)->onQueue('whatsapp');
    });
})->daily()->name('sync-whatsapp-templates');

// Dispatch scheduled social posts (every minute)
Schedule::job(new DispatchScheduledPostsJob, 'social')
    ->everyMinute()
    ->name('dispatch-social-posts')
    ->withoutOverlapping();

// Refresh expiring social OAuth tokens. Twitter/Google access tokens live only
// 1-2 hours, so this must run frequently — a daily pass leaves them expired
// (and the UI showing "Token expired") most of the day.
Schedule::job(new RefreshSocialTokensJob, 'social')
    ->everyFifteenMinutes()
    ->name('refresh-social-tokens')
    ->withoutOverlapping();

// Reset monthly usage meters on the 1st of each month
Schedule::call(function () {
    // Meters older than 2 months are pruned; current month is always kept
    UsageMeter::where('period', '<', (int) now()->subMonths(2)->format('Ym'))->delete();
})->monthlyOn(1, '00:05')->name('reset-usage-meters');

// Read new mail for every connected mailbox. Runs every minute and decides per
// mailbox, because each tenant picks their own interval — onOneServer so two app
// servers cannot read the same mailbox twice and file every message twice.
Schedule::command('email:poll-mailboxes')
    ->everyMinute()
    ->name('email-poll-mailboxes')
    ->withoutOverlapping()
    ->onOneServer();

// Warn about documents that are about to expire. Daily and early, so a contract
// that renews itself tacitly is noticed before the day it does.
Schedule::command('documents:remind')
    ->dailyAt('07:10')
    ->name('documents-remind')
    ->withoutOverlapping()
    ->onOneServer();

// Empty the document bin. A deleted document stops counting against the storage
// allowance immediately but its file is kept, so without this the disk grows for
// ever while the number on the screen says otherwise.
Schedule::command('documents:purge')
    ->dailyAt('03:20')
    ->name('documents-purge')
    ->withoutOverlapping()
    ->onOneServer();

// Cancel automation runs parked on an "Ask question" node whose contact never
// replied, so abandoned conversations do not accumulate as "waiting" forever.
Schedule::command('automation:prune-stale-runs')
    ->daily()
    ->name('automation-prune-stale-runs')
    ->withoutOverlapping()
    ->onOneServer();

// Prune inbound webhook idempotency records older than 30 days
Schedule::call(function () {
    app(WebhookIdempotencyService::class)->prune(30);
})->weekly()->name('prune-inbound-webhook-events');

// Sync subscription statuses with payment gateways (hourly)
Schedule::command('billing:sync')
    ->hourly()
    ->name('billing-sync')
    ->withoutOverlapping()
    ->onOneServer();

// Expire trials that have passed their trial_ends_at and not yet converted
Schedule::command('billing:expire-trials')
    ->hourly()
    ->name('billing-expire-trials')
    ->withoutOverlapping()
    ->onOneServer();

// Notify users whose trial ends in 3 days (daily at 09:00)
Schedule::command('notifications:trial-ending --days=3')
    ->dailyAt('09:00')
    ->name('notify-trial-ending-3d')
    ->withoutOverlapping()
    ->onOneServer();

// Send weekly performance digest to all workspace owners (Monday 09:00)
Schedule::command('reports:weekly-digest')
    ->mondays()
    ->at('09:00')
    ->name('weekly-digest-emails')
    ->withoutOverlapping()
    ->onOneServer();
