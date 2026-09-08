<?php

namespace App\Modules\Broadcasting\Jobs;

use App\Modules\Broadcasting\Jobs\Concerns\HoldsCampaignWhenReadonly;
use App\Modules\Broadcasting\Models\Campaign;
use App\Support\Entitlement;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class DispatchCampaignChunkJob implements ShouldQueue
{
    use Dispatchable, HoldsCampaignWhenReadonly, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public function __construct(
        public readonly int $campaignId,
        public readonly array $contactIds,
    ) {}

    public function handle(): void
    {
        // Belt and braces with the Queue::before hook in AppServiceProvider,
        // which a direct ->handle() (tests, sync dispatch) never fires.
        Entitlement::forget();

        $campaign = Campaign::find($this->campaignId);
        if (! $campaign || $campaign->status === 'failed') {
            return;
        }

        // LaunchScheduledCampaignsJob only gates a campaign at the moment it is
        // launched. Everything after that already exists as queued jobs: a
        // 100k-recipient campaign launched in grace fans out into a hundred of
        // these, and each one queues a thousand sends. If grace ends mid-run —
        // or the 'broadcast' queue is simply backed up — those jobs still
        // execute, and for a workspace with no SMS or SMTP config of its own
        // CredentialResolver bills the sends to the platform's own Twilio
        // account and sending reputation. One resolution per thousand
        // recipients is the cheapest place to stop that.
        if ($this->holdCampaignIfReadonly($campaign)) {
            return;
        }

        foreach ($this->contactIds as $i => $contactId) {
            SendCampaignMessageJob::dispatch($campaign->id, $contactId)
                ->onQueue('broadcast')
                ->delay(now()->addMilliseconds($i * 100)); // 10 msgs/second rate limit
        }
    }
}
