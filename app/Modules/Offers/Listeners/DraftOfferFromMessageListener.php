<?php

namespace App\Modules\Offers\Listeners;

use App\Events\MessageReceived;
use App\Models\ClientSetting;
use App\Modules\Offers\Jobs\DraftOfferJob;
use App\Modules\Offers\Models\OfferDraftAttempt;
use App\Modules\Shared\Models\Conversation;
use App\Support\Entitlement;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * The hook: an inbound customer message that reads like a request for a price
 * becomes a queued draft offer.
 *
 * ─── WHAT THIS CLASS IS ALLOWED TO DO ───────────────────────────────────────
 *
 * Write one row and dispatch one job. That is the entire contract, and it is
 * deliberately narrower than it looks like it could be.
 *
 * This runs on the inbound message path of all four channels — WhatsApp,
 * Messenger, Instagram and email — which is the product. App\Listeners\
 * AutoReplyListener is the precedent for what must never happen here: it makes a
 * blocking HTTPS call to an LLM, of up to about 180 seconds, inside a listener
 * that runs on the webhook request itself, in a job whose timeout is 120 — and
 * Messenger and Instagram share the 'whatsapp' queue with WhatsApp, so one slow
 * provider stalls three channels' inboxes at once. Nothing in this class may
 * touch the network, and nothing in it may take longer than a keyword scan.
 *
 * ─── WHY IT IS REGISTERED FIRST ─────────────────────────────────────────────
 *
 * Laravel's event dispatcher has no try/catch around listeners: it calls them in
 * registration order and the first one to throw stops the rest. Neither
 * AutomationTriggerListener::handleMessageReceived nor SendNewMessageNotification
 * has a top-level catch, so whichever listener is registered LAST is the one most
 * likely to be silently skipped when an earlier one throws.
 *
 * Registered first, with its entire body inside try/catch, this class can neither
 * be skipped by an upstream failure nor cause one downstream. Its catch is a
 * guarantee to the other three listeners, not to itself.
 *
 * ─── THE FIVE FILTERS, IN THIS ORDER ────────────────────────────────────────
 *
 * All of them server-side, cheapest first, so the common case — an ordinary
 * "mulțumesc" on a firm that has not switched the agent on — costs one string
 * comparison and one memoised lookup.
 *
 *   1. direction === 'in'. NOT optional and not defensive tidiness:
 *      App\Modules\Inbox\Services\InstagramDriver dispatches MessageReceived for
 *      OUTBOUND ECHOES too (line 354, on a Message created with
 *      direction => 'out'), so without this filter every message an operator
 *      types on Instagram would be read as a customer asking for a quote.
 *   2. The thread is not assigned to a human. `assigned_to` is the bot/human
 *      mode flag, defaulting to 'bot' — the same one AutoReplyListener reads.
 *      assigned_user_id is deliberately NOT consulted: that column only says
 *      which colleague owns the thread, and an owned thread is exactly where a
 *      ready-made draft helps most.
 *   3. The per-firm toggle. Default FALSE. This is what makes the riskiest stage
 *      in the plan safe to deploy: it ships switched off and is turned on one
 *      firm at a time, by hand, once their catalogue has been described.
 *   4. Entitlement. A lapsed client must not spend the platform's LLM key —
 *      CredentialResolver falls back to it when the workspace has none.
 *   5. The intent gate. Romanian keywords, diacritic- and case-insensitive.
 *
 * @see DraftOfferJob for everything that costs money.
 */
class DraftOfferFromMessageListener
{
    /**
     * The client setting that switches drafting on for a firm.
     *
     * Read straight from ClientSetting rather than through OfferSettings,
     * because this is a boolean gate and not one of the five money settings that
     * service exists to type; and because a gate that ships FALSE must not
     * depend on being present in someone's DEFAULTS array to stay false.
     *
     * Per client, not per workspace, for the reason OfferSettings already
     * documents: this is the same store the seller's fiscal identity lives in,
     * and workspaces.client_id is nullable. A workspace with no client has
     * nowhere for the setting to live, so it reads false and drafting stays off.
     */
    private const TOGGLE_KEY = 'offers.ai_drafting_enabled';

    /**
     * How long the job waits before it reads the thread.
     *
     * THE DEBOUNCE IS THE POINT. A customer writes "bună ziua", then "aveți
     * parchet stejar?", then "cam 40 mp" — three messages in fifteen seconds,
     * one question. Three drafts would be three offer numbers, three AI bills
     * and three rows on the firm's list for one customer, and the first two
     * would be built from half a sentence.
     *
     * Every lock in this codebase today is per-message-id (AutoReplyListener's
     * auto_reply_lock, AutomationTriggerListener's automation_trigger_lock),
     * which is the opposite of what is needed: those exist to stop the SAME
     * message being handled twice. This one stops DIFFERENT messages in the same
     * thread being handled separately. So it is keyed on the conversation, and
     * the job reads the whole recent exchange when it finally runs.
     */
    private const DEBOUNCE_SECONDS = 45;

    /**
     * How long the conversation-scoped debounce key survives.
     *
     * Longer than the delay so a backed-up 'ai' queue does not let a fourth
     * message open a second attempt before the first job has even started; short
     * enough that a customer who comes back two minutes later with a genuinely
     * new question gets a genuinely new draft.
     */
    private const DEBOUNCE_TTL_SECONDS = 90;

    /**
     * What a Romanian small-business customer types when they want a price.
     *
     * ALREADY NORMALISED — lower case, no diacritics — because both sides of the
     * comparison go through normalise(). Each entry therefore covers every way
     * the phrase is actually typed: 'cat costa' matches "cât costă", "Cat costa"
     * and "CÂT COSTĂ"; 'oferta' matches "ofertă"; 'pret' matches "preț" and
     * "PREȚ"; 'aveti' matches "aveți"; 'as vrea' matches "aș vrea", with the
     * comma-below ș (U+0219) and the cedilla ş (U+015F) alike, because phones
     * and older Windows keyboards disagree about which one they send.
     *
     * WHY KEYWORDS AND NOT A CLASSIFIER. There is no classifier of any kind in
     * this codebase — three places do literal keyword matching and eleven
     * handover phrases are hard-coded in English. An LLM call on every inbound
     * message across four channels would be a provider round trip for every
     * "mulțumesc", "ok" and delivery notification a firm receives, billed to the
     * firm. A keyword gate that misses one request in five is far cheaper than
     * that, and the ones it misses are still in the inbox where they always were.
     *
     * @var list<string>
     */
    private const INTENT_PHRASES = [
        // Asking what something costs.
        'cat costa',
        'cat ma costa',
        'cat ar fi',
        'cat e',
        'ce pret',
        'ce tarif',
        // Inflected forms spelled out rather than a prefix match: "pret" as a
        // prefix also matches "pretind" and "pretentios", so "Nu pretind ca
        // sunt expert" queued a draft offer. Romanian articles are suffixes, so
        // the noun has to be listed with them.
        'pret',
        'pretul',
        'pretului',
        'preturi',
        'preturile',
        'tarif',
        'tariful',
        'tarife',
        'tarifele',
        'cost',
        'costul',
        'costuri',
        'costurile',
        // Asking for the document itself.
        'oferta',
        'oferta,',
        'ofertă',
        'oferte',
        'ofertei',
        'ofertele',
        'deviz',
        'cotatie',
        'estimare',
        // Wanting to buy, said with an object. The bare verbs are deliberately
        // absent: measured against thirty ordinary messages a dental clinic
        // receives, "aveti", "vreau" and "as vrea" on their own fired on
        // twenty-seven of them — "Aveți loc mâine dimineață?", "Vreau să anulez
        // programarea". A gate that passes nine messages in ten is not a gate,
        // it is a pass-through that bills the firm for every "mulțumesc".
        // "aveti in stoc" is a buying signal; the bare "aveti" is not. That
        // distinction is the whole difference between a gate and a pass-through.
        'aveti in stoc',
        'ai in stoc',
        'mai aveti in stoc',
        'in stoc',
        // 'disponibil' on its own is gone: "Sunteți disponibil joi?" asks about
        // the person, not the product.
        'vreau sa cumpar',
        'as vrea sa cumpar',
        'vreau sa comand',
        'as dori o oferta',
        'as vrea o oferta',
        'vreau o oferta',
        'imi faceti o oferta',
        'puteti sa imi faceti',
    ];

    /**
     * Romanian diacritics, both encodings of ș and ț.
     *
     * The comma-below forms (U+0219, U+021B) are the correct ones; the cedilla
     * forms (U+015F, U+0163) are what a great deal of software still sends, and
     * a gate that only knew the correct ones would miss half the messages it
     * exists to catch.
     *
     * @var array<string, string>
     */
    private const DIACRITICS = [
        'ă' => 'a', 'â' => 'a', 'î' => 'i',
        'ș' => 's', 'ş' => 's',
        'ț' => 't', 'ţ' => 't',
    ];

    /**
     * The whole body is inside the catch, on purpose.
     *
     * Three other listeners run on this event, one of which puts the message in
     * front of the person waiting for it. Nothing this class can get wrong — a
     * missing conversation, a cache backend that is down, a duplicate row, a bug
     * in the intent gate — may stop a customer's message reaching the inbox.
     */
    public function handle(MessageReceived $event): void
    {
        try {
            $this->process($event);
        } catch (\Throwable $e) {
            // Ids and the exception, never the body: this message is a customer's
            // own words and may carry their name, address or order number.
            Log::error('DraftOfferFromMessageListener failed; inbound path unaffected.', [
                'message_id' => $event->message->id ?? null,
                'conversation_id' => $event->message->conversation_id ?? null,
                'exception' => get_class($e),
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);
        }
    }

    /**
     * Protected, not private, and only for one reason: the guarantee this class
     * makes to the other three listeners is that nothing thrown in here can
     * reach the dispatcher, and a guarantee that cannot be tested is a comment.
     * DraftOfferInboundResilienceTest replaces this method with one that throws
     * and then proves the rest of the inbound path still ran.
     */
    protected function process(MessageReceived $event): void
    {
        $message = $event->message;

        // ── 1. Inbound only ──────────────────────────────────────────────────
        // InstagramDriver dispatches this same event for outbound echoes. See
        // the class docblock.
        if (($message->direction ?? 'in') !== 'in') {
            return;
        }

        $body = (string) ($message->body ?? '');
        if (trim($body) === '') {
            return;
        }

        // ── 2. It reads like a request for a price ───────────────────────────
        //
        // Hoisted above every database read on purpose. It is the only free
        // filter and it rejects the overwhelming majority of inbound traffic, so
        // an ordinary "mulțumesc" costs one string comparison instead of six
        // queries — paid on every message a firm receives, for ever.
        if (! $this->looksLikeAPriceRequest($body)) {
            return;
        }

        $messageId = (int) ($message->id ?? 0);
        if ($messageId <= 0) {
            return;
        }

        $conversation = $message->conversation;
        if (! $conversation instanceof Conversation) {
            return;
        }

        $workspaceId = (int) ($conversation->workspace_id ?? 0);
        if ($workspaceId <= 0) {
            return;
        }

        // ── 2. Not already in a person's hands ───────────────────────────────
        if (($conversation->assigned_to ?? 'bot') === 'human') {
            return;
        }

        // One lookup, memoised, reused by filters 3 and 4. Asking twice would be
        // two queries and, worse, two chances to disagree within one message.
        $client = Entitlement::clientForWorkspace($workspaceId);
        $clientId = $client === null ? null : (int) $client->getKey();

        // ── 3. The firm has switched drafting on ─────────────────────────────
        if (! $this->enabled($clientId)) {
            return;
        }

        // ── 4. The client is still entitled ──────────────────────────────────
        // Drafting spends the LLM key, and CredentialResolver falls back to the
        // platform's when the workspace has none — so a lapsed client would
        // quietly spend the owner's credit on every inbound question. The
        // message itself is already stored and already in the inbox; what stops
        // is the app doing paid work on the tenant's behalf.
        if (Entitlement::state($client) === Entitlement::READONLY) {
            // One line per client per hour, not one per message: a blocked
            // tenant keeps receiving messages all day. Ids only.
            if (Cache::add("entitlement_block_log:offer_draft:{$clientId}", 1, 3600)) {
                Log::warning('Offer drafting suppressed: client entitlement is read-only.', [
                    'workspace_id' => $workspaceId,
                    'client_id' => $clientId,
                ]);
            }

            return;
        }

        // ── Debounce, conversation-scoped ────────────────────────────────────
        // First message in wins and its job reads the whole thread when it runs.
        // Cache::add is atomic, so two workers handling two messages of the same
        // burst cannot both pass.
        if (! Cache::add("offer_draft_debounce:{$conversation->id}", $messageId, self::DEBOUNCE_TTL_SECONDS)) {
            return;
        }

        // ── The row, then the job — never the other way round ────────────────
        // WhatsappDriver catches every throwable from inbound processing and
        // only logs it, so a job that is dispatched and then lost is lost for
        // good: nothing retries, and nothing records that the customer ever
        // asked. The row is written first, on the synchronous path, so the
        // attempt exists even if everything after this line fails.
        try {
            $attempt = new OfferDraftAttempt;
            $attempt->forceFill([
                'workspace_id' => $workspaceId,
                'conversation_id' => (int) $conversation->id,
                'message_id' => $messageId,
                'status' => OfferDraftAttempt::STATUS_QUEUED,
                'reason' => null,
                'offer_id' => null,
                'tokens' => 0,
            ])->save();
        } catch (UniqueConstraintViolationException) {
            // The unique index on message_id did its job: this message has been
            // seen before, on a webhook Meta redelivered. Not an error, and not
            // a second draft.
            return;
        }

        // Onto 'ai', never 'whatsapp' and never inline. The drafting job holds an
        // LLM connection open for as long as the provider takes; putting that on
        // the queue that carries WhatsApp, Messenger and Instagram sends would
        // stall three channels behind one slow completion.
        //
        // On the sync driver ->delay() is ignored and the job runs inline here,
        // inside this listener's try/catch. That is correct rather than merely
        // tolerable: a sync install has no worker to debounce towards, and the
        // catch above still keeps the failure away from the other listeners.
        DraftOfferJob::dispatch($attempt->id)
            ->onQueue('ai')
            ->delay(now()->addSeconds(self::DEBOUNCE_SECONDS));
    }

    /**
     * Whether this firm has switched drafting on. Absent, empty, or no client at
     * all — all of them mean off.
     *
     * filter_var and not a cast: client_settings.value is a text column, and the
     * string "false" is truthy in PHP. A boolean gate that opens on the string
     * "false" is exactly the kind of bug that ships a feature to every customer
     * at once.
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

    /**
     * Does this message read like someone asking what something costs?
     *
     * Substring matching on the normalised body, which is what the three
     * existing keyword matchers in this codebase do. It over-matches a little —
     * "pret" is inside "pretinde" — and that is the right side to err on: an
     * unwanted draft is a row a person discards in one click, a missed one is a
     * customer who waits.
     */
    private function looksLikeAPriceRequest(string $body): bool
    {
        $normalised = $this->normalise($body);

        if ($normalised === '') {
            return false;
        }

        // Whole words only. str_contains matched "pret" inside "pretind" and
        // "pretentios", so "Nu pretind ca sunt expert" queued a draft offer.
        // The phrases are ASCII by the time they get here — normalise() has
        // already folded the diacritics — so \b is safe.
        foreach (self::INTENT_PHRASES as $phrase) {
            if (preg_match('/\b'.preg_quote($phrase, '/').'\b/', $normalised) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * Lower case, no Romanian diacritics, single spaces.
     *
     * mb_strtolower before the diacritic map, so "CÂT COSTĂ" is folded to "cât
     * costă" and then to "cat costa" — the map only carries lower-case keys
     * because doing it in the other order would need twice as many.
     */
    private function normalise(string $text): string
    {
        $lower = mb_strtolower($text, 'UTF-8');
        $stripped = strtr($lower, self::DIACRITICS);

        return trim((string) preg_replace('/\s+/u', ' ', $stripped));
    }
}
