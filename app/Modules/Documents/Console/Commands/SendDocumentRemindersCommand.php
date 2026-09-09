<?php

namespace App\Modules\Documents\Console\Commands;

use App\Models\User;
use App\Models\Workspace;
use App\Modules\Documents\Models\Document;
use App\Notifications\DocumentExpiringNotification;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Notification;

/**
 * Warns about documents that are running out.
 *
 * Each document carries the offsets it was asked for — say 30 and 7 days — and a
 * record of which have already gone out, so a second run on the same day, or a
 * restart mid-sweep, cannot send the same warning twice.
 *
 * Overdue offsets still fire: a mailbox that was down for a week, or a reminder
 * added after the date was set, must not leave the tenant unwarned.
 */
class SendDocumentRemindersCommand extends Command
{
    protected $signature = 'documents:remind';

    protected $description = 'Warn about documents that are about to expire';

    public function handle(): int
    {
        $sent = 0;

        Document::whereNotNull('expires_at')
            ->whereNotNull('remind_days')
            ->with('creator')
            ->chunkById(100, function ($documents) use (&$sent) {
                foreach ($documents as $document) {
                    $sent += $this->remindFor($document);
                }
            });

        $this->info("Sent {$sent} document reminder(s).");

        return self::SUCCESS;
    }

    private function remindFor(Document $document): int
    {
        $already = array_map('intval', $document->reminders_sent ?? []);
        $due = [];

        foreach (array_map('intval', $document->remind_days ?? []) as $days) {
            if (in_array($days, $already, true)) {
                continue;
            }

            if (now()->startOfDay()->greaterThanOrEqualTo($document->expires_at->copy()->subDays($days)->startOfDay())) {
                $due[] = $days;
            }
        }

        if ($due === []) {
            return 0;
        }

        $recipients = $this->recipients($document);

        // Only the nearest one is sent when several came due at once — a
        // mailbox that was down for a fortnight should not produce a stack of
        // warnings about the same contract.
        $nearest = min($due);

        if ($recipients->isNotEmpty()) {
            Notification::send($recipients, new DocumentExpiringNotification($document, $nearest));
        }

        // Everything that was due is marked, not just the one sent, so the
        // older offsets do not fire again tomorrow.
        $document->forceFill([
            'reminders_sent' => array_values(array_unique(array_merge($already, $due))),
        ])->save();

        return $recipients->isNotEmpty() ? 1 : 0;
    }

    /**
     * Who hears about it: the people who run the firm, plus whoever filed the
     * document if that is someone else.
     *
     * @return Collection<int, User>
     */
    private function recipients(Document $document): Collection
    {
        $clientId = Workspace::whereKey($document->workspace_id)->value('client_id');

        $admins = $clientId
            ? User::where('client_id', $clientId)
                ->where('client_role', User::CLIENT_ROLE_ADMINISTRATOR)
                ->where('status', User::STATUS_ACTIVE)
                ->get()
            : collect();

        if ($document->creator && ! $admins->contains('id', $document->creator->id)) {
            $admins->push($document->creator);
        }

        return $admins;
    }
}
