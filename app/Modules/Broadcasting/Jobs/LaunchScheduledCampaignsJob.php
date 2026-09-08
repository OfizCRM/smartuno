<?php

namespace App\Modules\Broadcasting\Jobs;

use App\Modules\Broadcasting\Models\Campaign;
use App\Support\Entitlement;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class LaunchScheduledCampaignsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public function handle(): void
    {
        // Belt and braces with the Queue::before hook in AppServiceProvider:
        // the scheduler dispatches this job so the hook covers production, but
        // a direct ->handle() (tests, `php artisan schedule:run` in sync mode)
        // never fires it, and this tick must not answer from the last one.
        Entitlement::forget();

        $due = Campaign::where('status', 'queued')
            ->whereNotNull('schedule_at')
            ->where('schedule_at', '<=', now())
            ->get();

        /** @var array<int, int> $blocked workspace_id => client_id */
        $blocked = [];

        /** @var array<int, int> $held workspace_id => campaigns held */
        $held = [];

        // Grouped by workspace so a tenant with twenty due campaigns costs one
        // entitlement resolution, not twenty. Freshness across ticks comes from
        // the forget() above, not from re-querying inside the loop.
        foreach ($due->groupBy('workspace_id') as $workspaceId => $campaigns) {
            $workspaceId = (int) $workspaceId;
            $client = Entitlement::clientForWorkspace($workspaceId);

            if (Entitlement::state($client) === Entitlement::READONLY) {
                // The campaigns stay 'queued' and otherwise untouched: the first
                // tick after the client pays launches them. Nothing here marks
                // them failed, cancelled or sent.
                $blocked[$workspaceId] = (int) $client?->getKey();
                $held[$workspaceId] = $campaigns->count();

                continue;
            }

            $campaigns->each(fn (Campaign $c) => LaunchCampaignJob::dispatch($c->id)->onQueue('broadcast'));
        }

        $this->logHeld($held, $blocked);
    }

    /**
     * This job runs every minute, so a line per held campaign would be 1440 a
     * day per campaign and the log would be unreadable. One aggregated line per
     * run instead, carrying only clients not already reported in the last hour:
     * a newly blocked client appears immediately, an existing one stays visible
     * hourly. Ids and counts only — never a client name or a campaign's contents.
     *
     * @param  array<int, int>  $held  workspace_id => campaigns held
     * @param  array<int, int>  $blocked  workspace_id => client_id
     */
    private function logHeld(array $held, array $blocked): void
    {
        /** @var array<int, array{client_id: int, workspace_ids: array<int, int>, campaigns: int}> $byClient */
        $byClient = [];

        foreach ($held as $workspaceId => $count) {
            $clientId = $blocked[$workspaceId] ?? 0;

            $byClient[$clientId]['client_id'] = $clientId;
            $byClient[$clientId]['workspace_ids'][] = $workspaceId;
            $byClient[$clientId]['campaigns'] = ($byClient[$clientId]['campaigns'] ?? 0) + $count;
        }

        $report = array_values(array_filter(
            $byClient,
            fn (array $row): bool => Cache::add("entitlement_block_log:campaigns:{$row['client_id']}", 1, 3600)
        ));

        if ($report === []) {
            return;
        }

        Log::warning('Scheduled campaigns held: client entitlement is read-only.', [
            'held' => $report,
        ]);
    }
}
