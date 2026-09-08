<?php

namespace App\Modules\Email\Console\Commands;

use App\Modules\Email\Jobs\PollMailboxJob;
use App\Modules\Email\Services\MailboxSettings;
use App\Modules\Shared\Models\ChannelAccount;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Queues a read for every mailbox that is due one.
 *
 * Runs every minute and decides per mailbox, rather than being scheduled per
 * interval: each tenant picks their own cadence, and a single schedule entry is
 * far easier to reason about than four.
 */
class PollMailboxesCommand extends Command
{
    protected $signature = 'email:poll-mailboxes {--force : Ignore each mailbox\'s interval and read them all now}';

    protected $description = 'Read new mail for every connected mailbox that is due';

    public function handle(): int
    {
        $due = 0;

        ChannelAccount::where('channel', 'email')
            ->whereIn('status', ['active', 'error'])
            ->orderBy('id')
            ->chunkById(100, function ($mailboxes) use (&$due) {
                foreach ($mailboxes as $mailbox) {
                    if (! $this->option('force') && ! $this->isDue($mailbox)) {
                        continue;
                    }

                    // 'default' and not a queue of its own: no worker consumes an
                    // 'email' queue on any existing deployment, so a job sent
                    // there would never run and never say so.
                    PollMailboxJob::dispatch((int) $mailbox->id);
                    $due++;
                }
            });

        $this->info("Queued {$due} mailbox read(s).");

        return self::SUCCESS;
    }

    /**
     * A mailbox in the 'error' state is still polled — that is how it recovers
     * once the tenant fixes the password — but no faster than its own interval.
     */
    private function isDue(ChannelAccount $mailbox): bool
    {
        $meta = $mailbox->getAttribute('meta_json') ?? [];
        $last = $meta['last_polled_at'] ?? null;

        if (! $last) {
            return true;
        }

        $minutes = (int) ($meta['poll_minutes'] ?? 10);
        if (! in_array($minutes, MailboxSettings::POLL_CHOICES, true)) {
            $minutes = 10;
        }

        try {
            return Carbon::parse($last)->addMinutes($minutes)->isPast();
        } catch (\Throwable) {
            // An unreadable timestamp must not freeze a mailbox for ever.
            return true;
        }
    }
}
