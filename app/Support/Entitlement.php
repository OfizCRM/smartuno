<?php

namespace App\Support;

use App\Models\Client;
use App\Models\ClientSubscription;
use App\Models\Subscription;
use App\Models\User;
use App\Models\Workspace;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;

/**
 * The one place that answers "what may this client do right now".
 *
 * Three states:
 *   active   – entitled; everything works.
 *   grace    – the entitlement ended less than config('saas.grace_days') days
 *              ago; everything still works, the UI warns.
 *   readonly – grace is over; reads work, writes are refused by
 *              App\Http\Middleware\EnforceSubscriptionAccess. Nothing is deleted.
 *
 * Entitlement can come from EITHER of the two subscription tables, and a client
 * entitled through either one is entitled:
 *   client_subscriptions – admin-assigned from /admin/clients. An `active` row
 *                          with a NULL ends_at is PERPETUAL: an admin who granted
 *                          a plan by hand deliberately set no expiry, and no
 *                          amount of elapsed time may ever take it away.
 *   subscriptions        – the client's users' self-serve / gateway plans. Status
 *                          is read before dates: a row in a live status with no
 *                          ends_at is entitled, because Stripe leaves the old
 *                          trial_ends_at on a converted trial forever. For a
 *                          lapsed row renews_at is the paid-through date.
 * Looking in only one table is exactly what left App\Http\Middleware\EnforceLimit
 * inert (docs/debt-register.md), so both are read here, always.
 *
 * Client::effectivePlan() models the same two-table precedence but answers a
 * different question — *which plan*, via activeSubscription, which ignores
 * ends_at entirely and so cannot say *when* entitlement ended. We need the latest
 * end date across both tables, so the resolution is done independently here while
 * mirroring that precedence.
 *
 * FAIL OPEN. If the client is null, both tables are empty, a date cannot be read,
 * or anything at all throws, the answer is 'active' and a warning is logged.
 * Locking a paying customer out of their own inbox because of a null is far worse
 * than giving away a week of service.
 */
final class Entitlement
{
    public const ACTIVE = 'active';

    public const GRACE = 'grace';

    public const READONLY = 'readonly';

    /**
     * Gateway statuses that mean "this subscription is running right now".
     * Everything else (canceled/cancelled, expired, past_due, unpaid,
     * incomplete, incomplete_expired) is lapsed and must be dated.
     */
    private const LIVE_STATUSES = ['active', 'trialing'];

    /**
     * A date before this is corrupt data, not an expiry — see toImmutable().
     * The product did not exist in the twentieth century.
     */
    private const EARLIEST_PLAUSIBLE_YEAR = 2000;

    /**
     * Which kind of entitlement ran out. Only the wording differs — a firm that
     * never paid a leu must not be told "your subscription has ended" and asked
     * to reactivate one, and a paying firm must not be told its trial is over.
     */
    public const REASON_TRIAL = 'trial';

    public const REASON_SUBSCRIPTION = 'subscription';

    /**
     * Per-request memo keyed by client id. This resolver runs on every request
     * (middleware + the shared Inertia prop), and a request only ever concerns
     * one client, so a static keyed by id collapses that to two queries. It is a
     * plain static rather than the cache because the answer must change the
     * instant an admin extends a subscription — a stale cached 'readonly' would
     * lock out a customer who has just paid. Call forget() in tests that move
     * time or rewrite subscription rows.
     *
     * @var array<int, array{state: string, grace_ends_at: CarbonImmutable|null, reason: string|null}>
     */
    private static array $memo = [];

    /**
     * Per-request memo for the workspace -> client hop, keyed by workspace id.
     * Separate from $memo because it answers a different, far more stable
     * question: workspaces.client_id is set when the workspace is created and
     * effectively never changes, whereas an entitlement changes the moment an
     * invoice is paid. Caching the hop is therefore free of the staleness risk
     * that forget() exists to manage, and it is what collapses a Meta batch of
     * twenty messages from forty lookups to one.
     *
     * @var array<int, Client|null>
     */
    private static array $clientByWorkspace = [];

    public static function state(?Client $client): string
    {
        return self::resolve($client)['state'];
    }

    public static function graceEndsAt(?Client $client): ?CarbonImmutable
    {
        return self::resolve($client)['grace_ends_at'];
    }

    /**
     * What ran out — REASON_TRIAL or REASON_SUBSCRIPTION — or null while active.
     * Wording only; it has no effect on what the client may do.
     */
    public static function reason(?Client $client): ?string
    {
        return self::resolve($client)['reason'];
    }

    /**
     * Whole days of grace still remaining, or null when not in grace. Rounded up,
     * so the last partial day still reads as "1 day left" rather than "0".
     */
    public static function daysLeft(?Client $client): ?int
    {
        $resolved = self::resolve($client);

        if ($resolved['state'] !== self::GRACE || $resolved['grace_ends_at'] === null) {
            return null;
        }

        $seconds = CarbonImmutable::now()->diffInSeconds($resolved['grace_ends_at'], false);

        return max(1, (int) ceil($seconds / 86400));
    }

    /**
     * Drop everything memoised. Called for every queued job by the hook in
     * AppServiceProvider::boot(), and by tests that move time or rewrite
     * subscription rows.
     */
    public static function forget(): void
    {
        self::$memo = [];
        self::$clientByWorkspace = [];
    }

    /**
     * The client that owns a workspace, for background code that has a workspace
     * id and no Client — the event listeners and the every-minute scheduled jobs.
     *
     * It returns the Client rather than the state so the one caller shape covers
     * both needs: the caller passes it straight to state(), whose existing
     * null-safe contract already supplies the fail-open answer, and it still has
     * the client id to put in the log line that tells the owner *which* customer
     * is being held. A state-returning helper would have forced a second lookup
     * for that id.
     *
     * Every hop can be missing. workspaces.client_id is a plain nullable column
     * with no foreign key (see the create_workspaces migration), so the id can be
     * null or dangle at a deleted row, and the workspace itself may be gone. None
     * of that is evidence that anyone stopped paying, so all of it returns null,
     * which state() reads as 'active'. Fail open, exactly like resolve().
     *
     * FRESHNESS is handled one level up, not here. A queue worker lives for
     * hours and handles many clients: a verdict memoised for the life of the
     * process would keep skipping a customer's campaigns long after they paid,
     * until somebody restarted the worker — a paying customer silently
     * receiving no service, the worst outcome this check can produce. So the
     * memo is dropped at the start of every queued job (the Queue::before hook
     * in AppServiceProvider::boot(), plus an explicit call in the jobs whose
     * handle() the tests invoke directly), which is the boundary at which the
     * question can genuinely have a new answer.
     *
     * It must NOT be dropped per call. Inbound webhooks are processed inside
     * ProcessInboundMessageJob / ProcessInboundInboxMessageJob, one Meta batch
     * carrying up to twenty messages, each dispatching MessageReceived to two
     * listeners that both land here. Forgetting per call made that 160 queries
     * inside a single job for entitled tenants too; memoised, the whole batch
     * costs four.
     */
    public static function clientForWorkspace(?int $workspaceId): ?Client
    {
        if ($workspaceId === null || $workspaceId <= 0) {
            return null;
        }

        if (array_key_exists($workspaceId, self::$clientByWorkspace)) {
            return self::$clientByWorkspace[$workspaceId];
        }

        try {
            $workspace = Workspace::query()->find($workspaceId, ['id', 'client_id']);

            if ($workspace === null || $workspace->client_id === null) {
                return self::$clientByWorkspace[$workspaceId] = null;
            }

            return self::$clientByWorkspace[$workspaceId] = Client::query()->find((int) $workspace->client_id);
        } catch (\Throwable $e) {
            // Fail open: a query error must not hold a customer's campaigns.
            // Ids only — no names, no contact details.
            Log::warning('Entitlement workspace lookup failed; failing open to active.', [
                'workspace_id' => $workspaceId,
                'exception' => get_class($e),
                'message' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * @return array{state: string, grace_ends_at: CarbonImmutable|null, reason: string|null}
     */
    private static function resolve(?Client $client): array
    {
        $open = ['state' => self::ACTIVE, 'grace_ends_at' => null, 'reason' => null];

        if (! $client instanceof Client || $client->getKey() === null) {
            return $open;
        }

        $key = null;

        try {
            $key = (int) $client->getKey();

            if (array_key_exists($key, self::$memo)) {
                return self::$memo[$key];
            }

            return self::$memo[$key] = self::compute($client);
        } catch (\Throwable $e) {
            // Fail open: never let a broken row, a bad date or a query error cost
            // a customer their access. Id only — no names, emails or plan data.
            Log::warning('Entitlement resolution failed; failing open to active.', [
                'client_id' => $key,
                'exception' => get_class($e),
                'message' => $e->getMessage(),
            ]);

            if ($key !== null) {
                self::$memo[$key] = $open;
            }

            return $open;
        }
    }

    /**
     * @return array{state: string, grace_ends_at: CarbonImmutable|null, reason: string|null}
     */
    private static function compute(Client $client): array
    {
        $active = ['state' => self::ACTIVE, 'grace_ends_at' => null, 'reason' => null];

        $now = CarbonImmutable::now();
        $graceDays = max(0, (int) config('saas.grace_days', 7));
        $clientId = $client->getKey();

        /** @var CarbonImmutable|null $latestEnd the last moment this client was entitled, across both tables */
        $latestEnd = null;

        // Which kind of entitlement produced $latestEnd. Wording for the banner
        // only; it never changes what the client may do.
        $latestReason = null;

        // Admin-assigned grants. Rows in any status contribute their end date —
        // a row flipped to 'expired' still tells us *when* it expired — but only
        // an 'active' row with no end date is the deliberate perpetual grant.
        $adminGrants = ClientSubscription::query()
            ->where('client_id', $clientId)
            ->get(['status', 'ends_at']);

        foreach ($adminGrants as $grant) {
            if ($grant->ends_at === null) {
                if ($grant->status === ClientSubscription::STATUS_ACTIVE) {
                    return $active;
                }

                continue;
            }

            $end = self::toImmutable($grant->ends_at);

            if ($end !== null && ($latestEnd === null || $end->greaterThan($latestEnd))) {
                $latestEnd = $end;
                $latestReason = self::REASON_SUBSCRIPTION;
            }
        }

        // Self-serve / gateway subscriptions hang off the client's users, so they
        // are fetched with one subquery rather than a query per user.
        $userSubscriptions = Subscription::query()
            ->whereIn('user_id', User::query()->where('client_id', $clientId)->select('id'))
            ->get(['status', 'ends_at', 'trial_ends_at', 'renews_at']);

        foreach ($userSubscriptions as $subscription) {
            // Status before dates, for 'active' only. StripeGateway never clears
            // trial_ends_at when a trial converts — handleSubscriptionUpdated()
            // and sync() both write back the existing value whenever Stripe still
            // reports a trial_end, and Stripe keeps trial_end on the object
            // forever — so a firm that has been billed successfully for months
            // still carries the date its trial ended. Reading the dates first
            // took that stale date as an expiry and put a paying customer into
            // read-only. ends_at holds only cancel_at, a *scheduled* future
            // cancellation, so an 'active' row without one is simply entitled.
            //
            // 'trialing' is deliberately NOT short-circuited: a row still in that
            // status has not converted, so its trial_ends_at is live data and is
            // exactly the date the owner's decision turns on.
            if ($subscription->status === 'active' && $subscription->ends_at === null) {
                return $active;
            }

            // A trial that was later converted carries both dates; the later of
            // the two is the moment access was actually meant to stop.
            $end = self::later($subscription->ends_at, $subscription->trial_ends_at);

            // For a lapsed row renews_at is the end of the last period actually
            // paid for. Stripe's dunning cancellation leaves ends_at NULL (that
            // column only ever holds cancel_at, a *future* scheduled cancel), so
            // renews_at is the only date such a row has, and ignoring it let the
            // customer who most obviously stopped paying keep full access.
            if (! in_array($subscription->status, self::LIVE_STATUSES, true)) {
                $end = self::later($end, $subscription->renews_at);
            }

            if ($end === null) {
                continue;
            }

            if ($latestEnd === null || $end->greaterThan($latestEnd)) {
                $latestEnd = $end;
                // A row that never converted still has its whole entitlement in
                // trial_ends_at: no ends_at, and the only date is the trial's.
                $latestReason = ($subscription->ends_at === null && $subscription->trial_ends_at !== null)
                    ? self::REASON_TRIAL
                    : self::REASON_SUBSCRIPTION;
            }
        }

        // No dated entitlement anywhere: an admin-created client that was never
        // given a plan, or a fresh install. Fail open rather than lock out.
        if ($latestEnd === null) {
            return $active;
        }

        if ($latestEnd->greaterThan($now)) {
            return $active;
        }

        $graceEndsAt = $latestEnd->addDays($graceDays);

        // Half-open interval [expiry, expiry + grace): at the exact grace-end
        // instant the customer has had the full seven days, so grace is over.
        // Keeping it half-open also guarantees daysLeft() >= 1 whenever the state
        // is 'grace', so the banner never counts down to a meaningless zero.
        if ($now->lessThan($graceEndsAt)) {
            return ['state' => self::GRACE, 'grace_ends_at' => $graceEndsAt, 'reason' => $latestReason];
        }

        return ['state' => self::READONLY, 'grace_ends_at' => null, 'reason' => $latestReason];
    }

    private static function later(mixed $a, mixed $b): ?CarbonImmutable
    {
        $left = self::toImmutable($a);
        $right = self::toImmutable($b);

        if ($left === null) {
            return $right;
        }

        if ($right === null) {
            return $left;
        }

        return $left->greaterThan($right) ? $left : $right;
    }

    /**
     * Accepts whatever the column actually holds. A raw string is parsed, and an
     * unparseable one throws — straight into resolve()'s catch, which fails open.
     *
     * A zero timestamp — what a migration from an old WhatsMine install leaves in
     * a datetime column — does NOT throw: Carbon reads '0000-00-00 00:00:00' as
     * the year -0001, which the caller would then take as "expired two thousand
     * years ago" and lock the customer out permanently, silently, with nothing in
     * the log because nothing failed. So an implausible date is rejected here and
     * contributes no end date at all, which lands the client back on the fail-open
     * path this class promises.
     */
    private static function toImmutable(mixed $value): ?CarbonImmutable
    {
        if ($value === null || $value === '') {
            return null;
        }

        $date = null;

        if ($value instanceof \DateTimeInterface) {
            $date = CarbonImmutable::instance($value);
        } elseif (is_string($value)) {
            $date = CarbonImmutable::parse($value);
        }

        if ($date === null || $date->year < self::EARLIEST_PLAUSIBLE_YEAR) {
            return null;
        }

        return $date;
    }
}
