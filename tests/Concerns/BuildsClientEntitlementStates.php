<?php

namespace Tests\Concerns;

use App\Models\Client;
use App\Models\ClientSubscription;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Models\Workspace;
use App\Support\Entitlement;

/**
 * Building a client that is genuinely in one of the three entitlement states.
 *
 * Extracted from tests/Feature/Billing/SubscriptionAccessTest so the HTTP gate
 * and the background gate are proved against the *same* fixtures. If the way an
 * entitlement is stored ever changes, both suites move together — two private
 * copies of adminGrant() would have let one of them keep testing a shape that no
 * longer exists.
 */
trait BuildsClientEntitlementStates
{
    private ?Plan $entitlementPlan = null;

    /**
     * A client, its administrator and its workspace.
     *
     * @return array{0: User, 1: Client, 2: Workspace}
     */
    private function makeClient(string $name): array
    {
        $ctx = $this->createWorkspaceContext(['name' => $name]);

        return [$ctx['user'], $ctx['client'], $ctx['workspace']];
    }

    /**
     * An admin-assigned entitlement in client_subscriptions.
     */
    private function adminGrant(Client $client, mixed $endsAt, string $status = ClientSubscription::STATUS_ACTIVE): ClientSubscription
    {
        return ClientSubscription::create([
            'client_id' => $client->id,
            'plan_id' => $this->plan()->id,
            'billing_cycle' => ClientSubscription::BILLING_MONTHLY,
            'starts_at' => now()->subYear(),
            'ends_at' => $endsAt,
            'status' => $status,
        ]);
    }

    /**
     * A self-serve / gateway entitlement in subscriptions, hanging off a user.
     *
     * @param  array<string, mixed>  $attrs
     */
    private function gatewaySubscription(User $user, array $attrs = []): Subscription
    {
        return Subscription::create(array_merge([
            'user_id' => $user->id,
            'plan_id' => $this->plan()->id,
            'status' => 'active',
            'billing_cycle' => 'month',
            'starts_at' => now()->subMonth(),
            'gateway' => 'stripe',
        ], $attrs));
    }

    private function plan(): Plan
    {
        return $this->entitlementPlan ??= Plan::factory()->create();
    }

    /**
     * A client parked in exactly one of the three states, asserted to actually be
     * in it before the test proceeds. Without the assertion a fixture that
     * silently drifted (a changed grace default, a renamed status) would leave a
     * "readonly client is blocked" test passing against an active client.
     *
     * @return array{0: User, 1: Client, 2: Workspace}
     */
    private function makeClientInState(string $state, string $name): array
    {
        [$user, $client, $workspace] = $this->makeClient($name);

        match ($state) {
            Entitlement::ACTIVE => $this->adminGrant($client, now()->addMonth()),
            // Three days past a seven-day grace window: still entitled, warned.
            Entitlement::GRACE => $this->adminGrant($client, now()->subDays(3), ClientSubscription::STATUS_EXPIRED),
            Entitlement::READONLY => $this->adminGrant($client, now()->subDays(8), ClientSubscription::STATUS_EXPIRED),
            default => throw new \InvalidArgumentException("Unknown entitlement state [{$state}]."),
        };

        Entitlement::forget();

        $this->assertSame(
            $state,
            Entitlement::state($client->fresh()),
            "Fixture for [{$name}] was meant to be {$state} but the resolver disagrees."
        );

        Entitlement::forget();

        return [$user, $client, $workspace];
    }
}
