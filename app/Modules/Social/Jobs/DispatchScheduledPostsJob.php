<?php

namespace App\Modules\Social\Jobs;

use App\Modules\Social\Models\SocialPost;
use App\Support\Entitlement;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class DispatchScheduledPostsJob implements ShouldQueue
{
    use Queueable;

    public function handle(): void
    {
        // Belt and braces with the Queue::before hook in AppServiceProvider:
        // the scheduler dispatches this job so the hook covers production, but
        // a direct ->handle() (tests, `php artisan schedule:run` in sync mode)
        // never fires it, and this tick must not answer from the last one.
        Entitlement::forget();

        $due = SocialPost::where('status', 'scheduled')
            ->where('scheduled_at', '<=', now())
            ->get(['id', 'workspace_id']);

        // Read before the dispatch loop rather than after it, as it used to be:
        // a post flipped a few lines below carries updated_at = now and so can
        // never satisfy the 30-minute staleness test in the same run anyway.
        $stuck = SocialPost::where('status', 'publishing')
            ->where('updated_at', '<', now()->subMinutes(30))
            ->get(['id', 'workspace_id', 'updated_at']);

        $blocked = $this->blockedWorkspaces($due->concat($stuck)->pluck('workspace_id'));

        $held = $this->dispatchDue($due, $blocked);

        foreach ($this->requeueStuckPosts($stuck, $blocked) as $workspaceId => $count) {
            $held[$workspaceId] = ($held[$workspaceId] ?? 0) + $count;
        }

        $this->logHeld($held, $blocked);
    }

    /**
     * Which of these workspaces belong to a read-only client, as
     * workspace_id => client_id. Resolved once per distinct workspace; the
     * forget() at the top of handle() is what makes each tick ask again, so a
     * client who has just paid is never answered from a stale 'readonly'.
     *
     * @param  Collection<int, mixed>  $workspaceIds
     * @return array<int, int>
     */
    private function blockedWorkspaces(Collection $workspaceIds): array
    {
        $blocked = [];

        foreach ($workspaceIds->map(fn ($id): int => (int) $id)->unique() as $workspaceId) {
            $client = Entitlement::clientForWorkspace($workspaceId);

            if (Entitlement::state($client) === Entitlement::READONLY) {
                $blocked[$workspaceId] = (int) $client?->getKey();
            }
        }

        return $blocked;
    }

    /**
     * @param  Collection<int, SocialPost>  $due
     * @param  array<int, int>  $blocked  workspace_id => client_id
     * @return array<int, int> workspace_id => posts held
     */
    private function dispatchDue(Collection $due, array $blocked): array
    {
        $held = [];

        foreach ($due as $post) {
            $workspaceId = (int) $post->getAttribute('workspace_id');

            // BEFORE the flip, deliberately. Claiming the post and only then
            // skipping it would leave it in 'publishing', where nothing ever
            // moves it back to 'scheduled' — the post would be stranded even
            // after the client pays. Held posts stay 'scheduled' and go out on a
            // later tick.
            if (isset($blocked[$workspaceId])) {
                $held[$workspaceId] = ($held[$workspaceId] ?? 0) + 1;

                continue;
            }

            // Atomically flip status to 'publishing' before dispatching so a second
            // scheduler tick cannot pick up the same post and dispatch it twice.
            $updated = SocialPost::where('id', $post->id)
                ->where('status', 'scheduled')
                ->update(['status' => 'publishing']);

            if ($updated) {
                PublishSocialPostJob::dispatch($post->id)->onQueue('social');
            }
        }

        return $held;
    }

    /**
     * Safety net: a post stays in 'publishing' forever if the worker died hard
     * (SIGKILL, reboot) before the publish job's failed() hook could run. The
     * per-account link statuses make re-publishing idempotent, so re-dispatch.
     *
     * A read-only client's stuck post is left exactly as it is — re-dispatching
     * would publish to their social accounts, which is the work this check
     * exists to stop. It is not touched either, so it stays eligible for this
     * same net on the first tick after they pay.
     *
     * @param  Collection<int, SocialPost>  $stuck
     * @param  array<int, int>  $blocked  workspace_id => client_id
     * @return array<int, int> workspace_id => posts held
     */
    private function requeueStuckPosts(Collection $stuck, array $blocked): array
    {
        $held = [];

        foreach ($stuck as $post) {
            $workspaceId = (int) $post->getAttribute('workspace_id');

            if (isset($blocked[$workspaceId])) {
                $held[$workspaceId] = ($held[$workspaceId] ?? 0) + 1;

                continue;
            }

            Log::warning('Requeueing social post stuck in publishing', ['post_id' => $post->id]);

            // Reset the staleness clock so the next ticks don't re-dispatch
            // the same post every minute while it waits in the queue.
            $post->touch();

            PublishSocialPostJob::dispatch($post->id)->onQueue('social');
        }

        return $held;
    }

    /**
     * This job runs every minute, so a line per held post would be 1440 a day
     * per post. One aggregated line per run instead, carrying only clients not
     * already reported in the last hour — a newly blocked client is visible at
     * once, an existing one stays visible hourly. Ids and counts only: no post
     * titles, no bodies, no client names.
     *
     * @param  array<int, int>  $held  workspace_id => posts held
     * @param  array<int, int>  $blocked  workspace_id => client_id
     */
    private function logHeld(array $held, array $blocked): void
    {
        /** @var array<int, array{client_id: int, workspace_ids: array<int, int>, posts: int}> $byClient */
        $byClient = [];

        foreach ($held as $workspaceId => $count) {
            $clientId = $blocked[$workspaceId] ?? 0;

            $byClient[$clientId]['client_id'] = $clientId;
            $byClient[$clientId]['workspace_ids'][] = $workspaceId;
            $byClient[$clientId]['posts'] = ($byClient[$clientId]['posts'] ?? 0) + $count;
        }

        $report = array_values(array_filter(
            $byClient,
            fn (array $row): bool => Cache::add("entitlement_block_log:social_posts:{$row['client_id']}", 1, 3600)
        ));

        if ($report === []) {
            return;
        }

        Log::warning('Scheduled social posts held: client entitlement is read-only.', [
            'held' => $report,
        ]);
    }
}
