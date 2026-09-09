<?php

namespace App\Notifications;

use App\Models\NotificationPreference;
use App\Modules\Documents\Models\Document;
use App\Notifications\Channels\OneSignalChannel;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\BroadcastMessage;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * A document is about to expire or needs renewing.
 *
 * The one thing this module does that nobody has to remember to do. A contract
 * that renews itself tacitly in thirty days, with nobody watching, is the exact
 * problem a small firm has and cannot solve with a folder.
 */
class DocumentExpiringNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly Document $document,
        public readonly int $daysBefore,
    ) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        $channels = ['database', 'broadcast'];

        if ($this->isEnabled($notifiable, 'mail')) {
            $channels[] = 'mail';
        }

        if ($this->isEnabled($notifiable, 'one_signal')) {
            $channels[] = OneSignalChannel::class;
        }

        return $channels;
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'document_expiring',
            'document_uuid' => $this->document->uuid,
            'document_name' => $this->document->name,
            'expires_at' => $this->document->expires_at?->toDateString(),
            'days_before' => $this->daysBefore,
            'message' => $this->sentence(),
            'url' => route('client.documents.index'),
        ];
    }

    public function toBroadcast(object $notifiable): BroadcastMessage
    {
        return new BroadcastMessage($this->toArray($notifiable));
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject(__('Document expiring: :name', ['name' => $this->document->name]))
            ->line($this->sentence())
            ->line(__('Expiry date: :date', ['date' => $this->document->expires_at?->format('d.m.Y') ?? '']))
            ->action(__('Open documents'), route('client.documents.index'));
    }

    /** The same sentence in the bell, in the push and in the mail. */
    private function sentence(): string
    {
        if ($this->daysBefore === 0) {
            return __(':name expires today.', ['name' => $this->document->name]);
        }

        return trans_choice(':count day(s) until :name expires.', $this->daysBefore, [
            'name' => $this->document->name,
        ]);
    }

    private function isEnabled(object $notifiable, string $channel): bool
    {
        $pref = NotificationPreference::where('user_id', $notifiable->id)
            ->where('event', 'document_expiring')
            ->where('channel', $channel)
            ->first();

        return $pref === null || $pref->enabled;
    }
}
