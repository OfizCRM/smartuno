<?php

namespace App\Modules\Automation\Jobs;

use App\Events\AutomationFailed;
use App\Modules\Automation\Models\AutomationRun;
use App\Modules\Automation\Services\AutomationEngine;
use App\Support\Entitlement;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class ExecuteAutomationRunJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 120;

    /** How long a run held for a read-only client waits before asking again. */
    private const HOLD_MINUTES = 60;

    /**
     * Context key recording when this run was first held. Written once, on the
     * first hold, and removed the moment the client is entitled again, so a run
     * that is held, released, parked on another Wait and held again later starts
     * its ceiling from scratch rather than being cancelled on the first wake-up.
     */
    private const HELD_SINCE = '_held_since';

    public function __construct(public readonly int $runId) {}

    public function handle(AutomationEngine $engine): void
    {
        // The Queue::before hook in AppServiceProvider already does this for a
        // job that came off the queue; repeated here because a direct ->handle()
        // (tests, dispatchSync in some paths) never fires it, and a worker that
        // has been up for hours must not answer from a stale verdict.
        Entitlement::forget();

        $run = AutomationRun::with('automation')->find($this->runId);
        // A finished run must never execute again — e.g. a delayed wake-up job for a
        // run that was completed or cancelled while it sat in the queue.
        if (! $run || in_array($run->status, ['cancelled', 'failed', 'completed'], true)) {
            return;
        }

        // The six trigger guards in AutomationTriggerListener only cover the
        // moment a run *starts*. A run parked on a Wait node wakes up here days
        // later, by which time entitlement may have lapsed — and the nodes after
        // a Wait are the expensive ones: ai_reply and run_chatbot reach
        // CredentialResolver, which falls back to the platform LLM key when the
        // workspace has none, so a blocked tenant would spend the owner's own
        // credit, and every send_* node would put a message on their channel.
        if ($this->isHeld($run)) {
            return;
        }

        try {
            $engine->executeRun($run);
        } catch (\Throwable $e) {
            // The column is `error` (shown on the Runs page). Writing to a
            // non-existent `error_message` key was silently dropped, which left
            // every crashed run as "failed" with no reason.
            $run->update(['status' => 'failed', 'error' => $e->getMessage(), 'completed_at' => now()]);
            AutomationFailed::dispatch($run, $e->getMessage());
            throw $e;
        }
    }

    /**
     * Held, not consumed — the same shape as the campaign and post schedulers.
     * The run keeps its status and its resume cursor, and a fresh wake-up job is
     * queued an hour out, so it continues from exactly where it stopped on the
     * first attempt after the client pays. Failing the run instead would lose
     * work the tenant configured and paid for up to the moment they lapsed.
     *
     * The hold is bounded. Because the re-queue is a fresh job rather than
     * release(), $tries never applies, so without a ceiling a churned tenant
     * with runs parked on Wait nodes would re-queue them hourly for ever:
     * nothing else reaps them either — automation:prune-stale-runs only cancels
     * runs carrying _awaiting_reply, which a timed wait never has — so the
     * shared 'automation' queue would carry that traffic permanently, growing
     * with every tenant that churns.
     */
    private function isHeld(AutomationRun $run): bool
    {
        // ?? rather than ?->: the automation can genuinely be gone (executeRun
        // handles that case too), and null-coalescing already suppresses the
        // property read on null.
        $workspaceId = (int) ($run->automation->workspace_id ?? 0);
        $client = Entitlement::clientForWorkspace($workspaceId);

        if (Entitlement::state($client) !== Entitlement::READONLY) {
            $this->clearHoldMarker($run);

            return false;
        }

        $clientId = (int) $client?->getKey();

        if ($this->holdStartedAt($run)->addDays($this->maxHoldDays())->isPast()) {
            // Once per run, ever — so it is not throttled like the hourly line.
            Log::warning('Automation run cancelled: held past the entitlement ceiling.', [
                'workspace_id' => $workspaceId,
                'client_id' => $clientId,
                'run_id' => $run->id,
            ]);

            // Cancelled, not failed: nothing went wrong with the flow, and
            // AutomationFailed would notify a tenant about their own lapse. The
            // reason is what the Runs page shows, so it says what happened.
            $run->update([
                'status' => 'cancelled',
                'error' => __('Subscription lapsed while this run was waiting.'),
                'completed_at' => now(),
            ]);

            return true;
        }

        // One line per client per hour rather than one per held run: a tenant can
        // have many runs parked on Wait nodes. Ids only — no contact data, no
        // node contents.
        if (Cache::add("entitlement_block_log:automation_run:{$clientId}", 1, 3600)) {
            Log::warning('Automation run held: client entitlement is read-only.', [
                'workspace_id' => $workspaceId,
                'client_id' => $clientId,
            ]);
        }

        // A fresh job rather than release(): release() counts against $tries, so
        // the third hourly retry would fail the run outright and it would never
        // resume at all.
        //
        // Not on the sync driver, where ->delay() is ignored and dispatch runs
        // inline — that would re-enter this method immediately and recurse until
        // the process ran out of memory. A sync install has no wake-up worker to
        // re-dispatch to in the first place (executeWait's own delayed job fires
        // instantly there too, so Wait nodes do not work at all), so the run just
        // stays 'waiting'.
        if (! $this->queueRunsInline()) {
            self::dispatch($run->id)
                ->delay(now()->addMinutes(self::HOLD_MINUTES))
                ->onQueue('automation');
        }

        return true;
    }

    /**
     * When this run was first held, recording it if this is the first hold.
     *
     * Derived from the context rather than from updated_at, which for a run
     * parked on a 30-day Wait node is already 30 days old at its first wake-up
     * and would cancel it on the spot.
     *
     * A marker that cannot be read restarts the clock instead of cancelling:
     * losing one ceiling period costs the owner a month of queue traffic, while
     * cancelling a customer's run because of an unparseable string destroys work
     * they configured. Same fail-open bias as Entitlement itself.
     */
    private function holdStartedAt(AutomationRun $run): CarbonImmutable
    {
        // getAttribute() rather than ->context: AutomationRun declares no
        // @property for it, so the property read is an undefined-property error
        // at level 6 and the accessor is the typed way in. The array cast still
        // applies.
        $context = $this->contextOf($run);
        $marker = $context[self::HELD_SINCE] ?? null;

        if (is_string($marker) && $marker !== '') {
            try {
                return CarbonImmutable::parse($marker);
            } catch (\Throwable) {
                // Fall through and rewrite it.
            }
        }

        $now = CarbonImmutable::now();

        $run->update(['context' => array_merge($context, [self::HELD_SINCE => $now->toIso8601String()])]);

        return $now;
    }

    /**
     * Drop the marker once the client is entitled again, so the ceiling only
     * ever measures one uninterrupted hold. Writes nothing in the common case
     * where there is no marker — which is every run of every paying customer.
     */
    private function clearHoldMarker(AutomationRun $run): void
    {
        $context = $this->contextOf($run);

        if (! array_key_exists(self::HELD_SINCE, $context)) {
            return;
        }

        unset($context[self::HELD_SINCE]);

        $run->update(['context' => $context]);
    }

    /**
     * The run's context, always as an array — a run created before the cast, or
     * with a null context column, must not make the ceiling explode.
     *
     * @return array<string, mixed>
     */
    private function contextOf(AutomationRun $run): array
    {
        $context = $run->getAttribute('context');

        return is_array($context) ? $context : [];
    }

    /**
     * How long a run may stay held before it is cancelled instead of re-queued.
     * The default comfortably exceeds any realistic payment delay — a full month
     * after a seven-day grace window has already closed.
     */
    private function maxHoldDays(): int
    {
        return max(1, (int) config('saas.held_run_max_days', 30));
    }

    /**
     * Whether dispatching would execute in-process instead of queueing. The
     * driver is read rather than the connection name so a connection named
     * something else that is still `sync` is caught too.
     */
    private function queueRunsInline(): bool
    {
        $connection = $this->connection ?? config('queue.default');

        return config("queue.connections.{$connection}.driver") === 'sync';
    }
}
