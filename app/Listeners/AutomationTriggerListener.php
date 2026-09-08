<?php

namespace App\Listeners;

use App\Events\AutomationWebhookReceived;
use App\Events\CampaignCompleted;
use App\Events\CommerceEventReceived;
use App\Events\ContactCreated;
use App\Events\LeadQualified;
use App\Events\LeadStageChanged;
use App\Events\MessageReceived;
use App\Modules\Automation\Jobs\ExecuteAutomationRunJob;
use App\Modules\Automation\Models\Automation;
use App\Modules\Automation\Models\AutomationRun;
use App\Modules\Automation\Services\AutomationEngine;
use App\Support\Entitlement;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class AutomationTriggerListener
{
    public function __construct(private readonly AutomationEngine $engine) {}

    public function handleMessageReceived(MessageReceived $event): void
    {
        $message = $event->message;

        // Only a contact's own message can drive a flow; never an outbound echo.
        if (($message->direction ?? 'in') !== 'in') {
            return;
        }

        $contactId = $message->conversation?->contact_id;
        $workspaceId = $message->conversation?->workspace_id;
        if (! $contactId || ! $workspaceId) {
            return;
        }

        // Evaluate each inbound message exactly once, however many times the event
        // reaches us (a retried webhook, parallel workers, a double registration).
        if ($message->id && ! Cache::add("automation_trigger_lock:{$message->id}", 1, 60)) {
            return;
        }

        $messageBody = $message->body ?? '';

        // A reply to an "Ask question" node resumes the parked run. That same message
        // must not also restart the automation from its trigger, otherwise every
        // answer re-asks the question and runs pile up in "waiting".
        //
        // Deliberately ABOVE the entitlement guard. This consumes an answer the
        // tenant's own flow asked for; it is not the app starting new outbound
        // work. A contact answers once, so dropping the answer loses it for good:
        // the run would stay parked with _awaiting_reply set, the client could pay
        // a week later and it would still never resume, and automation:prune-stale-runs
        // would eventually cancel it with "No reply within 30 days" — a reason that
        // is simply false, shown to a paying customer on their Runs page. Storing
        // the answer sends nothing: the run is only moved to 'pending' and its
        // wake-up job is held by ExecuteAutomationRunJob's own guard until the
        // client is entitled again.
        $resumed = $this->engine->resumeAwaitingReplies($workspaceId, $contactId, $messageBody);

        // The message itself is already stored — the driver persists it before
        // dispatching MessageReceived — so a blocked tenant still sees it in the
        // inbox. What stops here is a *new* run being started from the trigger.
        if ($this->isReadonly($workspaceId, 'message.received')) {
            return;
        }

        $this->fireWithConfig('message.received', $workspaceId, $contactId, [
            'message_id' => $message->id,
            'message_channel' => $message->channel,
            'message_body' => $messageBody,
        ], $messageBody, $resumed);
    }

    public function handleContactCreated(ContactCreated $event): void
    {
        if ($this->isReadonly($event->contact->workspace_id, 'contact.created')) {
            return;
        }

        $this->fire('contact.created', $event->contact->workspace_id, $event->contact->id);
    }

    public function handleCampaignCompleted(CampaignCompleted $event): void
    {
        // No per-contact trigger for campaign completion; skip.
    }

    public function handleCommerceEvent(CommerceEventReceived $event): void
    {
        // eventType is one of order.placed / order.fulfilled / order.cancelled /
        // cart.abandoned / customer.created — matched directly against trigger_type.
        if ($this->isReadonly($event->workspaceId, $event->eventType)) {
            return;
        }

        $this->fire($event->eventType, $event->workspaceId, $event->contactId, $event->context);
    }

    public function handleLeadStageChanged(LeadStageChanged $event): void
    {
        // A lead only has a contact once it has been pushed to contacts. Without
        // one there is nobody for the automation's send/tag actions to act on.
        if (! $event->contactId) {
            return;
        }

        // Checked once here rather than inside fire(), which this handler calls
        // up to three times for a single stage change.
        if ($this->isReadonly($event->workspaceId, 'lead.stage_changed')) {
            return;
        }

        $this->fire('lead.stage_changed', $event->workspaceId, $event->contactId, $event->context());

        // Reaching a terminal stage is its own trigger, so a tenant can wire
        // "won" or "lost" follow-up without a condition node on the stage name.
        if ($event->isWon) {
            $this->fire('lead.won', $event->workspaceId, $event->contactId, $event->context());
        }

        if ($event->isLost) {
            $this->fire('lead.lost', $event->workspaceId, $event->contactId, $event->context());
        }
    }

    public function handleLeadQualified(LeadQualified $event): void
    {
        if (! $event->contactId) {
            return;
        }

        if ($this->isReadonly($event->workspaceId, 'lead.qualified')) {
            return;
        }

        $this->fire('lead.qualified', $event->workspaceId, $event->contactId, $event->context());
    }

    public function handleAutomationWebhookReceived(AutomationWebhookReceived $event): void
    {
        $automation = Automation::where('id', $event->automationId)
            ->where('status', 'active')
            ->where('trigger_type', 'webhook')
            ->first();

        if (! $automation) {
            return;
        }

        // The event carries no workspace id, so it comes from the automation the
        // webhook addressed — which is the workspace whose entitlement decides.
        if ($this->isReadonly((int) $automation->getAttribute('workspace_id'), 'webhook')) {
            return;
        }

        $context = ['payload' => $event->payload];

        if ($event->contactId) {
            $this->engine->triggerForContact($automation, $event->contactId, $context);
        } else {
            // Contactless: trigger a run without a contact (contact_id = null)
            $this->triggerWithoutContact($automation, $context);
        }
    }

    /**
     * Whether the client owning this workspace has run out of entitlement. An
     * automation acts on the tenant's behalf — it sends, tags and writes — so it
     * stops exactly like a write request does. Fails open: an unknown workspace,
     * or one with no client, answers false.
     */
    private function isReadonly(?int $workspaceId, string $triggerType): bool
    {
        $client = Entitlement::clientForWorkspace($workspaceId);

        if (Entitlement::state($client) !== Entitlement::READONLY) {
            return false;
        }

        $clientId = (int) $client?->getKey();

        // Once per client per hour, not once per event: a blocked tenant keeps
        // receiving messages and creating contacts all day. Ids only.
        if (Cache::add("entitlement_block_log:automation:{$clientId}", 1, 3600)) {
            Log::warning('Automation trigger suppressed: client entitlement is read-only.', [
                'workspace_id' => $workspaceId,
                'client_id' => $clientId,
                'trigger_type' => $triggerType,
            ]);
        }

        return true;
    }

    private function triggerWithoutContact(Automation $automation, array $context = []): void
    {
        $run = AutomationRun::create([
            'automation_id' => $automation->id,
            'contact_id' => null,
            'status' => 'pending',
            'context' => $context,
            'started_at' => now(),
        ]);

        dispatch(new ExecuteAutomationRunJob($run->id))->onQueue('automation');
    }

    private function fire(string $triggerType, int $workspaceId, int $contactId, array $context = []): void
    {
        Automation::where('workspace_id', $workspaceId)
            ->where('status', 'active')
            ->where('trigger_type', $triggerType)
            ->each(fn ($automation) => $this->engine->triggerForContact($automation, $contactId, $context));
    }

    /**
     * Like fire(), but respects trigger_config.keywords for message.received automations.
     * If keywords are set, the message body must contain at least one keyword (case-insensitive).
     */
    private function fireWithConfig(string $triggerType, int $workspaceId, int $contactId, array $context, string $messageBody = '', array $excludeAutomationIds = []): void
    {
        $automations = Automation::where('workspace_id', $workspaceId)
            ->where('status', 'active')
            ->where('trigger_type', $triggerType)
            ->when($excludeAutomationIds !== [], fn ($q) => $q->whereNotIn('id', $excludeAutomationIds))
            ->get();

        $bodyLower = mb_strtolower($messageBody);

        foreach ($automations as $automation) {
            $keywords = $automation->trigger_config['keywords'] ?? [];

            if (! empty($keywords)) {
                $matches = false;
                foreach ($keywords as $kw) {
                    if (str_contains($bodyLower, mb_strtolower((string) $kw))) {
                        $matches = true;
                        break;
                    }
                }
                if (! $matches) {
                    continue;
                }
            }

            $this->engine->triggerForContact($automation, $contactId, $context);
        }
    }
}
