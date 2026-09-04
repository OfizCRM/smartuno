<?php

namespace App\Services;

use App\Jobs\DispatchWebhookJob;
use App\Models\User;
use App\Models\WebhookEndpoint;
use Illuminate\Support\Collection;

class WebhookDispatchService
{
    /**
     * Dispatch an event to all matching webhook endpoints for a user.
     */
    public function dispatch(User $user, string $event, array $payload): void
    {
        $this->fanOut(
            WebhookEndpoint::where('user_id', $user->id)->where('enabled', true)->get(),
            $event,
            $payload
        );
    }

    /**
     * Dispatch an event to every enabled endpoint owned by any member of a
     * workspace. Endpoints hang off a user, but the events are workspace-wide,
     * so an endpoint registered by the second person in a firm must fire too.
     *
     * @param  array<string, mixed>  $payload
     */
    public function dispatchForWorkspace(?int $workspaceId, string $event, array $payload): void
    {
        if (! $workspaceId) {
            return;
        }

        $this->fanOut(
            WebhookEndpoint::whereIn('user_id', User::where('workspace_id', $workspaceId)->select('id'))
                ->where('enabled', true)
                ->get(),
            $event,
            $payload
        );
    }

    /**
     * @param  Collection<int, WebhookEndpoint>  $endpoints
     * @param  array<string, mixed>  $payload
     */
    private function fanOut(Collection $endpoints, string $event, array $payload): void
    {
        foreach ($endpoints as $endpoint) {
            if ($endpoint->listensTo($event)) {
                DispatchWebhookJob::dispatch($endpoint, $event, $payload);
            }
        }
    }

    /**
     * Dispatch an event to a specific endpoint.
     */
    public function dispatchToEndpoint(WebhookEndpoint $endpoint, string $event, array $payload): void
    {
        DispatchWebhookJob::dispatch($endpoint, $event, $payload);
    }
}
