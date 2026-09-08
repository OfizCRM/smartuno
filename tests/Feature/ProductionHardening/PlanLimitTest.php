<?php

namespace Tests\Feature\ProductionHardening;

use App\Models\Plan;
use App\Modules\Broadcasting\Models\Campaign;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Plan-limit enforcement tests.
 */
class PlanLimitTest extends TestCase
{
    use RefreshDatabase;

    public function test_campaign_launch_returns_redirect_when_limit_exhausted(): void
    {
        Queue::fake();

        $data = $this->createWorkspaceContext();
        $user = $data['user'];
        $workspace = $data['workspace'];
        $client = $data['client'];

        $plan = Plan::factory()->create(['limits' => ['campaigns_per_month' => 0]]);
        $this->attachPlanToClient($client, $plan);

        $campaign = Campaign::factory()->create([
            'workspace_id' => $workspace->id,
            'status' => 'draft',
            'channel' => 'sms',
        ]);

        $response = $this->actingAs($user)
            ->post("/app/broadcasts/campaigns/{$campaign->id}/launch");

        $response->assertRedirect('/billing');
        $response->assertSessionHas('upgrade_required');
    }
}
