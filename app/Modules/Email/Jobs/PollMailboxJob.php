<?php

namespace App\Modules\Email\Jobs;

use App\Modules\Ecommerce\Services\StoreUrlGuard;
use App\Modules\Email\Services\AttachmentStore;
use App\Modules\Email\Services\InboundMailParser;
use App\Modules\Email\Services\MailboxIngestor;
use App\Modules\Shared\Models\ChannelAccount;
use App\Support\Entitlement;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Webklex\PHPIMAP\ClientManager;

/**
 * Reads one mailbox and files whatever is new into the inbox.
 *
 * Never marks anything as read and never moves or deletes: the settings page
 * promises the tenant's mailbox is left alone, and a person reading the same
 * mail in Thunderbird should not find it already opened. Progress is tracked by
 * IMAP UID instead of the \Seen flag.
 *
 * Dispatched on the `default` queue on purpose. There is no `email` queue and no
 * worker consuming one, so a job sent there would sit in the table for ever with
 * nothing to tell anybody.
 */
class PollMailboxJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** A single pass takes at most this many messages, so one neglected mailbox cannot hold the queue. */
    private const BATCH = 50;

    public int $tries = 2;

    public int $timeout = 120;

    /**
     * Set once the IMAP session is open, so a failure after that point does not
     * tell the tenant to go and check settings that are already correct.
     */
    private bool $connected = false;

    public function __construct(public readonly int $mailboxId) {}

    public function handle(InboundMailParser $parser, MailboxIngestor $ingestor): void
    {
        $mailbox = ChannelAccount::find($this->mailboxId);
        if (! $mailbox || $mailbox->channel !== 'email') {
            return;
        }

        // A lapsed customer's mailbox is not polled: nothing here passes through
        // middleware, so without this the owner keeps paying to read the mail of
        // an account that stopped paying months ago.
        $client = Entitlement::clientForWorkspace((int) $mailbox->workspace_id);
        if (Entitlement::state($client) === Entitlement::READONLY) {
            return;
        }

        $credentials = $mailbox->getAttribute('credentials') ?? [];
        $imap = $credentials['imap'] ?? [];

        // Re-checked here and not only at save time: this is the line that opens
        // the socket, and the row could have been written by anything.
        if (StoreUrlGuard::guardHost((string) ($imap['host'] ?? '')) !== null) {
            $this->markError($mailbox, __('Host must be a public address (private and internal addresses are blocked).'));

            return;
        }

        try {
            $this->poll($mailbox, $imap, $parser, $ingestor);
        } catch (\Throwable $e) {
            // error, not warning: a mailbox that has stopped working is the kind
            // of thing an operator must see, and a production LOG_LEVEL of
            // `error` would swallow anything quieter.
            Log::error('Mailbox poll failed', ['mailbox_id' => $mailbox->id, 'error' => $e->getMessage()]);

            // The tenant sees this on the settings page; a mailbox that has
            // stopped working must say so rather than simply going quiet.
            $this->markError($mailbox, $this->connected
                ? __('Connected, but the mailbox could not be read. It will be retried automatically.')
                : __('Could not connect. Check the host, the port and the encryption setting.'));
        }
    }

    /** @param array<string, mixed> $imap */
    private function poll(ChannelAccount $mailbox, array $imap, InboundMailParser $parser, MailboxIngestor $ingestor): void
    {
        $meta = $mailbox->getAttribute('meta_json') ?? [];

        // The library's default header decoder calls imap_utf8(), and ext-imap was
        // removed from PHP in 8.4 — so on this platform it hands back
        // "=?UTF-8?B?...?=" untouched for every subject, sender name and
        // attachment filename. 'iconv' is the branch that decodes without the
        // extension. It belongs on the manager and not in the array below:
        // ClientManager::make() files that array under `accounts` and reads
        // `decoding` only from its own configuration.
        $manager = new ClientManager([
            'decoding' => ['options' => ['header' => 'iconv']],
        ]);

        $client = $manager->make([
            'host' => $imap['host'],
            'port' => (int) $imap['port'],
            'protocol' => 'imap',
            'encryption' => ($imap['encryption'] ?? 'ssl') === 'none' ? false : $imap['encryption'],
            'validate_cert' => true,
            'username' => $imap['username'],
            'password' => $imap['password'],
            'authentication' => null,
        ]);

        // The library writes PHP warnings straight to output on a socket problem.
        $previous = set_error_handler(static fn () => true);

        try {
            $client->connect();
            $this->connected = true;
            $folder = $client->getFolder('INBOX');

            $query = $folder->query()->leaveUnread()->setFetchOrderAsc()->limit(self::BATCH);
            $lastUid = (int) ($meta['last_uid'] ?? 0);

            if ($lastUid > 0) {
                // whereUid() and not getByUidGreater(): the latter reads like a
                // filter on this builder and is not one — it runs its own search,
                // returns a collection, and leaves this query with no criteria at
                // all, so the get() below asks the server for "UID SEARCH" with
                // nothing after it and every poll after the first one fails. It
                // would also pull every id in the mailbox and filter them in PHP.
                $query->whereUid(($lastUid + 1).':*');
            } else {
                // First run: only as far back as the tenant asked for. Importing
                // a ten-year mailbox would bury the inbox in dead history.
                $days = max(0, (int) ($meta['initial_days'] ?? 2));
                $query->whereSince(Carbon::now()->subDays($days)->format('d M Y'));
            }

            $highestUid = $lastUid;

            foreach ($query->get() as $message) {
                $uid = (int) $message->getUid();

                // "5:*" in a mailbox whose highest uid is 4 still returns message
                // 4 — an IMAP range is read from whichever end is lower, so the
                // newest message comes back on every poll until something newer
                // arrives. De-duplication would catch it; skipping is cheaper and
                // keeps the batch limit meaningful.
                if ($uid <= $lastUid) {
                    continue;
                }

                $highestUid = max($highestUid, $uid);
                $this->ingestOne($mailbox, $message, $parser, $ingestor);
            }

            $mailbox->setAttribute('meta_json', array_merge($meta, [
                'last_uid' => $highestUid,
                'last_polled_at' => Carbon::now()->toIso8601String(),
                'last_error' => null,
            ]));
            $mailbox->status = 'active';
            $mailbox->save();
        } finally {
            restore_error_handler();
            if ($previous) {
                set_error_handler($previous);
            }
            try {
                $client->disconnect();
            } catch (\Throwable) {
                // Already closed.
            }
        }
    }

    private function ingestOne(ChannelAccount $mailbox, mixed $message, InboundMailParser $parser, MailboxIngestor $ingestor): void
    {
        try {
            $from = $message->getFrom()[0] ?? null;

            $mail = $parser->parse(
                headers: $this->headers($message),
                fromEmail: (string) ($from->mail ?? ''),
                fromName: ($from->personal ?? '') !== '' ? (string) $from->personal : null,
                subject: (string) $message->getSubject(),
                html: $message->getHTMLBody() ?: null,
                text: $message->getTextBody() ?: null,
                date: Carbon::parse((string) $message->getDate()),
            );
            $mail['attachments'] = $this->storeAttachments($message);

            $ingestor->ingest($mailbox, $mail);
        } catch (\Throwable $e) {
            // One malformed message must not stop the rest of the batch, and it
            // must not be retried for ever: the UID still advances.
            Log::warning('Could not ingest a message', [
                'mailbox_id' => $mailbox->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Keep the files that came with the mail.
     *
     * Capped per file and per message: without a bound, one tenant forwarding a
     * photo album fills a disk every other tenant shares. Anything over the
     * limit is still listed by name, so the thread does not silently pretend
     * nothing was attached.
     *
     * @return array<int, array<string, mixed>>
     */
    private function storeAttachments(mixed $message): array
    {
        $store = app(AttachmentStore::class);
        $kept = [];
        $bytes = 0;

        foreach ($message->getAttachments() as $attachment) {
            try {
                $content = (string) $attachment->getContent();
                $entry = $store->put(
                    (string) ($attachment->getName() ?: 'atasament'),
                    (string) ($attachment->getMimeType() ?: 'application/octet-stream'),
                    $content,
                    $bytes,
                );
                if ($entry['stored']) {
                    $bytes += $entry['size'];
                }
                $kept[] = $entry;
            } catch (\Throwable $e) {
                // The name is kept even though the bytes are not. Dropping the
                // entry entirely — which is what this did — meant the thread
                // showed no trace that "factura.pdf" had ever arrived, and the
                // mailbox UID advanced regardless, so nothing would fetch it
                // again. An attachment we could not store is exactly the thing
                // the person needs to be told about, and this is the same shape
                // AttachmentStore already uses for a file over the size cap.
                Log::warning('Could not store an email attachment', [
                    'name' => (string) ($attachment->getName() ?: 'atasament'),
                    'error' => $e->getMessage(),
                ]);

                $kept[] = [
                    'name' => (string) ($attachment->getName() ?: 'atasament'),
                    'mime' => (string) ($attachment->getMimeType() ?: 'application/octet-stream'),
                    'size' => 0,
                    'path' => null,
                    // The same key every other entry carries, so nothing reading
                    // messages.payload has to work out which shape it is holding
                    // before it knows what is safe to touch. `stored` is what
                    // says there are no bytes; this only says where they would
                    // have gone. AttachmentStore's own over-cap branch does the
                    // same, and this is the other way an entry gets no file.
                    'disk' => $store->diskName(),
                    'stored' => false,
                ];
            }
        }

        return $kept;
    }

    /** @return array<string, string> */
    private function headers(mixed $message): array
    {
        $out = [];
        foreach ((array) $message->getHeader()->getAttributes() as $name => $value) {
            $out[strtolower((string) $name)] = trim((string) $value);
        }

        return $out;
    }

    private function markError(ChannelAccount $mailbox, string $message): void
    {
        $mailbox->setAttribute('meta_json', array_merge($mailbox->getAttribute('meta_json') ?? [], [
            'last_error' => $message,
            'last_polled_at' => Carbon::now()->toIso8601String(),
        ]));
        $mailbox->status = 'error';
        $mailbox->save();
    }
}
