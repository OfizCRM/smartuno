<?php

namespace App\Modules\Offers\Jobs;

use App\Models\ClientProfile;
use App\Models\ClientSetting;
use App\Modules\Catalog\Models\CatalogItem;
use App\Modules\Offers\Exceptions\OfferDraftFailed;
use App\Modules\Offers\Models\Offer;
use App\Modules\Offers\Models\OfferDraftAttempt;
use App\Modules\Offers\Models\OfferItem;
use App\Modules\Offers\Services\OfferDrafter;
use App\Modules\Offers\Services\OfferNumberAllocator;
use App\Modules\Offers\Services\OfferSettings;
use App\Modules\Offers\Services\OfferTotals;
use App\Modules\Shared\Models\Conversation;
use App\Modules\Shared\Models\Message;
use App\Support\Entitlement;
use App\Support\Romania;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Turn a customer's question into a draft offer a person can approve, edit or
 * throw away.
 *
 * Everything expensive happens here and nothing expensive happens in the
 * listener: this is the only place in the stage that talks to a provider, and it
 * runs on the 'ai' queue, away from the 'whatsapp' queue that carries WhatsApp,
 * Messenger and Instagram sends.
 *
 * ─── THE MODEL NEVER TOUCHES MONEY ──────────────────────────────────────────
 *
 * OfferDrafter hands back catalogue ids and quantities. Every figure on the
 * offer is computed here in PHP, by the same OfferTotals a person's hand-built
 * offer goes through, from prices read out of the catalogue table — never from
 * anything the model wrote. What the model proposes as a price is treated as a
 * suggestion and clamped into [min_price_cents, price_cents]; an id it invents
 * is dropped; a bundle it tries to quote as a line is refused.
 *
 * ─── FAILURE IS LOUD, AND THE CUSTOMER HEARS NOTHING ────────────────────────
 *
 * App\Modules\AI\Services\ChatbotRunner is the precedent to avoid: it catches
 * every provider error and sends a fallback sentence to a real customer, so a
 * broken key looks like a working bot saying something useless. Nothing in this
 * job sends anything to anyone. A failure writes 'failed' on the attempt row
 * with a translation key, logs the detail for the operator, and stops. The firm
 * sees the failed attempt on their own screen, in Romanian; the customer is left
 * exactly where they were — waiting for a person, in the inbox, as before.
 *
 * ─── ONE ATTEMPT, NEVER TWO ─────────────────────────────────────────────────
 *
 * $tries is 1, deliberately. A retry of a job that got as far as creating the
 * offer would produce a SECOND offer, with a second number burned out of the
 * firm's series, for one customer question — and offer numbers are the firm's
 * own commercial reference, not something a queue may consume speculatively.
 * The retry that is worth having is the one inside LlmGateway::structured(),
 * which re-asks with a larger budget when the answer came back truncated; that
 * is a retry of the completion, not of the offer.
 */
class DraftOfferJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** See the class docblock: a second run would mean a second offer. */
    public int $tries = 1;

    /**
     * Longer than the 120 every other job here uses, because a multi-constraint
     * structured completion plus one truncation retry legitimately takes longer
     * than a chatbot reply. Safe only because this queue carries nothing a
     * customer is waiting on.
     */
    public int $timeout = 180;

    /** How much of the recent exchange the agent is shown. */
    private const MESSAGE_LIMIT = 12;

    /**
     * How far back to read. An hour, not the whole thread: a customer who asked
     * about parquet in March and about a kitchen today is asking about a kitchen.
     */
    private const MESSAGE_WINDOW_MINUTES = 60;

    /** A quantity the decimal(12,3) column can hold and a firm might mean. */
    private const MAX_QUANTITY = 9999.999;

    /**
     * The shape a throwable's message must have before it is allowed anywhere
     * near a database column: a dotted translation key in lower case, nothing
     * else.
     *
     * LlmGateway::structured() throws exactly this — its five codes are
     * ai.structured.no_provider, .provider_failed, .empty, .cut_off and
     * .unreadable — and it does so precisely so that its caller can store the
     * message. Its docblock is explicit that the provider's own words never
     * qualify, and the pattern is what enforces that here rather than trusting
     * it: OpenAI's 401 body is "Incorrect API key provided: sk-…", which has
     * spaces, a colon and capitals and therefore cannot match. Neither can a
     * stack trace, a SQL error or a customer's message quoted back.
     *
     * Deliberately a pattern and not a list of the five known codes. A sixth
     * code added to the gateway should reach the screen as itself rather than be
     * flattened into "something went wrong" by a list nobody remembered to
     * update — and a pattern that only admits lower-case dotted keys gives away
     * nothing by being open.
     */
    private const REASON_KEY_PATTERN = '/^[a-z][a-z0-9_]*(\.[a-z][a-z0-9_]*){1,3}$/';

    /** Mirrors the listener's gate; re-read here because 45 seconds have passed. */
    private const TOGGLE_KEY = 'offers.ai_drafting_enabled';

    /**
     * @param  int  $attemptId  the offer_draft_attempts row, written by the
     *                          listener before this job was dispatched
     * @param  array<string, mixed>|null  $interpretation  a person's CORRECTED
     *                                                     reading of the request, from "Ceva nu e corect? Corectează" on the
     *                                                     offer screen. When present the drafter must start from this instead
     *                                                     of re-reading the messages — which is the whole point of the
     *                                                     Regenerate button: a misreading becomes a five-second fix rather
     *                                                     than a failure, and re-running from the raw message would just
     *                                                     reproduce the same misreading.
     */
    public function __construct(
        public readonly int $attemptId,
        public readonly ?array $interpretation = null,
    ) {}

    /**
     * The job died outside handle() — a worker killed, an OOM, a timeout.
     *
     * Without this the attempt row stays 'queued' for ever and the failure is
     * invisible in a table created precisely so that a silent miss could not
     * happen. Laravel calls this after the last attempt.
     */
    public function failed(?\Throwable $e): void
    {
        OfferDraftAttempt::query()
            ->whereKey($this->attemptId)
            ->where('status', 'queued')
            ->update([
                'status' => 'failed',
                // A translation key, never the provider's own words: those carry
                // API keys and request bodies.
                'reason' => 'offers.ai_fail_worker',
                'updated_at' => now(),
            ]);
    }

    /**
     * The justifications for the lines that actually made it onto the offer.
     *
     * @param  array<int, mixed>  $proposed
     * @param  array<int, mixed>  $kept
     * @return array<int, mixed>
     */
    private function justified(array $proposed, array $kept): array
    {
        $ids = [];

        foreach ($kept as $line) {
            $id = is_array($line) ? ($line['catalog_item_id'] ?? null) : null;

            if ($id !== null) {
                $ids[(int) $id] = true;
            }
        }

        return array_values(array_filter($proposed, static function (mixed $line) use ($ids): bool {
            $id = is_array($line) ? ($line['catalog_item_id'] ?? null) : null;

            return $id === null || isset($ids[(int) $id]);
        }));
    }

    public function handle(
        OfferTotals $totals,
        OfferNumberAllocator $numbers,
        OfferSettings $offerSettings,
    ): void {
        // The Queue::before hook in AppServiceProvider already does this for a
        // job off the queue; repeated because a direct ->handle() never fires it
        // and a worker up for hours must not answer from a stale verdict.
        Entitlement::forget();

        $attempt = OfferDraftAttempt::query()->find($this->attemptId);

        // Gone, or already resolved. A delayed job whose attempt was deleted, or
        // one that somehow ran twice, must not draft again.
        if (! $attempt || $attempt->status !== OfferDraftAttempt::STATUS_QUEUED) {
            return;
        }

        $workspaceId = (int) $attempt->workspace_id;

        try {
            $this->draft($attempt, $workspaceId, $totals, $numbers, $offerSettings);
        } catch (\Throwable $e) {
            // Not rethrown. The attempt row IS the loud record — it is on the
            // firm's screen with a Romanian reason, and it is indexed on
            // (workspace_id, status) so failures are countable. Rethrowing would
            // add a failed_jobs entry saying the same thing, and would invite a
            // worker started with --tries=3 to override $tries and produce the
            // second offer this job exists to prevent.
            $this->fail($attempt, $this->reasonFor($e), $e);
        }
    }

    /**
     * The whole of the work, so that every exit from it is inside handle()'s
     * catch and therefore leaves a row that says what happened.
     */
    private function draft(
        OfferDraftAttempt $attempt,
        int $workspaceId,
        OfferTotals $totals,
        OfferNumberAllocator $numbers,
        OfferSettings $offerSettings,
    ): void {
        // Tenancy: the conversation is fetched WITH the workspace filter, and
        // everything downstream is scoped from it. The attempt row carries its
        // own workspace_id precisely so this query never needs a join.
        $conversation = Conversation::query()
            ->where('workspace_id', $workspaceId)
            ->find((int) $attempt->conversation_id);

        if (! $conversation) {
            $this->skip($attempt, OfferDraftAttempt::REASON_GONE);

            return;
        }

        // ── The three guards that can have changed during the debounce ───────
        // Forty-five seconds is long enough for a colleague to open the thread,
        // for an owner to switch the agent off, or for a subscription to lapse.
        // Filters 1 and 5 in the listener (direction, intent) cannot change, so
        // they are not re-run.
        if (($conversation->assigned_to ?? 'bot') === 'human') {
            $this->skip($attempt, OfferDraftAttempt::REASON_HANDOVER);

            return;
        }

        $client = Entitlement::clientForWorkspace($workspaceId);
        $clientId = $client === null ? null : (int) $client->getKey();

        if (! $this->enabled($clientId)) {
            $this->skip($attempt, OfferDraftAttempt::REASON_DISABLED);

            return;
        }

        if (Entitlement::state($client) === Entitlement::READONLY) {
            $this->skip($attempt, OfferDraftAttempt::REASON_READONLY);

            return;
        }

        $messages = $this->recentMessages($conversation);

        if ($messages->isEmpty()) {
            $this->skip($attempt, OfferDraftAttempt::REASON_GONE);

            return;
        }

        // Resolved here rather than injected into handle(): a container failure
        // on an injected argument happens BEFORE the method body, so it would
        // escape the catch and leave this row stuck at 'queued' for ever —
        // exactly the silent miss offer_draft_attempts exists to make impossible.
        // Resolved inside the try, a broken drafter is a failed attempt with a
        // reason on the screen.
        //
        // The empty catalogue, the message with no readable text and every
        // provider failure are the drafter's own OfferDraftFailed, each carrying
        // the translation key this row stores — so none of them is re-checked
        // here. A second copy of that logic would be a second answer to the same
        // question, and the two would drift.
        $drafter = app(OfferDrafter::class);

        $result = $drafter->draft($conversation, $messages, $this->interpretation);

        $tokens = max(0, $result['tokens']);

        // ── The model's answer, checked against the catalogue AGAIN ──────────
        // The drafter has already validated; this is the last code that runs
        // before a price reaches a row a customer can be shown, and it is scoped
        // on workspace_id. See validateLines().
        [$lines, $dropped, $warnings] = $this->validateLines($workspaceId, $result['lines']);

        if ($lines === []) {
            // 'Nothing suitable in the catalogue' is the drafter's own answer
            // when it looked and found nothing; anything else that empties the
            // list is this guard refusing what came back.
            $this->fail(
                $attempt,
                $result['reason_key'] ?? OfferDraftAttempt::REASON_NO_MATCH,
                null,
                $tokens,
            );

            return;
        }

        $settings = $offerSettings->get($clientId);
        $profile = $clientId === null
            ? null
            : ClientProfile::query()->where('client_id', $clientId)->first();

        $aiJson = [
            // What the agent understood — the editable block on the offer screen.
            // The drafter has already laid a person's correction over the model's
            // reading field by field, so this is the merged version and the next
            // Regenerate starts from it.
            'interpretation' => $result['interpretation'],
            'corrected' => $this->interpretation !== null,
            // One sentence, and then the per-line justifications the screen draws
            // as bullets under "DE CE A ALES ACESTE PRODUSE".
            'summary' => $result['summary'],
            // Only the lines that survived validateLines(). Storing the
            // drafter's whole proposal meant "DE CE A ALES ACESTE PRODUSE"
            // argued the case for a product the job had already refused — a
            // bullet for something the customer will never see on the offer.
            'lines' => $this->justified($result['lines'], $lines),
            // Everything refused, and everything kept but worth a second look —
            // the drafter's own, plus anything this guard added.
            'dropped' => array_merge($result['dropped'], $dropped),
            'warnings' => array_merge($result['warnings'], $warnings),
            'catalogue' => $result['catalogue'],
            // The customer's own words, by id, so the screen can show the request
            // verbatim without guessing which messages the draft came from.
            'source_message_ids' => $messages->map(
                static fn (Message $m): int => (int) $m->getAttribute('id')
            )->values()->all(),
            'model' => $result['model'],
            'tokens' => $tokens,
            'drafted_at' => now()->toIso8601String(),
        ];

        $offer = DB::transaction(function () use (
            $attempt, $workspaceId, $conversation, $messages, $lines,
            $settings, $profile, $aiJson, $totals, $numbers,
        ): Offer {
            $offer = $this->existingDraft($attempt, $workspaceId);

            if ($offer === null) {
                $offer = new Offer;
                $offer->forceFill([
                    'workspace_id' => $workspaceId,
                    // Allocated inside the transaction, so a draft that fails to
                    // save does not burn a number out of the firm's series.
                    'number' => $numbers->next($workspaceId),
                    'contact_id' => $conversation->getAttribute('contact_id'),
                    'conversation_id' => (int) $conversation->id,
                    'channel' => $this->channelOf($messages),
                    'status' => 'draft',
                    // The badge on the list, and the left column on the offer
                    // screen, both hang off this one value.
                    'source' => 'ai',
                    'currency' => 'RON',
                    // Snapshotted now, exactly as the hand-built path does it: a
                    // firm that registers for VAT next month has not changed the
                    // offer drafted today.
                    'vat_status' => $profile?->vat_status,
                    'vat_rate' => $this->vatRateFor($profile),
                    'valid_until' => now()->addDays((int) $settings['validity_days'])->toDateString(),
                    // No user: nobody pressed a button. The screen reads source
                    // to say who prepared it.
                    'created_by' => null,
                ])->save();
            }

            $offer->forceFill([
                'ai_json' => $aiJson,
                // The one sentence, in a text column of its own, so the reasoning
                // is legible in the row itself and not only after a JSON decode.
                'ai_reason' => $aiJson['summary'] === '' ? null : $aiJson['summary'],
            ])->save();

            $this->replaceItems($offer, $workspaceId, $lines, $settings, $totals);
            $this->recalculate($offer, $settings, $totals);

            return $offer;
        });

        $attempt->forceFill([
            'status' => OfferDraftAttempt::STATUS_DRAFTED,
            'reason' => null,
            'offer_id' => (int) $offer->id,
            // Accumulated, not replaced: a regenerate costs the firm again, and
            // the question "what has this draft cost me" is about the total.
            'tokens' => (int) $attempt->tokens + $tokens,
        ])->save();

        Log::info('Offer drafted by agent.', [
            'workspace_id' => $workspaceId,
            'attempt_id' => (int) $attempt->id,
            'offer_id' => (int) $offer->id,
            'lines' => count($lines),
            'tokens' => $tokens,
        ]);
    }

    // ─────────────────────────────────────────────────────── the input

    /**
     * The recent half of the conversation, oldest first.
     *
     * Handed to the drafter as models rather than as an array of strings,
     * because that is the shape OfferDrafter::draft() declares — and because it
     * filters the collection itself (inbound only, this conversation only,
     * non-empty body, sorted by id and not by the provider's clock). Reshaping
     * it here would mean two places deciding what the agent gets to read.
     *
     * Scoped by conversation_id alone: messages carry no workspace_id, and the
     * conversation this reads from was fetched WITH the workspace filter, which
     * is where the tenancy lives.
     *
     * @return Collection<int, Message>
     */
    private function recentMessages(Conversation $conversation): Collection
    {
        return Message::query()
            ->where('conversation_id', $conversation->id)
            ->where('direction', 'in')
            ->where('created_at', '>=', now()->subMinutes(self::MESSAGE_WINDOW_MINUTES))
            // Newest first with a LIMIT, then reversed: the alternative reads the
            // whole thread to keep the last twelve of it.
            ->orderByDesc('id')
            ->limit(self::MESSAGE_LIMIT)
            ->get()
            ->reverse()
            ->values();
    }

    /**
     * Which channel the offer will go back out on: the one the customer last
     * wrote in on.
     *
     * @param  Collection<int, Message>  $messages
     */
    private function channelOf(Collection $messages): ?string
    {
        $last = $messages->last();

        if (! $last instanceof Message) {
            return null;
        }

        $channel = $last->getAttribute('channel');

        return is_string($channel) && $channel !== '' ? $channel : null;
    }

    // ─────────────────────────────────────────────── the model's answer

    /**
     * The proposed lines, checked against this workspace's own catalogue AGAIN.
     *
     * THE DRAFTER ALREADY DID THIS, and it is still done here. Not out of
     * distrust of that class, but because this is the last code that runs before
     * a price reaches a row a customer can be shown, and because the two guards
     * answer to different things: the drafter's protects the offer's quality,
     * this one protects the tenant boundary and the money. Tenancy in this
     * codebase is manual — one forgotten where clause is a cross-tenant leak —
     * so the query that resolves every id the model returned is scoped on
     * workspace_id here, in the file that writes the row.
     *
     * Four refusals:
     *
     *   - an id that is not in THIS workspace's catalogue. A model that invents
     *     an id, or repeats one from its training, must not be able to reach
     *     another tenant's product.
     *   - an item the firm has switched off. Inactive means "do not sell this".
     *   - a bundle quoted as a line. Stage 3's rule: a bundle is a list of
     *     components, and quoting it as one line hides what the customer is
     *     buying. Expanding it is the drafter's job; refusing an unexpanded one
     *     is this one's.
     *   - a price outside [min_price_cents, price_cents]. The floor is what the
     *     firm said it will not go below; the ceiling is its own list price,
     *     because a draft that quotes ABOVE list is a customer who feels cheated
     *     the moment they check the website.
     *
     * An out-of-stock line is FLAGGED and kept, never dropped — whether to quote
     * a lead time or turn the enquiry away is the firm's decision, not the
     * agent's.
     *
     * Notes are shaped {key, name, detail} to match what the drafter already
     * returns, so the screen renders one list and not two.
     *
     * @param  array<int, array<string, mixed>>  $proposed  the drafter's lines
     * @return array{0: list<array<string, mixed>>, 1: list<array{key: string, name: string, detail: string}>, 2: list<array{key: string, name: string, detail: string}>}
     */
    private function validateLines(int $workspaceId, array $proposed): array
    {
        $ids = [];
        foreach ($proposed as $line) {
            $id = (int) ($line['catalog_item_id'] ?? 0);
            if ($id > 0) {
                $ids[$id] = $id;
            }
        }

        /** @var Collection<int, CatalogItem> $items */
        $items = $ids === []
            ? collect()
            : CatalogItem::query()
                ->where('workspace_id', $workspaceId)
                ->whereIn('id', array_values($ids))
                ->get()
                ->keyBy('id');

        $lines = [];
        $dropped = [];
        $warnings = [];

        foreach ($proposed as $line) {
            $id = (int) ($line['catalog_item_id'] ?? 0);
            $item = $id > 0 ? $items->get($id) : null;
            $label = trim((string) ($line['name'] ?? ''));

            if (! $item instanceof CatalogItem || ! $item->is_active) {
                $dropped[] = [
                    'key' => OfferDrafter::DROP_UNKNOWN_ITEM,
                    'name' => $label,
                    'detail' => '',
                ];

                continue;
            }

            $name = (string) $item->getAttribute('name');

            if ($item->type === 'bundle') {
                $dropped[] = [
                    'key' => OfferDrafter::DROP_BUNDLE_INCOMPLETE,
                    'name' => $name,
                    'detail' => '',
                ];

                continue;
            }

            $quantity = $this->quantity($line['quantity'] ?? 1);
            [$unitPrice, $clamped] = $this->unitPrice($item, $line['unit_price_cents'] ?? null);

            if ($clamped) {
                $warnings[] = [
                    'key' => OfferDrafter::WARN_PRICE_CLAMPED,
                    'name' => $name,
                    'detail' => '',
                ];
            }

            $stock = $item->getAttribute('stock');

            if ($item->type === 'product' && $stock !== null && (float) $stock < (float) $quantity) {
                $warnings[] = [
                    'key' => OfferDrafter::WARN_OUT_OF_STOCK,
                    'name' => $name,
                    'detail' => '',
                ];
            }

            $unit = trim((string) $item->getAttribute('unit'));

            $lines[] = [
                'catalog_item_id' => $id,
                // Snapshots from the catalogue row, never from the model. The
                // name a customer reads is the firm's own wording.
                'name' => $name,
                'unit' => $unit === '' ? 'buc' : $unit,
                'quantity' => $quantity,
                'unit_price_cents' => $unitPrice,
            ];
        }

        return [$lines, $dropped, $warnings];
    }

    /**
     * A quantity the decimal(12,3) column can hold.
     *
     * Returned as a fixed-scale string, which is what the column stores and what
     * OfferTotals expects — a float here is a quantity that drifts, and a
     * drifting quantity is a total the customer can see is wrong.
     */
    private function quantity(mixed $value): string
    {
        $number = is_numeric($value) ? (float) $value : 1.0;

        if ($number <= 0.0) {
            $number = 1.0;
        }

        return number_format(min($number, self::MAX_QUANTITY), 3, '.', '');
    }

    /**
     * The unit price, clamped into the band the firm set.
     *
     * @return array{0: int, 1: bool} the price, and whether the model's proposal
     *                                had to be moved to get there
     */
    private function unitPrice(CatalogItem $item, mixed $proposed): array
    {
        $list = max(0, (int) $item->price_cents);
        $floor = max(0, (int) $item->min_price_cents);

        // Bad catalogue data — a floor above the list price — is not a licence to
        // quote either of them arbitrarily. The list price is the safer of the
        // two, because it is the one the firm publishes.
        if ($floor > $list) {
            $floor = $list;
        }

        if (! is_numeric($proposed)) {
            return [$list, false];
        }

        $wanted = (int) round((float) $proposed);
        $clampedValue = max($floor, min($list, $wanted));

        return [$clampedValue, $clampedValue !== $wanted];
    }

    // ─────────────────────────────────────────────────────── the offer

    /**
     * The draft this attempt already produced, when it is being regenerated.
     *
     * REGENERATE REUSES THE OFFER, and that is a product decision, not an
     * optimisation. The number is the firm's own commercial reference and may
     * already have been said on the phone; a regenerate that allocated a new one
     * would leave the customer holding a number that no longer exists.
     *
     * Only ever a draft this job itself wrote, still unsent. A sent offer, a
     * hand-built one, or one in another workspace is not touched — the second
     * check is the tenancy one and the first two are the "do not rewrite what a
     * person already sent" one.
     */
    private function existingDraft(OfferDraftAttempt $attempt, int $workspaceId): ?Offer
    {
        $offerId = $attempt->offer_id;

        if ($offerId === null) {
            return null;
        }

        return Offer::query()
            ->where('workspace_id', $workspaceId)
            ->where('id', $offerId)
            ->where('source', 'ai')
            ->where('status', 'draft')
            ->whereNull('sent_at')
            ->first();
    }

    /**
     * Write the lines, priced by OfferTotals.
     *
     * The same shape as OfferController::replaceItems, and for the same reason:
     * the per-line figure has to come out of the one integer routine that also
     * produces the subtotal, or the printed column stops adding up to the
     * printed total on exactly the trade quantities the decimal column exists
     * for. The only difference is added_by.
     *
     * @param  list<array<string, mixed>>  $lines
     * @param  array<string, mixed>  $settings
     */
    private function replaceItems(Offer $offer, int $workspaceId, array $lines, array $settings, OfferTotals $totals): void
    {
        $priced = $totals->compute(
            $lines,
            $settings,
            $offer->vat_status,
            $offer->vat_rate === null ? null : (float) $offer->vat_rate,
        );
        $lineTotals = array_map(static fn (array $l): int => (int) $l['line_total_cents'], $priced['lines']);

        // A regenerate replaces the lines wholesale. Scoped on both columns: the
        // offer id alone would be enough, and filtering on the workspace too is
        // what makes a mis-set offer_id a no-op rather than another tenant's
        // lines disappearing.
        OfferItem::query()
            ->where('workspace_id', $workspaceId)
            ->where('offer_id', $offer->id)
            ->delete();

        if ($lines === []) {
            return;
        }

        $now = now();
        $rows = [];

        foreach ($lines as $position => $line) {
            $rows[] = [
                'workspace_id' => $workspaceId,
                'offer_id' => (int) $offer->id,
                'catalog_item_id' => $line['catalog_item_id'],
                'name' => $line['name'],
                'unit' => $line['unit'],
                'quantity' => $line['quantity'],
                'unit_price_cents' => $line['unit_price_cents'],
                'line_total_cents' => $lineTotals[$position] ?? 0,
                'position' => $position,
                // The one column that differs from the hand-built path. It is
                // what lets the screen mark which lines a person kept and which
                // the agent proposed.
                'added_by' => 'ai',
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        OfferItem::query()->insert($rows);
    }

    /**
     * Recompute the money from what was actually stored, so what is on the row
     * is always a function of what is in offer_items.
     *
     * @param  array<string, mixed>  $settings
     */
    private function recalculate(Offer $offer, array $settings, OfferTotals $totals): void
    {
        $lines = OfferItem::query()
            ->where('workspace_id', $offer->workspace_id)
            ->where('offer_id', $offer->id)
            ->orderBy('position')
            ->get()
            ->map(static fn (OfferItem $item): array => [
                // The exact decimal string MySQL returned — casting decimal(12,3)
                // through a float and back is the drift this column was left
                // uncast to avoid.
                'quantity' => (string) $item->getAttribute('quantity'),
                'unit_price_cents' => (int) $item->unit_price_cents,
            ])
            ->all();

        $computed = $totals->compute(
            $lines,
            $settings,
            $offer->vat_status,
            $offer->vat_rate === null ? null : (float) $offer->vat_rate,
        );

        $offer->forceFill([
            'subtotal_cents' => (int) $computed['subtotal_cents'],
            'discount_cents' => (int) $computed['discount_cents'],
            'shipping_cents' => (int) $computed['shipping_cents'],
            'vat_cents' => (int) $computed['vat_cents'],
            'total_cents' => (int) $computed['total_cents'],
        ])->save();
    }

    /** The seller's own rate, or the legislated standard one. */
    private function vatRateFor(?ClientProfile $profile): ?float
    {
        if ($profile === null || $profile->vat_status === null) {
            return null;
        }

        if ($profile->vat_rate !== null) {
            return (float) $profile->vat_rate;
        }

        return Romania::standardVatRate();
    }

    // ─────────────────────────────────────────────────────── outcomes

    /**
     * Nothing was produced and something went wrong.
     *
     * The reason is a translation key, never a provider body: a provider error
     * can echo the prompt back, and the prompt carries the customer's words and
     * the firm's floor prices. The detail goes to the log, where an operator can
     * read it and a customer cannot.
     */
    private function fail(OfferDraftAttempt $attempt, string $reason, ?\Throwable $e = null, int $tokens = 0): void
    {
        try {
            $attempt->forceFill([
                'status' => OfferDraftAttempt::STATUS_FAILED,
                'reason' => $reason,
                // A failed draft is still a paid one when the provider answered.
                // Leaving the tokens off would make the feature look free
                // whenever it did not work, which is exactly backwards.
                'tokens' => (int) $attempt->tokens + max(0, $tokens),
            ])->save();
        } catch (\Throwable $writeError) {
            Log::error('Offer draft attempt could not be marked failed.', [
                'attempt_id' => $this->attemptId,
                'exception' => get_class($writeError),
                'message' => $writeError->getMessage(),
            ]);
        }

        Log::error('Offer drafting failed; nothing was sent to the customer.', [
            'workspace_id' => (int) $attempt->workspace_id,
            'attempt_id' => (int) $attempt->id,
            'conversation_id' => (int) $attempt->conversation_id,
            'reason' => $reason,
            'exception' => $e === null ? null : get_class($e),
            'message' => $e?->getMessage(),
            'file' => $e?->getFile(),
            'line' => $e?->getLine(),
        ]);
    }

    /**
     * Nothing was produced and nothing went wrong — the answer is simply no
     * longer wanted. Info, not error: a colleague picking up a thread is the
     * system working.
     */
    private function skip(OfferDraftAttempt $attempt, string $reason): void
    {
        $attempt->forceFill([
            'status' => OfferDraftAttempt::STATUS_SKIPPED,
            'reason' => $reason,
        ])->save();

        Log::info('Offer draft skipped.', [
            'workspace_id' => (int) $attempt->workspace_id,
            'attempt_id' => (int) $attempt->id,
            'reason' => $reason,
        ]);
    }

    /**
     * Which Romanian sentence the firm is shown for this failure.
     *
     * The gateway and the drafter say why they failed in the exception message,
     * as a translation key. Anything that does not look like one is bucketed
     * rather than stored, because a message that is not a key is a message that
     * might be a secret.
     */
    private function reasonFor(\Throwable $e): string
    {
        // The drafter's own failures carry the key as a typed property, which is
        // the contract to prefer: it cannot be confused with prose.
        if ($e instanceof OfferDraftFailed && $this->looksLikeAKey($e->reasonKey)) {
            return $e->reasonKey;
        }

        // Anything else — including LlmGateway::structured()'s five codes when
        // they reach here unwrapped — is admitted only if it has the shape.
        if ($this->looksLikeAKey($e->getMessage())) {
            return trim($e->getMessage());
        }

        return OfferDraftAttempt::REASON_UNEXPECTED;
    }

    /** @see self::REASON_KEY_PATTERN */
    private function looksLikeAKey(string $value): bool
    {
        $value = trim($value);

        return $value !== ''
            && strlen($value) <= 64
            && preg_match(self::REASON_KEY_PATTERN, $value) === 1;
    }

    /**
     * Whether the firm still has drafting switched on. Read straight from
     * ClientSetting for the reasons the listener documents; filter_var and not a
     * cast because the string "false" is truthy in PHP.
     */
    private function enabled(?int $clientId): bool
    {
        if ($clientId === null || $clientId <= 0) {
            return false;
        }

        $value = ClientSetting::get($clientId, self::TOGGLE_KEY, false);

        if (is_bool($value)) {
            return $value;
        }

        return filter_var((string) $value, FILTER_VALIDATE_BOOLEAN);
    }
}
