<?php

namespace Tests\Feature;

use App\Events\ContactCreated;
use App\Jobs\DispatchWebhookJob;
use App\Models\User;
use App\Models\WebhookEndpoint;
use App\Modules\Shared\Models\Contact;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class WebhookEndpointTest extends TestCase
{
    use RefreshDatabase;

    private function clientUser(): User
    {
        return User::factory()->create([
            'role'              => 'client',
            'email_verified_at' => now(),
        ]);
    }

    public function test_user_can_list_webhook_endpoints(): void
    {
        $user = $this->clientUser();
        WebhookEndpoint::factory()->count(2)->create(['user_id' => $user->id]);

        $this->actingAs($user)
            ->get(route('client.webhooks.index'))
            ->assertOk();
    }

    public function test_user_can_create_webhook_endpoint(): void
    {
        $user = $this->clientUser();

        $this->actingAs($user)
            ->post(route('client.webhooks.store'), [
                'url'     => 'https://example.com/webhook',
                'events'  => ['contact.created'],
                'enabled' => true,
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('webhook_endpoints', [
            'user_id' => $user->id,
            'url'     => 'https://example.com/webhook',
        ]);
    }

    public function test_user_can_delete_own_endpoint(): void
    {
        $user     = $this->clientUser();
        $endpoint = WebhookEndpoint::factory()->create(['user_id' => $user->id]);

        $this->actingAs($user)
            ->delete(route('client.webhooks.destroy', $endpoint))
            ->assertRedirect();

        $this->assertDatabaseMissing('webhook_endpoints', ['id' => $endpoint->id]);
    }

    public function test_user_cannot_delete_other_users_endpoint(): void
    {
        $user      = $this->clientUser();
        $otherUser = $this->clientUser();
        $endpoint  = WebhookEndpoint::factory()->create(['user_id' => $otherUser->id]);

        $this->actingAs($user)
            ->delete(route('client.webhooks.destroy', $endpoint))
            ->assertForbidden();
    }

    public function test_user_can_rotate_endpoint_secret(): void
    {
        $user     = $this->clientUser();
        $endpoint = WebhookEndpoint::factory()->create(['user_id' => $user->id]);
        $oldSecret = $endpoint->secret;

        $this->actingAs($user)
            ->postJson(route('client.webhooks.rotate-secret', $endpoint))
            ->assertOk()
            ->assertJsonStructure(['secret']);

        $this->assertNotEquals($oldSecret, $endpoint->fresh()->secret);
    }

    public function test_guest_cannot_access_webhooks(): void
    {
        $this->get(route('client.webhooks.index'))
            ->assertRedirect(route('login'));
    }

    public function test_page_only_offers_events_that_are_really_dispatched(): void
    {
        $user = $this->clientUser();

        $this->actingAs($user)
            ->get(route('client.webhooks.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('availableEvents', WebhookEndpoint::EVENTS));
    }

    public function test_event_that_is_never_dispatched_is_rejected(): void
    {
        $user = $this->clientUser();

        $this->actingAs($user)
            ->post(route('client.webhooks.store'), [
                'url' => 'https://example.com/webhook',
                'events' => ['subscription.created'],
            ])
            ->assertSessionHasErrors('events.0');

        $this->assertDatabaseMissing('webhook_endpoints', ['url' => 'https://example.com/webhook']);
    }

    public function test_endpoint_of_any_workspace_member_receives_the_fan_out(): void
    {
        Queue::fake();

        ['user' => $owner, 'workspace' => $workspace] = $this->createWorkspaceContext();
        $colleague = User::factory()->create([
            'role' => User::ROLE_CLIENT,
            'client_id' => $owner->client_id,
            'workspace_id' => $workspace->id,
            'email_verified_at' => now(),
        ]);

        // The endpoint belongs to the second person in the firm, not the first.
        WebhookEndpoint::factory()->create([
            'user_id' => $colleague->id,
            'events' => ['contact.created'],
        ]);

        ContactCreated::dispatch(Contact::factory()->create(['workspace_id' => $workspace->id]));

        Queue::assertPushed(DispatchWebhookJob::class, 1);
    }

    public function test_unselected_event_is_not_delivered(): void
    {
        Queue::fake();

        ['user' => $owner, 'workspace' => $workspace] = $this->createWorkspaceContext();
        WebhookEndpoint::factory()->create([
            'user_id' => $owner->id,
            'events' => ['campaign.completed'],
        ]);

        ContactCreated::dispatch(Contact::factory()->create(['workspace_id' => $workspace->id]));

        Queue::assertNotPushed(DispatchWebhookJob::class);
    }
}
