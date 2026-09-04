<?php

namespace App\Listeners;

use App\Events\CampaignCompleted;
use App\Events\ContactCreated;
use App\Events\MessageReceived;
use App\Services\WebhookDispatchService;

/**
 * Fan-out platform events to registered outbound webhook endpoints.
 */
class DispatchOutboundWebhookListener
{
    public function __construct(private readonly WebhookDispatchService $webhookService) {}

    public function handleContactCreated(ContactCreated $event): void
    {
        $contact = $event->contact;

        $this->webhookService->dispatchForWorkspace($contact->workspace_id, 'contact.created', [
            'event' => 'contact.created',
            'contact' => [
                'id' => $contact->id,
                'phone_e164' => $contact->phone_e164,
                'email' => $contact->email,
                'first_name' => $contact->first_name,
                'last_name' => $contact->last_name,
            ],
        ]);
    }

    public function handleMessageReceived(MessageReceived $event): void
    {
        $message = $event->message;
        $conversation = $message->conversation;
        if (! $conversation) {
            return;
        }

        $this->webhookService->dispatchForWorkspace($conversation->workspace_id, 'message.received', [
            'event' => 'message.received',
            'message' => [
                'id' => $message->id,
                'conversation_id' => $message->conversation_id,
                'direction' => $message->direction,
                'channel' => $message->channel,
                'body' => $message->body,
                'sent_at' => $message->sent_at?->toIso8601String(),
            ],
        ]);
    }

    public function handleCampaignCompleted(CampaignCompleted $event): void
    {
        $campaign = $event->campaign;

        $this->webhookService->dispatchForWorkspace($campaign->workspace_id, 'campaign.completed', [
            'event' => 'campaign.completed',
            'campaign' => [
                'id' => $campaign->id,
                'name' => $campaign->name,
                'channel' => $campaign->channel,
                'status' => $campaign->status,
                'totals' => $campaign->totals_json ?? [],
            ],
        ]);
    }
}
