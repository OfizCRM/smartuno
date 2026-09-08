<?php

namespace App\Modules\Broadcasting\Jobs\Concerns;

use App\Modules\Broadcasting\Models\Campaign;
use App\Support\Entitlement;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Stopping a campaign that is already fanned out, for a client who has gone
 * read-only since it was launched.
 *
 * Shared by DispatchCampaignChunkJob and SendCampaignMessageJob rather than
 * written twice: they must agree exactly on what "held" means for a campaign,
 * and two private copies would have been free to drift — one pausing, the other
 * silently returning, which would strand the campaign in 'sending' for ever.
 * It is a trait rather than a service because there is no state and no
 * dependency to inject; it is the same shape as the private isReadonly() guards
 * in the event listeners, extracted only because it has two call sites.
 */
trait HoldsCampaignWhenReadonly
{
    /**
     * Pause the campaign and report that this job must stop.
     *
     * 'paused' is deliberate, and it is the product's own resumable state
     * rather than a new one:
     *
     *   - SendCampaignMessageJob already soft-stops on it, so the tens of
     *     thousands of sends still sitting on the 'broadcast' queue stop
     *     without any of them having to resolve entitlement themselves;
     *   - FinalizeCampaignJob returns early on it, so the campaign is not
     *     marked completed while recipients are still unsent;
     *   - CampaignController::launch accepts a paused campaign, and
     *     LaunchCampaignJob's insertOrIgnore plus its still-queued re-query
     *     resume exactly the recipients that never went out.
     *
     * Leaving it in 'sending' instead would strand it: nothing else moves a
     * campaign out of that status, and the finalizer would eventually mark it
     * completed with recipients that were never sent.
     */
    protected function holdCampaignIfReadonly(Campaign $campaign): bool
    {
        $workspaceId = (int) $campaign->workspace_id;
        $client = Entitlement::clientForWorkspace($workspaceId);

        if (Entitlement::state($client) !== Entitlement::READONLY) {
            return false;
        }

        // Conditional so only the first job of a fan-out actually writes, and so
        // a campaign the tenant paused, or that failed or finished in the
        // meantime, is never reopened by this.
        Campaign::where('id', $campaign->id)
            ->whereIn('status', ['queued', 'sending'])
            ->update(['status' => 'paused']);

        $clientId = (int) $client?->getKey();

        // One line per client per hour, not one per recipient: a paused campaign
        // can still have a hundred thousand sends draining behind it. Ids only —
        // no campaign name, no recipient, no message body.
        if (Cache::add("entitlement_block_log:campaign_send:{$clientId}", 1, 3600)) {
            Log::warning('Campaign paused mid-send: client entitlement is read-only.', [
                'workspace_id' => $workspaceId,
                'client_id' => $clientId,
                'campaign_id' => $campaign->id,
            ]);
        }

        return true;
    }
}
