<?php

namespace App\Modules\Email\Services;

use App\Events\MessageReceived;
use App\Modules\Shared\Models\ChannelAccount;
use App\Modules\Shared\Models\Conversation;
use App\Modules\Shared\Models\Message;
use App\Modules\Shared\Services\ContactService;

/**
 * Files one parsed email into the inbox.
 *
 * Deliberately takes a flat array rather than an IMAP object: threading,
 * de-duplication and the automated-mail rules are where this goes wrong, and
 * none of them need a mail server to test.
 */
class MailboxIngestor
{
    public function __construct(private readonly ContactService $contacts) {}

    /**
     * @param  array<string, mixed>  $mail  as produced by InboundMailParser::parse()
     * @return Message|null null when the message was already stored
     */
    public function ingest(ChannelAccount $mailbox, array $mail): ?Message
    {
        $workspaceId = (int) $mailbox->workspace_id;

        // A poll that overlaps the previous one, or a UID reset after the server
        // reissues UIDVALIDITY, both re-offer messages already filed.
        if ($mail['message_id'] && $this->alreadyStored($workspaceId, $mail['message_id'])) {
            return null;
        }

        $contact = $this->contacts->upsert($workspaceId, [
            'email' => $mail['from_email'],
            'first_name' => $this->firstName($mail['from_name']),
            'last_name' => $this->lastName($mail['from_name']),
            'source' => 'email',
        ]);

        $conversation = $this->conversationFor($mailbox, (int) $contact->id, $mail);

        $message = Message::create([
            'conversation_id' => $conversation->id,
            'direction' => 'in',
            'channel' => 'email',
            'type' => 'text',
            'body' => $mail['text'],
            // The subject has no column; it belongs to the message, not the
            // thread, because a correspondent can rename a thread mid-way.
            'payload' => [
                'subject' => $mail['subject'],
                'from' => $mail['from_email'],
                'automated' => $mail['automated'],
                'automated_reason' => $mail['automated_reason'],
                'attachments' => $mail['attachments'] ?? [],
            ],
            'provider_message_id' => $mail['message_id'],
            'status' => 'delivered',
            'sent_by' => 'human',
            'sent_at' => $mail['date'],
        ]);

        $conversation->forceFill([
            'last_message_at' => $mail['date'],
            'last_inbound_at' => $mail['date'],
            'unread_count' => (int) $conversation->getAttribute('unread_count') + 1,
        ])->save();

        // Automated mail is filed so the thread stays complete, but it must not
        // wake the chatbot: an out-of-office answering an out-of-office is a loop
        // that runs until someone reads the bill.
        if (! $mail['automated']) {
            MessageReceived::dispatch($message);
        }

        return $message;
    }

    private function alreadyStored(int $workspaceId, string $messageId): bool
    {
        return Message::where('provider_message_id', $messageId)
            ->whereHas('conversation', fn ($q) => $q->where('workspace_id', $workspaceId))
            ->exists();
    }

    /**
     * The thread this mail belongs to.
     *
     * One conversation per thread, not per person: a customer who writes about
     * an order in March and a complaint in July has two subjects, and collapsing
     * them into one endless conversation makes both harder to answer.
     *
     * @param  array<string, mixed>  $mail
     */
    private function conversationFor(ChannelAccount $mailbox, int $contactId, array $mail): Conversation
    {
        $parents = array_filter(array_merge(
            [$mail['in_reply_to']],
            $mail['references'],
        ));

        if ($parents !== []) {
            $existing = Conversation::where('workspace_id', $mailbox->workspace_id)
                ->whereHas('messages', fn ($q) => $q->whereIn('provider_message_id', $parents))
                ->first();

            if ($existing) {
                return $existing;
            }
        }

        // A new thread. external_thread_id holds the root Message-ID so a later
        // reply that names it as a parent finds this row.
        return Conversation::create([
            'workspace_id' => $mailbox->workspace_id,
            'channel_account_id' => $mailbox->id,
            'contact_id' => $contactId,
            'external_thread_id' => $mail['message_id'],
            'status' => 'open',
            'assigned_to' => 'bot',
            'unread_count' => 0,
            'last_message_at' => $mail['date'],
        ]);
    }

    private function firstName(?string $name): ?string
    {
        return $name ? trim(explode(' ', $name, 2)[0]) ?: null : null;
    }

    private function lastName(?string $name): ?string
    {
        return $name ? (trim(explode(' ', $name, 2)[1] ?? '') ?: null) : null;
    }
}
