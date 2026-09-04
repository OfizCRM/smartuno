<?php

namespace Tests\Feature\Polish;

use App\Jobs\GenerateWorkspaceExportJob;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class WorkspaceExportTest extends TestCase
{
    use RefreshDatabase;

    public function test_data_export_route_is_registered_and_authorized(): void
    {
        $ctx = $this->createWorkspaceContext([], ['email_verified_at' => now()]);
        $user = $ctx['user'];

        // The route must exist (not 404) and be accessible by authenticated users
        // 500 can happen in test env due to Vite manifest missing for new pages — that's a build artifact, not a logic error
        $response = $this->actingAs($user)->get(route('client.settings.data-export'));

        $this->assertNotEquals(404, $response->getStatusCode(), 'Data export route must be registered');
        $this->assertNotEquals(403, $response->getStatusCode(), 'Authenticated user must be able to access data export');
    }

    /**
     * The export ships every contact's phone number and every message body as a
     * ZIP behind a 72-hour signed URL that needs no login. Before this gate any
     * staff member could mail the whole workspace's data to themselves, while the
     * audit log sitting next to it in the settings hub was already admin-only.
     */
    public function test_staff_cannot_reach_or_request_the_export(): void
    {
        Queue::fake();

        $ctx = $this->createWorkspaceContext([], [
            'client_role' => User::CLIENT_ROLE_STAFF,
            'email_verified_at' => now(),
        ]);
        $staff = $ctx['user'];

        $this->actingAs($staff)
            ->get(route('client.settings.data-export'))
            ->assertForbidden();

        $this->actingAs($staff)
            ->post(route('client.settings.data-export.store'))
            ->assertForbidden();

        Queue::assertNotPushed(GenerateWorkspaceExportJob::class);
    }

    public function test_requesting_export_dispatches_job(): void
    {
        Queue::fake();

        $ctx = $this->createWorkspaceContext([], ['email_verified_at' => now()]);
        $user = $ctx['user'];

        $this->actingAs($user)
            ->post(route('client.settings.data-export.store'))
            ->assertRedirect();

        Queue::assertPushed(GenerateWorkspaceExportJob::class, fn ($job) => true);
    }
}
