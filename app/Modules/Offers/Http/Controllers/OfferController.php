<?php

namespace App\Modules\Offers\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\ClientProfile;
use App\Models\ClientSetting;
use App\Modules\Catalog\Models\CatalogItem;
use App\Modules\Catalog\Support\Money;
use App\Modules\Documents\Models\Document;
use App\Modules\Offers\Exceptions\OfferDraftFailed;
use App\Modules\Offers\Models\Offer;
use App\Modules\Offers\Models\OfferDraftAttempt;
use App\Modules\Offers\Models\OfferItem;
use App\Modules\Offers\Services\OfferDrafter;
use App\Modules\Offers\Services\OfferNumberAllocator;
use App\Modules\Offers\Services\OfferPdfRenderer;
use App\Modules\Offers\Services\OfferSender;
use App\Modules\Offers\Services\OfferSettings;
use App\Modules\Offers\Services\OfferTotals;
use App\Modules\Shared\Models\Contact;
use App\Modules\Shared\Models\Conversation;
use App\Modules\Shared\Models\Message;
use App\Modules\Shared\Services\PrivateFileStore;
use App\Support\Demo;
use App\Support\Romania;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Throwable;

/**
 * Offers a person builds by hand from the catalogue.
 *
 * Every query here filters workspace_id itself. This application has no global
 * scope and no tenant trait, so a missing clause is another firm's quote on the
 * screen — and offer_items carries its own workspace_id and is filtered on it,
 * rather than being reached only through the offer it belongs to.
 *
 * Money is bani, as integers, in every column and every prop: total_cents =
 * 24000 is 240,00 lei. Nothing in this controller divides by 100 — the screen
 * formats, the server counts.
 *
 * The server is the authority on the figures. The editor recomputes a line
 * total while a person types so the row does not sit blank, but what is saved
 * is what OfferTotals computed here, from prices this controller read back out
 * of the catalogue.
 */
class OfferController extends Controller
{
    /** Rows on a page of the list. */
    private const PER_PAGE = 25;

    /**
     * The largest a single line may come to, in bani.
     *
     * A hundred million lei — past anything a firm of this size quotes, and far
     * enough below PHP_INT_MAX that the millis-times-bani arithmetic inside
     * OfferTotals cannot overflow into a float.
     */
    private const MAX_LINE_BANI = 10000000000;

    /** The statuses an offer may still be decided from. */
    private const OPEN_STATUSES = ['draft', 'sent'];

    /** Which of the statuses count as money still on the table. */
    private const IN_PROGRESS_STATUSES = ['draft', 'sent'];

    /** The tabs above the list, in the order they are drawn. */
    private const COUNTED_STATUSES = ['draft', 'sent', 'accepted', 'refused'];

    /**
     * The channels an offer can be attributed to. Nothing writes one in stage
     * 2a — sending is stage 2b — but the filter is already allow-listed so a
     * hand-edited URL cannot reach the query with something else.
     */
    private const CHANNELS = ['whatsapp', 'instagram', 'messenger', 'sms', 'email'];

    /** How many lines one offer may carry. Well past a real quote. */
    private const MAX_ITEMS = 200;

    /** The one tab that is not a status: source = 'ai' AND status = 'draft'. */
    private const AI_VIEW = 'ai_drafts';

    /**
     * The client setting that switches the agent's drafting on for a firm.
     *
     * The SAME key, and the same filter_var reading of it, as
     * App\Modules\Offers\Listeners\DraftOfferFromMessageListener::TOGGLE_KEY.
     * Two readers of one gate is one string too many, but the alternative is a
     * controller importing a listener — and a gate that ships FALSE must not
     * depend on being present in somebody's DEFAULTS array to stay false, which
     * is why it is not one of OfferSettings' five money settings.
     */
    private const AI_DRAFTING_KEY = 'offers.ai_drafting_enabled';

    /**
     * How much of the customer's side of the thread is shown above an AI draft,
     * and handed back to the drafter on a rebuild.
     *
     * Bounded because a thread can hold thousands of messages and because the
     * whole of one would not fit a prompt anyway. Twenty inbound messages is far
     * more than the burst that produced the draft.
     */
    private const REQUEST_MESSAGES = 20;

    public function __construct(
        private readonly OfferTotals $totals,
        private readonly OfferNumberAllocator $numbers,
        private readonly OfferPdfRenderer $pdfs,
        private readonly OfferSettings $settings,
        private readonly OfferSender $sender,
        private readonly PrivateFileStore $files,
    ) {}

    public function index(Request $request): Response
    {
        $workspaceId = (int) $request->user()->workspace_id;

        $filters = $this->filters($request);

        $query = $this->scoped($workspaceId)
            ->with(['contact:id,uuid,first_name,last_name,company', 'creator:id,name'])
            // Scoped by workspace as well as by offer: the count must not be the
            // one query in the module that trusts the foreign key alone.
            ->withCount(['items' => function (Builder $items) use ($workspaceId): void {
                $items->where('workspace_id', $workspaceId);
            }]);

        $this->narrow($query, $workspaceId, $filters);

        $offers = $query->latest('created_at')
            ->paginate(self::PER_PAGE)
            ->withQueryString()
            ->through(fn (Offer $offer): array => $this->row($offer));

        // One grouped query behind the tabs and the tiles, and one narrow
        // aggregate behind the agent's banner. A firm with two thousand offers
        // must cost the same as one with twenty, and the number on a tab can
        // never disagree with the number on a tile.
        $byStatus = $this->byStatus($workspaceId);
        $aiDrafts = $this->aiDrafts($workspaceId);

        return Inertia::render('Offers/Index', [
            'offers' => $offers,
            'counts' => $this->counts($byStatus, $aiDrafts['count']),
            'stats' => $this->stats($workspaceId, $byStatus, $aiDrafts),
            'filters' => $filters,
        ]);
    }

    /**
     * A new draft.
     *
     * The number is allocated inside the same transaction as the row, so a draft
     * that fails to save does not burn one — and two people pressing the button
     * at the same moment cannot be handed the same one.
     */
    public function store(Request $request): RedirectResponse
    {
        $workspaceId = (int) $request->user()->workspace_id;
        $clientId = $this->clientId($request);
        $userId = (int) $request->user()->id;

        $data = $request->validate($this->itemRules() + [
            'contact_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
        ]);

        $contactId = $this->ownedContactId($workspaceId, $data['contact_id'] ?? null);
        $lines = $this->lines($workspaceId, $this->itemsIn($data));

        $settings = $this->settings->get($clientId);
        $profile = $this->profile($clientId);

        $offer = DB::transaction(function () use ($workspaceId, $userId, $contactId, $lines, $settings, $profile): Offer {
            $offer = new Offer;

            // forceFill rather than create(): the tenancy column, the status and
            // the money are written by this controller on purpose, and must not
            // depend on what a $fillable list happens to allow.
            $offer->forceFill([
                'workspace_id' => $workspaceId,
                'number' => $this->numbers->next($workspaceId),
                'contact_id' => $contactId,
                'status' => 'draft',
                'source' => 'human',
                'currency' => 'RON',
                // The seller's VAT position is snapshotted now. A firm that
                // registers for VAT next month has not changed the offer it sent
                // today, and a firm with no company profile yet simply has none.
                'vat_status' => $profile?->vat_status,
                // Resolved to a number now, not left null to be looked up again
                // later. A null rate fell through to today's legislated rate on
                // every recalculation, so a draft that spanned the 2025-08-01
                // move from 19% to 21% quietly re-priced itself — and the PDF
                // printed "TVA 4,29 lei" with no percentage beside it.
                'vat_rate' => $this->vatRateFor($profile),
                'valid_until' => now()->addDays($settings['validity_days'])->toDateString(),
                'created_by' => $userId,
            ])->save();

            $this->replaceItems($offer, $workspaceId, $lines, $settings);
            $this->recalculate($offer, $settings);

            return $offer;
        });

        return redirect()->route('client.offers.show', $offer)
            ->with('success', __('Offer :number created.', ['number' => $offer->number]));
    }

    public function show(Request $request, Offer $offer): Response
    {
        $workspaceId = (int) $request->user()->workspace_id;
        $this->authorise($request, $offer);

        $clientId = $this->clientId($request);

        $offer->loadMissing('contact:id,uuid,first_name,last_name,company,email,phone_e164,tax_id,address,city');

        return Inertia::render('Offers/Show', [
            'offer' => $this->detail($offer, $workspaceId),
            'settings' => $this->settings->get($clientId),
            'seller' => $this->seller($clientId),
        ]);
    }

    /**
     * The whole editable offer, in one PUT.
     *
     * The lines are replaced rather than diffed: the editor holds the list and
     * sends the list it holds, so a row deleted on the screen is a row that is
     * absent from the payload, not a row anybody has to identify.
     */
    public function update(Request $request, Offer $offer): RedirectResponse
    {
        $workspaceId = (int) $request->user()->workspace_id;
        $this->authorise($request, $offer);
        $this->assertEditable($offer);

        $data = $request->validate($this->itemRules() + [
            'contact_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'valid_until' => ['sometimes', 'nullable', 'date'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'discount_label' => ['sometimes', 'nullable', 'string', 'max:64'],
        ]);

        $updates = [];

        if (array_key_exists('contact_id', $data)) {
            $updates['contact_id'] = $this->ownedContactId($workspaceId, $data['contact_id']);
        }

        if (array_key_exists('valid_until', $data)) {
            $updates['valid_until'] = $this->text($data['valid_until']);
        }

        if (array_key_exists('notes', $data)) {
            $updates['notes'] = $this->text($data['notes']);
        }

        if (array_key_exists('discount_label', $data)) {
            $updates['discount_label'] = $this->text($data['discount_label']);
        }

        // Stage 4 instrumentation, and the only honest way to get it: a person
        // saving an offer the agent drafted has, by definition, changed it
        // before sending. The one number that says whether the drafting is worth
        // keeping is how often that does NOT happen — an approval rate near 100%
        // with this column near always-false means the drafts are being
        // rubber-stamped, not reviewed.
        if ((string) $offer->source === 'ai') {
            $updates['edited_before_send'] = true;
        }

        $replacing = array_key_exists('items', $data);
        $lines = $replacing ? $this->lines($workspaceId, $this->itemsIn($data)) : [];

        $settings = $this->settings->get($this->clientId($request));

        DB::transaction(function () use ($offer, $workspaceId, $updates, $replacing, $lines, $settings): void {
            if ($updates !== []) {
                $offer->forceFill($updates)->save();
            }

            if ($replacing) {
                $this->replaceItems($offer, $workspaceId, $lines, $settings);
            }

            // Always, even when only the notes moved: the totals are also a
            // function of the settings, and this is the moment they are re-read.
            $this->recalculate($offer, $settings);
        });

        return back()->with('success', __('Offer saved.'));
    }

    public function destroy(Request $request, Offer $offer): RedirectResponse
    {
        $this->authorise($request, $offer);

        // Soft, like the document library: an offer is an afternoon of typing,
        // and a row removed by a mis-click should be recoverable rather than gone.
        $offer->delete();

        // Not back(): the delete is as likely to come from the offer's own page,
        // and that page no longer exists.
        return redirect()->route('client.offers.index')
            ->with('success', __('Offer deleted.'));
    }

    /** The customer said yes, or said no. */
    public function decision(Request $request, Offer $offer): RedirectResponse
    {
        $this->authorise($request, $offer);

        $data = $request->validate([
            'decision' => ['required', 'in:accepted,refused'],
        ]);

        if (! in_array((string) $offer->status, self::OPEN_STATUSES, true)) {
            throw ValidationException::withMessages([
                'decision' => __('Only an offer that is still open can be marked as accepted or refused.'),
            ]);
        }

        $offer->forceFill([
            'status' => $data['decision'],
            'decision' => $data['decision'],
            'decided_at' => now(),
        ])->save();

        return back()->with('success', $data['decision'] === 'accepted'
            ? __('Offer marked as accepted.')
            : __('Offer marked as refused.'));
    }

    /**
     * The PDF, shown in the browser.
     *
     * Rendered on request rather than served from whatever was filed last: a
     * draft changes under the person editing it, and a stale PDF is worse than
     * a moment's wait. The renderer files the Document row and writes its id
     * back onto the offer; the file itself is only ever handed out here, after
     * the workspace has been checked — it has no URL of its own.
     */
    public function pdf(Request $request, Offer $offer): HttpResponse
    {
        $workspaceId = (int) $request->user()->workspace_id;
        $this->authorise($request, $offer);

        $clientId = $this->clientId($request);
        $document = $this->pdfs->render($offer, $this->seller($clientId) ?? [], $this->settings->get($clientId));

        // Belt and braces. The renderer files the row against the offer's own
        // workspace; nothing is served before that has been confirmed.
        abort_unless((int) $document->workspace_id === $workspaceId, 403);

        $contents = $this->files->contents((string) $document->path);
        abort_if($contents === null, 404);

        return response($contents, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Length' => (string) strlen($contents),
            'X-Content-Type-Options' => 'nosniff',
            // The same policy the document library puts on an inline file.
            // `sandbox` is what stops script inside a PDF running against the
            // session of the person who opened it.
            'Content-Security-Policy' => "default-src 'none'; style-src 'unsafe-inline'; sandbox",
            'Content-Disposition' => (new ResponseHeaderBag)->makeDisposition(
                ResponseHeaderBag::DISPOSITION_INLINE,
                $offer->number.'.pdf',
                'oferta.pdf',
            ),
        ]);
    }

    /**
     * Put the offer in front of the customer, on the channel they wrote from.
     *
     * The offer is only marked sent when the driver accepted it. A failed send
     * that left the offer looking sent would be the worst outcome of the three:
     * the person moves on, and the customer never received anything.
     */
    public function send(Request $request, Offer $offer): RedirectResponse
    {
        $workspaceId = (int) $request->user()->workspace_id;
        $this->authorise($request, $offer);
        $this->assertEditable($offer);

        $data = $request->validate([
            'message' => ['required', 'string', 'max:4000'],
            'attach_pdf' => ['sometimes', 'boolean'],
        ]);

        $conversation = $this->conversationFor($workspaceId, $offer);

        if ($conversation === null) {
            return back()->with('error', __('This offer has no client conversation to send into.'));
        }

        // Refused here, in Romanian, rather than by Meta thirty hours later as a
        // failed bubble carrying their raw error.
        if ($conversation->resolvedChannel() === 'whatsapp' && ! $conversation->isWhatsappWindowOpen()) {
            return back()->with('error', __('The 24-hour WhatsApp window is closed. Re-engage this contact with an approved template first.'));
        }

        $clientId = $this->clientId($request);
        $pdf = ($data['attach_pdf'] ?? true)
            ? $this->pdfs->render($offer, $this->seller($clientId) ?? [], $this->settings->get($clientId))
            : null;

        $result = $this->sender->send($offer, $conversation, $data['message'], $pdf);

        if ($result['error'] !== null) {
            return back()->with('error', __('The offer could not be sent: :error', ['error' => $result['error']]));
        }

        $offer->forceFill([
            'status' => 'sent',
            'channel' => $conversation->resolvedChannel(),
            'conversation_id' => $conversation->id,
            'message_body' => $data['message'],
            'sent_at' => now(),
            'sent_by' => (int) $request->user()->id,
        ])->save();

        // Said out loud: Messenger and Instagram have no document path at all,
        // so the offer went as text and the PDF did not travel with it.
        return $pdf !== null && ! $result['pdf_sent']
            ? back()->with('success', __('Offer sent. This channel cannot carry a file, so the PDF was not attached.'))
            : back()->with('success', __('Offer sent.'));
    }

    /**
     * The conversation this offer belongs in.
     *
     * The one it was created from, when it came from the inbox; otherwise the
     * most recent thread with the same contact. Both are scoped — a
     * conversation id on the offer is not proof it is ours.
     */
    private function conversationFor(int $workspaceId, Offer $offer): ?Conversation
    {
        if ($offer->conversation_id !== null) {
            $existing = Conversation::query()
                ->where('workspace_id', $workspaceId)
                ->whereKey($offer->conversation_id)
                ->first();

            if ($existing !== null) {
                return $existing;
            }
        }

        if ($offer->contact_id === null) {
            return null;
        }

        return Conversation::query()
            ->where('workspace_id', $workspaceId)
            ->where('contact_id', $offer->contact_id)
            ->orderByDesc('last_message_at')
            ->first();
    }

    public function settings(Request $request): Response
    {
        $clientId = $this->clientId($request);

        return Inertia::render('Offers/Settings', [
            'settings' => $this->settings->get($clientId),
            // Off unless this firm has been switched on by hand. See
            // aiDraftingEnabled(): this is the gate that makes the drafting
            // safe to deploy at all.
            'aiDraftingEnabled' => $this->aiDraftingEnabled($clientId),
            // workspaces.client_id is nullable, and these settings hang off the
            // client. With no client there is nowhere to write them, and the
            // screen says so rather than offering a form that does nothing.
            'hasClient' => $clientId !== null,
        ]);
    }

    public function saveSettings(Request $request): RedirectResponse
    {
        $clientId = $this->clientId($request);

        $data = $request->validate([
            'validity_days' => ['required', 'integer', 'min:1', 'max:365'],
            // Lei, as the form writes them — the same convention as every other
            // money field in the product. The page sends "25" or "25,00"; bani
            // are this controller's business, not the form's.
            // A regex rather than `numeric`, which refuses "350,50" — the
            // separator a Romanian keyboard produces. Money::bani reads either.
            'free_shipping' => ['sometimes', 'nullable', 'string', 'max:20', 'regex:/^\d{1,7}([.,]\d{1,2})?$/'],
            'shipping' => ['sometimes', 'nullable', 'string', 'max:20', 'regex:/^\d{1,7}([.,]\d{1,2})?$/'],
            'default_discount_percent' => ['required', 'integer', 'min:0', 'max:100'],
            'footer_text' => ['sometimes', 'nullable', 'string', 'max:1000'],
            // Not required: a screen that submits the five money settings and
            // not this one must leave the gate exactly as it found it.
            'ai_drafting_enabled' => ['sometimes', 'boolean'],
        ]);

        // Said out loud rather than dropped: a save that appears to work and
        // writes nothing is the worst of the three possible behaviours.
        if ($clientId === null) {
            return back()->with('error', __('Complete your company profile before saving offer settings.'));
        }

        $this->settings->save($clientId, [
            'validity_days' => (int) $data['validity_days'],
            'free_shipping_cents' => Money::bani($data['free_shipping'] ?? null),
            'shipping_cents' => Money::bani($data['shipping'] ?? null),
            'default_discount_percent' => (int) $data['default_discount_percent'],
            'footer_text' => (string) ($this->text($data['footer_text'] ?? null) ?? ''),
        ]);

        // Written straight to ClientSetting rather than through OfferSettings:
        // that service types five money settings and would silently drop a sixth
        // key it does not know, which for a gate is the worst possible failure —
        // a screen that says "salvat" and leaves the agent switched off.
        if (array_key_exists('ai_drafting_enabled', $data)) {
            ClientSetting::set($clientId, self::AI_DRAFTING_KEY, $data['ai_drafting_enabled'] ? '1' : '0');
        }

        return back()->with('success', __('Offer settings saved.'));
    }

    // ------------------------------------------------------- the agent's draft

    /**
     * Save the corrected reading of what the customer asked for.
     *
     * Nothing is re-priced here. Correcting the reading and rebuilding the offer
     * from it are two presses on purpose: a person fixing "2 camere" to "3
     * camere" wants to fix the budget and the deadline too before anything is
     * re-drafted, and a save that spent a provider call each time would be both
     * slower and dearer for no gain.
     *
     * The lengths are OfferDrafter's own, not this controller's. They are what
     * the drafter will cut the fields to anyway, and a form that accepts 400
     * characters into a field the model is handed 200 of is a form that silently
     * loses half of what somebody typed.
     */
    public function interpretation(Request $request, Offer $offer): RedirectResponse
    {
        $this->authorise($request, $offer);
        $this->assertAiDraft($offer);

        $this->saveReading($request, $offer, required: true);

        return back()->with('success', __('The agent\'s reading has been corrected.'));
    }

    /**
     * Build the draft again, from the CORRECTED reading rather than from the
     * customer's raw words.
     *
     * This is the whole point of the left-hand column. A misreading — "vopsea
     * lavabilă" heard as "vopsea pentru lemn" — stops being a draft somebody
     * throws away and becomes one field and one press. The drafter is handed the
     * corrected reading, and lays it over its own answer field by field, so the
     * mistake the person just fixed cannot come back.
     *
     * Run in the request and not queued: a person is watching the screen waiting
     * for the answer, and a queued rebuild would need a poll, a spinner and a
     * second piece of state to get wrong. The inbound path is the one that must
     * never block, and it does not come through here — see
     * App\Modules\Offers\Listeners\DraftOfferFromMessageListener.
     *
     * The drafter is injected on the method rather than the constructor, so that
     * listing, showing and printing an offer never construct it.
     */
    public function regenerate(Request $request, Offer $offer, OfferDrafter $drafter): RedirectResponse
    {
        $workspaceId = (int) $request->user()->workspace_id;
        $this->authorise($request, $offer);
        $this->assertAiDraft($offer);

        // What is on the screen, saved BEFORE anything is drafted from it. The
        // editor sends the corrected fields with the request rather than relying
        // on a separate save having happened, so there is no version of this
        // where somebody fixes "buget", presses Regenerează, and the agent quotes
        // against the reading it had before. Absent from the payload, the reading
        // already on the offer stands.
        $this->saveReading($request, $offer);

        $clientId = $this->clientId($request);

        // The toggle is a kill switch, not a preference about inbound messages.
        // A firm that switched drafting off has said it does not want the agent
        // spending its provider budget, and a button on a screen is not an
        // exception to that. Not recorded as a failed draft, because nothing was
        // attempted.
        if (! $this->aiDraftingEnabled($clientId)) {
            return back()->with('error', __('Draft offers from the agent are switched off for your firm. Turn them on in the offer settings.'));
        }

        // The thread as it stands NOW, not the messages the first draft was made
        // from: a customer who has since added "și 2 uși" should get that in the
        // rebuild. The corrected reading still wins field by field over whatever
        // the model makes of them.
        //
        // And no conversation is not a refusal. An offer outlives the thread it
        // came out of — a purged or deleted conversation must not turn a draft
        // into one nobody can ever rebuild — so with the thread gone the
        // corrected reading on the offer stands on its own, which is exactly
        // what redraft() takes plain strings rather than a Conversation for.
        $conversation = $this->ownedConversation($workspaceId, $offer);
        $messages = $conversation === null
            ? new EloquentCollection
            : $this->inbound($conversation, OfferDrafter::MAX_MESSAGES);

        $said = [];

        foreach ($messages as $message) {
            // Unmasked, unlike the same messages on the screen below: this copy
            // goes into a prompt and never onto a page, and a drafter handed
            // "•••" instead of "40 mp parchet stejar" would quote the wrong thing.
            $body = $this->str($message->getAttribute('body'));

            if ($body !== null) {
                $said[] = $body;
            }
        }

        try {
            $result = $this->arrayOf($drafter->redraft($workspaceId, $clientId, $this->readingOf($offer), $said));
        } catch (OfferDraftFailed $e) {
            // redraft() answers its own expected refusals with a reason key, so
            // this is the unexpected one getting out. Its key is still short,
            // still translatable and still free of anything a provider said.
            return $this->draftFailed($offer, $e->reasonKey, $e, $e->getMessage());
        } catch (Throwable $e) {
            return $this->draftFailed($offer, OfferDrafter::REASON_FAILED, $e, null);
        }

        // No catalogue, nothing readable, the provider down, or it looked and
        // found nothing suitable — all of them arrive here as a key, because
        // from where the person is standing they are all a Regenerate that
        // produced no offer, and all of them need saying.
        $refused = $this->str($result['reason'] ?? null) ?? $this->str($result['reason_key'] ?? null);

        if ($refused !== null) {
            return $this->draftFailed($offer, $refused, null, null);
        }

        // ── The last code before a price reaches a row a customer can see ────
        // The drafter has already checked every id against this workspace's
        // catalogue. This is not distrust of it: it is the rule that every query
        // on a tenant-owned table names workspace_id, applied where the row is
        // actually written, and the job does exactly the same on its own path.
        $checked = $this->recheck($workspaceId, $this->listOf($result['lines'] ?? null));
        $lines = $this->lines($workspaceId, $checked['lines']);

        if ($lines === []) {
            return $this->draftFailed($offer, OfferDrafter::REASON_NO_MATCH, null, null);
        }

        $settings = $this->settings->get($clientId);

        // The same shape the job writes, key for key. Two paths producing two
        // shapes of ai_json would mean the offer screen has to know which of them
        // made the row in front of it.
        $aiJson = [
            'interpretation' => $this->reading($this->arrayOf($result['interpretation'] ?? null)),
            'corrected' => true,
            'summary' => $this->str($result['summary'] ?? null) ?? '',
            'lines' => $checked['lines'],
            'dropped' => array_merge(
                $this->notices($result['dropped'] ?? null),
                $checked['dropped'],
            ),
            'warnings' => $this->notices($result['warnings'] ?? null),
            'catalogue' => $this->arrayOf($result['catalogue'] ?? null),
            'source_message_ids' => $messages->map(
                fn (Message $message): int => $this->int($message->getKey())
            )->values()->all(),
            'model' => $this->str($result['model'] ?? null),
            'tokens' => $this->int($result['tokens'] ?? null),
            'regenerated_at' => now()->toIso8601String(),
            'regenerations' => $this->int($this->aiJson($offer)['regenerations'] ?? null) + 1,
            // The last run's failure is not this run's.
            'failure' => null,
        ];

        DB::transaction(function () use ($offer, $workspaceId, $lines, $settings, $aiJson): void {
            $this->replaceItems($offer, $workspaceId, $lines, $settings, 'ai');

            $offer->forceFill([
                'ai_json' => $this->mergeAiJson($offer, $aiJson),
                'ai_reason' => $aiJson['summary'] === '' ? null : $aiJson['summary'],
                // Back to null, which is what the job leaves and what "nobody has
                // touched this" means everywhere else. Leaving it true from the
                // run before would count a rubber-stamped offer as a reviewed
                // one, and that is the single number this column exists for.
                'edited_before_send' => null,
            ])->save();

            $this->recalculate($offer, $settings);
        });

        return back()->with('success', __('The draft has been rebuilt from your corrections.'));
    }

    // ---------------------------------------------------------------- reads

    /**
     * Every read starts here.
     *
     * There is no global scope in this application, so the workspace clause is
     * not optional and not implied — it is this method, and every query goes
     * through it.
     *
     * @return Builder<Offer>
     */
    private function scoped(int $workspaceId): Builder
    {
        return Offer::query()->where('workspace_id', $workspaceId);
    }

    /**
     * The filters the list understands, cleaned.
     *
     * Anything outside the allow-lists is dropped rather than passed through, so
     * a hand-edited URL narrows the list or does nothing — it never reaches a
     * query as a status this application does not have.
     *
     * @return array<string, string|null>
     */
    private function filters(Request $request): array
    {
        $status = $this->input($request, 'status');
        $channel = $this->input($request, 'channel');
        $view = $this->input($request, 'view');

        return [
            'status' => in_array($status, Offer::STATUSES, true) ? $status : null,
            'search' => $this->input($request, 'search') ?: null,
            'channel' => in_array($channel, self::CHANNELS, true) ? $channel : null,
            // The 'Ciorne AI' tab. One bucket and not an axis: source = 'ai' AND
            // status = 'draft', chosen together and never apart, because an AI
            // offer that has been sent is no longer a ciornă. A `source` filter
            // beside `status` would let a URL ask for combinations the tab strip
            // has no way to mean.
            'view' => $view === self::AI_VIEW ? self::AI_VIEW : null,
            'from' => $this->day($request, 'from'),
            'to' => $this->day($request, 'to'),
        ];
    }

    /**
     * Narrow the list the way the screen does.
     *
     * Does not scope the workspace — that is already on the builder, applied by
     * scoped(), before this is reached. The workspace id is taken again for the
     * contact sub-query, which is a second table and needs its own clause.
     *
     * @param  Builder<Offer>  $query
     * @param  array<string, string|null>  $filters
     */
    private function narrow(Builder $query, int $workspaceId, array $filters): void
    {
        if ($filters['status'] !== null) {
            $query->where('status', $filters['status']);
        }

        if ($filters['channel'] !== null) {
            $query->where('channel', $filters['channel']);
        }

        // Both clauses, or neither. The tab is the bucket, not half of it.
        if ($filters['view'] === self::AI_VIEW) {
            $query->where('source', 'ai')->where('status', 'draft');
        }

        if ($filters['from'] !== null) {
            $query->whereDate('created_at', '>=', $filters['from']);
        }

        if ($filters['to'] !== null) {
            $query->whereDate('created_at', '<=', $filters['to']);
        }

        if ($filters['search'] !== null) {
            $like = Money::likeTerm($filters['search']);

            $query->where(function (Builder $inner) use ($like, $workspaceId): void {
                $inner->where('number', 'like', $like)
                    ->orWhereHas('contact', function (Builder $contact) use ($like, $workspaceId): void {
                        $contact->where('workspace_id', $workspaceId)
                            ->where(function (Builder $name) use ($like): void {
                                $name->where('first_name', 'like', $like)
                                    ->orWhere('last_name', 'like', $like)
                                    ->orWhere('company', 'like', $like);
                            });
                    });
            });
        }
    }

    /**
     * How many offers sit at each status, and what they are worth.
     *
     * One grouped aggregate. Deliberately not narrowed by the filters: the tab
     * counts are what the workspace holds, so they do not move under a person
     * who is typing in the search box.
     *
     * @return array<string, array{count: int, value_cents: int}>
     */
    private function byStatus(int $workspaceId): array
    {
        $rows = $this->scoped($workspaceId)
            ->selectRaw('status, count(*) as aggregate, coalesce(sum(total_cents), 0) as value_cents')
            ->groupBy('status')
            ->get();

        $out = [];

        foreach ($rows as $row) {
            $out[(string) $row->getAttribute('status')] = [
                'count' => (int) $row->getAttribute('aggregate'),
                'value_cents' => (int) $row->getAttribute('value_cents'),
            ];
        }

        return $out;
    }

    /**
     * What the agent has left waiting for a person: the count for the banner and
     * the tab, and what those drafts come to.
     *
     * A SECOND QUERY, and not `source` added to byStatus()'s GROUP BY, which is
     * what this originally was. MEASURED, on MySQL, with 10,000 offers in the
     * workspace and 40,000 in the table:
     *
     *     GROUP BY status                4.80 ms
     *     GROUP BY status, source        7.10 ms   (+2.30)
     *     this query, on its own         1.07 ms
     *
     * Folding it in looked like the free option and is the dearer one. The
     * grouped aggregate builds a temporary table over every offer the workspace
     * has, and doubling its grain from five buckets to ten costs more than this
     * query costs in total — because this one is answered from
     * (workspace_id, status) and never looks at a row that is not a draft.
     *
     * @return array{count: int, value_cents: int}
     */
    private function aiDrafts(int $workspaceId): array
    {
        $row = $this->scoped($workspaceId)
            ->where('status', 'draft')
            ->where('source', 'ai')
            ->selectRaw('count(*) as aggregate, coalesce(sum(total_cents), 0) as value_cents')
            ->first();

        return [
            'count' => (int) ($row?->getAttribute('aggregate') ?? 0),
            'value_cents' => (int) ($row?->getAttribute('value_cents') ?? 0),
        ];
    }

    /**
     * @param  array<string, array{count: int, value_cents: int}>  $byStatus
     * @param  int  $aiDrafts  the badge on the 'Ciorne AI' tab
     * @return array<string, int>
     */
    private function counts(array $byStatus, int $aiDrafts): array
    {
        // 'all' counts every status, expired included — it is the tab that shows
        // everything, not the sum of the four tabs beside it.
        $counts = ['all' => 0];

        foreach ($byStatus as $bucket) {
            $counts['all'] += $bucket['count'];
        }

        foreach (self::COUNTED_STATUSES as $status) {
            $counts[$status] = $byStatus[$status]['count'] ?? 0;
        }

        // Not one of the statuses: a tab of its own, cutting across 'draft'.
        $counts['ai_drafts'] = $aiDrafts;

        return $counts;
    }

    /**
     * The tiles above the list.
     *
     * `accepted_rate` is a whole percentage of the offers that were actually
     * decided — accepted out of accepted plus refused. An offer still open is
     * not a loss, so it is not in the denominator, and a workspace that has
     * decided nothing yet reads 0 rather than dividing by zero.
     *
     * @param  array<string, array{count: int, value_cents: int}>  $byStatus
     * @param  array{count: int, value_cents: int}  $aiDrafts
     * @return array<string, int>
     */
    private function stats(int $workspaceId, array $byStatus, array $aiDrafts): array
    {
        $accepted = $byStatus['accepted']['count'] ?? 0;
        $refused = $byStatus['refused']['count'] ?? 0;
        $decided = $accepted + $refused;

        $inProgressCount = 0;
        $inProgressCents = 0;

        foreach (self::IN_PROGRESS_STATUSES as $status) {
            $inProgressCount += $byStatus[$status]['count'] ?? 0;
            $inProgressCents += $byStatus[$status]['value_cents'] ?? 0;
        }

        return [
            'drafts' => $byStatus['draft']['count'] ?? 0,
            // From sent_at, not from created_at: an offer written in March and
            // sent in April was sent in April.
            'sent_this_month' => (int) $this->scoped($workspaceId)
                ->whereNotNull('sent_at')
                ->where('sent_at', '>=', now()->startOfMonth())
                ->count(),
            'accepted' => $accepted,
            'accepted_rate' => $decided > 0 ? (int) round($accepted * 100 / $decided) : 0,
            'in_progress_cents' => $inProgressCents,
            'in_progress_count' => $inProgressCount,
            // The green banner. The value matters as much as the count: the first
            // question a firm asks about a pile of quotes is not how many there
            // are but how much is in them.
            'ai_drafts' => $aiDrafts['count'],
            'ai_drafts_cents' => $aiDrafts['value_cents'],
        ];
    }

    /**
     * One row of the list.
     *
     * A fixed key set rather than the model: a column added in stage 4 must not
     * start appearing in an Inertia prop because somebody ran a migration.
     *
     * @return array<string, mixed>
     */
    private function row(Offer $offer): array
    {
        $contact = $offer->contact;

        return [
            'uuid' => $offer->uuid,
            'number' => $offer->number,
            'status' => $offer->status,
            'channel' => $offer->channel,
            'total_cents' => (int) $offer->total_cents,
            'currency' => $offer->currency,
            'valid_until' => $offer->valid_until,
            'created_at' => $offer->getAttribute('created_at'),
            'sent_at' => $offer->sent_at,
            'source' => $offer->source,
            'contact' => $contact === null ? null : [
                'uuid' => $contact->uuid,
                'name' => $this->contactName($contact),
                'company' => $contact->company,
            ],
            'creator' => $offer->creator === null ? null : [
                'id' => (int) $offer->creator->id,
                'name' => $offer->creator->name,
            ],
            'items_count' => (int) ($offer->getAttribute('items_count') ?? 0),
        ];
    }

    /**
     * The offer as its own page reads it, lines included.
     *
     * @return array<string, mixed>
     */
    private function detail(Offer $offer, int $workspaceId): array
    {
        $contact = $offer->contact;

        return [
            'uuid' => $offer->uuid,
            'number' => $offer->number,
            'status' => $offer->status,
            'source' => $offer->source,
            'currency' => $offer->currency,
            'contact' => $contact === null ? null : [
                // The editor sends contact_id back on every save. Without the id
                // here it sent null, and a person who changed one quantity found
                // the client wiped off the offer and off the PDF.
                'id' => (int) $contact->id,
                'uuid' => $contact->uuid,
                'name' => $this->contactName($contact),
                'company' => $contact->company,
                'email' => $contact->email,
                'phone_e164' => $contact->phone_e164,
                'tax_id' => $contact->tax_id,
                'address' => $contact->address,
                'city' => $contact->city,
            ],
            'subtotal_cents' => (int) $offer->subtotal_cents,
            'discount_label' => $offer->discount_label,
            'discount_cents' => (int) $offer->discount_cents,
            'shipping_cents' => (int) $offer->shipping_cents,
            'vat_status' => $offer->vat_status,
            'vat_rate' => $offer->vat_rate === null ? null : (float) $offer->vat_rate,
            'vat_cents' => (int) $offer->vat_cents,
            'total_cents' => (int) $offer->total_cents,
            'valid_until' => $offer->valid_until,
            'notes' => $offer->notes,
            'created_at' => $offer->getAttribute('created_at'),
            'sent_at' => $offer->sent_at,
            'decision' => $offer->decision,
            'decided_at' => $offer->decided_at,
            'pdf_document_uuid' => $this->pdfDocumentUuid($offer, $workspaceId),
            // "Vezi ca client" — the offer on the signed public link, exactly as
            // the customer opens it.
            //
            // NULL FOR A DRAFT, AND THAT IS THE POINT. There is no link until
            // the offer has actually been sent: a draft is a document the firm
            // is still writing, and a page that shows it to a customer would be
            // showing prices nobody has agreed to quote. The button is drawn
            // only when this prop is a string.
            //
            // Minted here on every render rather than stored on the row: the
            // signature carries its own expiry — the offer's valid_until, capped
            // at thirty days — so there is no token column to leak or to revoke,
            // and a link stops working on its own the day the offer stops being
            // valid. The rule lives in PublicOfferController, next to the route
            // that honours it, so the two cannot drift apart.
            'public_url' => PublicOfferController::linkFor($offer),
            'items' => $this->itemRows($offer, $workspaceId),
            // The left-hand column, for an offer the agent drafted. null for
            // every other offer, and the column is not drawn. On the offer and
            // not beside it because the screen reads offer.ai — one prop that
            // cannot arrive describing a different offer than the one under it.
            'ai' => $this->agentPanel($offer, $workspaceId),
        ];
    }

    /**
     * The lines of one offer.
     *
     * Read straight off offer_items with its own workspace clause rather than
     * through $offer->items, so the tenancy check is on the table the rows are
     * actually in.
     *
     * @return array<int, array<string, mixed>>
     */
    private function itemRows(Offer $offer, int $workspaceId): array
    {
        return OfferItem::query()
            ->where('workspace_id', $workspaceId)
            ->where('offer_id', $offer->id)
            ->orderBy('position')
            ->orderBy('id')
            ->get()
            ->map(fn (OfferItem $item): array => [
                'id' => (int) $item->id,
                'catalog_item_id' => $item->catalog_item_id === null ? null : (int) $item->catalog_item_id,
                'name' => $item->name,
                'unit' => $item->unit,
                'quantity' => (float) $item->quantity,
                'unit_price_cents' => (int) $item->unit_price_cents,
                'line_total_cents' => (int) $item->line_total_cents,
                'position' => (int) $item->position,
                'added_by' => $item->added_by,
            ])
            ->all();
    }

    /**
     * The uuid of the PDF this offer last filed, if it has one.
     *
     * Looked up with the workspace clause as well as the id: pdf_document_id is
     * a plain column with no foreign key, so it is an integer somebody could
     * have written, not a promise.
     */
    private function pdfDocumentUuid(Offer $offer, int $workspaceId): ?string
    {
        if ($offer->pdf_document_id === null) {
            return null;
        }

        $uuid = Document::query()
            ->where('workspace_id', $workspaceId)
            ->whereKey((int) $offer->pdf_document_id)
            ->value('uuid');

        return is_string($uuid) ? $uuid : null;
    }

    /**
     * The firm this user belongs to, or null.
     *
     * Offer settings and the seller's fiscal identity both hang off the client,
     * not the workspace, and workspaces.client_id is nullable — so a workspace
     * can legitimately have neither. Every caller here handles null.
     */
    private function clientId(Request $request): ?int
    {
        $clientId = $request->user()->client_id ?? null;

        return $clientId === null ? null : (int) $clientId;
    }

    /**
     * The seller's own fiscal identity, for the header of the offer.
     *
     * @return array<string, mixed>|null
     */
    private function seller(?int $clientId): ?array
    {
        $profile = $this->profile($clientId);

        if ($profile === null) {
            return null;
        }

        return [
            'legal_name' => $profile->legal_name,
            'cui' => $profile->cui,
            'vat_status' => $profile->vat_status,
            'vat_rate' => $profile->vat_rate === null ? null : (float) $profile->vat_rate,
            'trade_register_no' => $profile->trade_register_no,
            'iban' => $profile->iban,
            'bank_name' => $profile->bank_name,
            'address' => $this->sellerAddress($profile),
        ];
    }

    /**
     * The VAT rate to write onto the offer, as a number.
     *
     * The firm's own rate when it set one; otherwise the rate the law says
     * applies today. Either way it is decided once, here, and the offer keeps
     * it — a rate that is looked up again on every save is not a snapshot.
     */
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

    private function profile(?int $clientId): ?ClientProfile
    {
        if ($clientId === null) {
            return null;
        }

        return ClientProfile::query()->where('client_id', $clientId)->first();
    }

    /** Street, city, county, postcode — whichever of them the firm filled in. */
    private function sellerAddress(ClientProfile $profile): ?string
    {
        $parts = array_filter([
            $this->text($profile->address_street),
            $this->text($profile->address_city),
            $this->text($profile->address_county),
            $this->text($profile->address_postcode),
        ]);

        return $parts === [] ? null : implode(', ', $parts);
    }

    private function contactName(Contact $contact): string
    {
        $name = trim((string) $contact->full_name);

        return $name !== '' ? $name : (string) ($contact->company ?? '');
    }

    // --------------------------------------------------------------- writes

    /**
     * The line payload, cleaned and verified.
     *
     * A line that names a catalogue item takes its name and unit from the
     * catalogue row this workspace owns, not from the payload: the price is the
     * seller's to change on a quote, the identity of the product is not. A line
     * with no catalog_item_id is a free-text line — writing "deplasare 40 km"
     * on a quote is a real need, and it is allowed.
     *
     * A catalog_item_id belonging to another workspace is a validation error,
     * never a line quietly dropped: silently saving four of the five rows
     * somebody could see on their screen is how a firm sends a wrong quote.
     *
     * @param  array<int, mixed>  $payload
     * @return array<int, array{catalog_item_id: int|null, name: string, unit: string, quantity: float, unit_price_cents: int}>
     */
    private function lines(int $workspaceId, array $payload): array
    {
        $wanted = [];

        foreach ($payload as $line) {
            $id = is_array($line) ? $this->id($line['catalog_item_id'] ?? null) : null;

            if ($id !== null) {
                $wanted[$id] = $id;
            }
        }

        $catalogue = [];

        if ($wanted !== []) {
            // withTrashed: the question here is whose item this is, not whether
            // it is still on sale. An offer that already quotes a line the firm
            // has since retired must stay editable.
            $catalogue = CatalogItem::withTrashed()
                ->where('workspace_id', $workspaceId)
                ->whereIn('id', array_values($wanted))
                ->get()
                ->keyBy('id')
                ->all();
        }

        $errors = [];
        $lines = [];

        foreach (array_values($payload) as $index => $line) {
            if (! is_array($line)) {
                $errors["items.{$index}.name"] = __('This line could not be read.');

                continue;
            }

            $catalogItemId = $this->id($line['catalog_item_id'] ?? null);
            $item = $catalogItemId === null ? null : ($catalogue[$catalogItemId] ?? null);

            if ($catalogItemId !== null && $item === null) {
                $errors["items.{$index}.catalog_item_id"] = __('This catalogue item does not belong to your workspace.');

                continue;
            }

            // A bundle is the name of a group, not something that can be sold as
            // one row. It has no price of its own to quote — its components hold
            // the money — and a line reading "Pachet birou complet" tells the
            // customer nothing about what they are being sent. The editor adds
            // the components instead; a payload that names the bundle anyway is
            // refused here rather than quietly priced at whatever the bundle row
            // happens to carry.
            if ($item !== null && $item->type === 'bundle') {
                $errors["items.{$index}.catalog_item_id"] = __('An assembly cannot go on an offer as one line. Add its components instead.');

                continue;
            }

            // The line owns its description. Re-reading it from the catalogue
            // on every save means renaming a product rewrites the wording of an
            // offer already written — which is what "snapshot" was supposed to
            // prevent. The catalogue is only the DEFAULT, for a line that has
            // just been picked and carries nothing of its own yet.
            $name = (string) ($this->text($line['name'] ?? null) ?? ($item !== null ? (string) $item->name : ''));

            if ($name === '') {
                $errors["items.{$index}.name"] = __('A line needs a name.');

                continue;
            }

            // Same rule, and the unit is half of what a price means: 240 lei
            // per "buc" and per "set" are different offers.
            $unit = (string) ($this->text($line['unit'] ?? null) ?? ($item !== null ? (string) $item->unit : 'buc'));

            $lines[] = [
                'catalog_item_id' => $catalogItemId,
                'name' => mb_substr($name, 0, 255),
                'unit' => mb_substr($unit, 0, 32),
                // decimal(12,3): three places is what the column keeps, so it is
                // what the arithmetic uses.
                'quantity' => round((float) ($line['quantity'] ?? 1), 3),
                'unit_price_cents' => $this->unitPrice($line, $item),
            ];

            // The rules accept a quantity and a price whose product overflows a
            // PHP integer, and the totals then die with a TypeError and a 500.
            // Refuse it here, where the person can be told which line it was.
            $last = $lines[count($lines) - 1];

            if ($last['quantity'] * $last['unit_price_cents'] > self::MAX_LINE_BANI) {
                $errors["items.{$index}.quantity"] = __('This line is larger than an offer can hold.');
                array_pop($lines);
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return $lines;
    }

    /**
     * What one line costs per unit, in bani.
     *
     * The editor speaks bani, so unit_price_cents is the normal path. A payload
     * that carries lei instead goes through Money::bani — the one converter in
     * the application — rather than a second copy of the same rounding rule.
     * With neither, a catalogue line falls back to the catalogue price.
     *
     * @param  array<string, mixed>  $line
     */
    private function unitPrice(array $line, ?CatalogItem $item): int
    {
        if (array_key_exists('unit_price_cents', $line) && $line['unit_price_cents'] !== null && $line['unit_price_cents'] !== '') {
            return max(0, (int) $line['unit_price_cents']);
        }

        if (array_key_exists('unit_price', $line) && $line['unit_price'] !== null && $line['unit_price'] !== '') {
            return Money::bani($line['unit_price']);
        }

        return $item === null ? 0 : (int) $item->price_cents;
    }

    /**
     * Write the lines, replacing whatever was there.
     *
     * The delete carries the workspace clause as well as the offer id, for the
     * same reason every other query in this file does.
     *
     * @param  array<int, array{catalog_item_id: int|null, name: string, unit: string, quantity: float, unit_price_cents: int}>  $lines
     * @param  array<string, mixed>  $settings
     * @param  string  $addedBy  'human' or 'ai' — which of the two put these lines here
     */
    private function replaceItems(Offer $offer, int $workspaceId, array $lines, array $settings, string $addedBy = 'human'): void
    {
        // The per-line figure comes from OfferTotals, the same integer routine
        // that produces the subtotal. Computing it a second way here — in
        // floats — is how the printed column stopped adding up to the printed
        // subtotal: 0,705 x 29,00 rounded to 20,44 on the line and 20,45 in the
        // sum, on the exact trade quantities the decimal column exists for.
        $priced = $this->totals->compute($lines, $settings, $offer->vat_status, $offer->vat_rate === null ? null : (float) $offer->vat_rate);
        $lineTotals = array_map(static fn (array $l): int => (int) $l['line_total_cents'], $priced['lines']);

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
                // Snapshots, all three: a price change in the catalogue next
                // week must not alter what this offer says today.
                'name' => $line['name'],
                'unit' => $line['unit'],
                'quantity' => $line['quantity'],
                'unit_price_cents' => $line['unit_price_cents'],
                'line_total_cents' => $lineTotals[$position] ?? 0,
                'position' => $position,
                'added_by' => $addedBy,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        // One insert rather than one per line: a quote with forty rows is one
        // round trip.
        OfferItem::query()->insert($rows);
    }

    /**
     * Recompute the money and write it down.
     *
     * The figures are read back out of offer_items rather than taken from the
     * payload, so what is stored is always a function of what was stored.
     *
     * @param  array<string, mixed>  $settings
     */
    private function recalculate(Offer $offer, array $settings): void
    {
        $lines = OfferItem::query()
            ->where('workspace_id', $offer->workspace_id)
            ->where('offer_id', $offer->id)
            ->orderBy('position')
            ->get()
            ->map(fn (OfferItem $item): array => [
                // The quantity stays the exact decimal string MySQL returned.
                // Casting decimal(12,3) through float and back is the drift this
                // column was deliberately left uncast to avoid.
                'quantity' => (string) $item->getAttribute('quantity'),
                'unit_price_cents' => (int) $item->unit_price_cents,
            ])
            ->all();

        // The VAT position is the offer's own snapshot, not today's company
        // profile: a firm that registers for VAT next month has not changed the
        // offer it sent today.
        $totals = $this->totals->compute(
            $lines,
            $settings,
            $offer->vat_status,
            $offer->vat_rate === null ? null : (float) $offer->vat_rate,
        );

        $offer->forceFill([
            'subtotal_cents' => (int) $totals['subtotal_cents'],
            'discount_cents' => (int) $totals['discount_cents'],
            'shipping_cents' => (int) $totals['shipping_cents'],
            'vat_cents' => (int) $totals['vat_cents'],
            'total_cents' => (int) $totals['total_cents'],
        ])->save();
    }

    /**
     * Read the corrected reading off a request and write it to the offer.
     *
     * TWO PAYLOAD SHAPES, both accepted. The editor posts the five fields nested
     * under `interpretation`, which is what keeps them from colliding with the
     * offer's own fields on a screen that also PUTs notes and lines; a caller
     * that sends them flat means the same thing. Guessing between the two here
     * is one `if`, and it is cheaper than a second endpoint or a frontend that
     * has to know which build of the server it is talking to.
     *
     * Nothing is written when neither shape is present: Regenerează from a page
     * that sent no fields must rebuild from the reading already on the offer,
     * not wipe it.
     *
     * The lengths are OfferDrafter's own, not this controller's. They are what
     * the drafter cuts the fields to anyway, and a form that accepts 400
     * characters into a field the model is handed 200 of loses half of what
     * somebody typed without saying so.
     */
    private function saveReading(Request $request, Offer $offer, bool $required = false): void
    {
        $nested = is_array($request->input('interpretation'));
        $prefix = $nested ? 'interpretation.' : '';
        $field = ['sometimes', 'nullable', 'string', 'max:'.OfferDrafter::MAX_FIELD_CHARS];

        $data = $request->validate([
            $prefix.'cere' => $field,
            $prefix.'buget' => $field,
            $prefix.'termen' => $field,
            $prefix.'pentru' => $field,
            $prefix.'cerinte' => ['sometimes', 'nullable', 'array', 'max:'.OfferDrafter::MAX_REQUIREMENTS],
            $prefix.'cerinte.*' => ['nullable', 'string', 'max:'.OfferDrafter::MAX_FIELD_CHARS],
        ]);

        $sent = $nested ? $this->arrayOf($data['interpretation'] ?? null) : $data;

        if ($sent === []) {
            // Regenerate legitimately arrives with no correction at all — it is
            // allowed to just re-run. Only the explicit "save my correction"
            // action treats an empty result as a failure, and it must: a payload
            // whose keys had drifted used to be dropped here while the caller
            // flashed success on top of it, so a person watched their correction
            // vanish and was told it had been saved.
            if ($required) {
                throw ValidationException::withMessages([
                    'interpretation' => __('Nothing in that correction could be saved.'),
                ]);
            }

            return;
        }

        $offer->forceFill([
            'ai_json' => $this->mergeAiJson($offer, [
                'interpretation' => $this->reading($sent),
                // What tells a report which drafts a person actually read before
                // approving. The job writes it too, for the same reason.
                'corrected' => true,
                'corrected_at' => now()->toIso8601String(),
            ]),
        ])->save();
    }

    /**
     * Record a rebuild that produced nothing.
     *
     * NOT written to offer_draft_attempts. That table is unique on message_id and
     * is the idempotency record for the inbound path, where nobody is at the
     * screen; a rebuild has no new message, so a row would either collide with
     * the attempt already filed for that one or overwrite the record of the run
     * that succeeded. The failure belongs on the offer, which is where the person
     * who pressed the button is looking — and unlike a toast it survives a reload.
     *
     * $shown is the drafter's own message, which OfferDraftFailed guarantees is
     * already translated and already free of anything a provider said. With none,
     * one honest sentence, and the specific reason renders on the panel from the
     * key — in the same words the inbound path uses for the same failure.
     */
    private function draftFailed(Offer $offer, string $reason, ?Throwable $e, ?string $shown): RedirectResponse
    {
        $reason = $this->reasonKey($reason);

        if ($e !== null) {
            // The class, never the message. A provider client's exception text
            // routinely carries the request URL, and the key is in the URL.
            Log::channel('single')->warning('Offer draft could not be rebuilt', [
                'offer_id' => (int) $offer->id,
                'workspace_id' => (int) $offer->workspace_id,
                'reason' => $reason,
                'exception' => get_class($e),
            ]);
        }

        $offer->forceFill([
            'ai_json' => $this->mergeAiJson($offer, [
                'failure' => ['reason' => $reason, 'at' => now()->toIso8601String()],
            ]),
        ])->save();

        return back()->with('error', $shown ?? __('The agent could not rebuild this draft.'));
    }

    /**
     * The proposed lines, re-checked against the catalogue this workspace owns.
     *
     * The drafter has already done this and done it more thoroughly — it expands
     * bundles, clamps quantities and explains every refusal. This is not a second
     * opinion on any of that. It is the tenancy rule the project states plainly:
     * every query on a tenant-owned table names workspace_id, and the place that
     * matters is the last one before the row is written. DraftOfferJob applies
     * the same guard on its own path.
     *
     * So exactly two things are checked, and both are things no amount of
     * prompting can guarantee:
     *
     *   - the item is an ACTIVE, non-deleted, non-bundle row of THIS workspace.
     *     Anything else is dropped, with a note, in the drafter's own vocabulary
     *     so the screen renders it beside the drafter's own drops.
     *   - the price is inside [min_price_cents, price_cents]. Under the floor the
     *     firm set is a loss it never agreed to; over the list price is a number
     *     the customer can find is wrong. In every correct case this changes
     *     nothing — the drafter clamped it already and said so in its warnings.
     *
     * @param  list<mixed>  $proposed
     * @return array{lines: list<array<string, mixed>>, dropped: list<array{key: string, name: string, detail: string}>}
     */
    private function recheck(int $workspaceId, array $proposed): array
    {
        $wanted = [];

        foreach ($proposed as $line) {
            $id = $this->id($this->arrayOf($line)['catalog_item_id'] ?? null);

            if ($id !== null) {
                $wanted[$id] = $id;
            }
        }

        /** @var array<int|string, CatalogItem> $catalogue */
        $catalogue = [];

        if ($wanted !== []) {
            // No withTrashed, unlike the hand-editing path: that one asks "whose
            // item is this" about a line already on a quote, this one is building
            // a new quote, and a retired or switched-off product must not go on it.
            $catalogue = CatalogItem::query()
                ->where('workspace_id', $workspaceId)
                ->where('is_active', true)
                ->where('type', '!=', 'bundle')
                ->whereIn('id', array_values($wanted))
                ->get()
                ->keyBy('id')
                ->all();
        }

        $lines = [];
        $dropped = [];

        foreach ($proposed as $line) {
            $row = $this->arrayOf($line);
            $id = $this->id($row['catalog_item_id'] ?? null);
            $item = $id === null ? null : ($catalogue[$id] ?? null);

            if (! $item instanceof CatalogItem) {
                $dropped[] = [
                    'key' => OfferDrafter::DROP_UNKNOWN_ITEM,
                    'name' => (string) ($this->cut($this->str($row['name'] ?? null), 120) ?? '#'.(string) $id),
                    'detail' => '',
                ];

                continue;
            }

            $row['catalog_item_id'] = (int) $item->id;
            $row['unit_price_cents'] = $this->clamped($row['unit_price_cents'] ?? null, $item);
            $lines[] = $row;
        }

        return ['lines' => $lines, 'dropped' => array_slice($dropped, 0, OfferDrafter::MAX_NOTICES)];
    }

    /**
     * What one line costs per unit, inside the range the firm set.
     *
     * With nothing proposed, the catalogue price. A floor typed above the ceiling
     * is a data-entry slip, not licence to quote over the list price, so the
     * floor is itself capped at the ceiling before either is applied.
     */
    private function clamped(mixed $proposed, CatalogItem $item): int
    {
        $ceiling = max(0, (int) $item->price_cents);
        $floor = min($ceiling, max(0, (int) ($item->min_price_cents ?? 0)));

        if ($proposed === null || $proposed === '' || ! is_numeric($proposed)) {
            return $ceiling;
        }

        return max($floor, min($ceiling, (int) $proposed));
    }

    /**
     * The left-hand column of the offer's own page: what the customer said, what
     * the agent understood of it, and why it chose what it chose.
     *
     * null for every offer a person built by hand — there is nothing to show and
     * the column is not drawn.
     *
     * Reads ai_json in the shape DraftOfferJob writes and regenerate() rewrites.
     * Every key is read through the widening helpers below, because a draft
     * written by an older version of either is still on the firm's list.
     *
     * @return array<string, mixed>|null
     */
    private function agentPanel(Offer $offer, int $workspaceId): ?array
    {
        if ((string) $offer->source !== 'ai') {
            return null;
        }

        $json = $this->aiJson($offer);
        $conversation = $this->ownedConversation($workspaceId, $offer);

        return [
            'interpretation' => $this->readingOf($offer),
            'corrected' => (bool) ($json['corrected'] ?? false),
            'summary' => $offer->ai_reason,
            // "DE CE A ALES ACESTE PRODUSE": the drafter's per-line
            // justification, paired with the line it belongs to, which is what
            // the screen renders as "Scaun ergonomic — se potrivește bugetului".
            'reasons' => $this->reasons($json['lines'] ?? null),
            // Everything it refused, and everything it kept but flagged. Both are
            // {key, name, detail} with key a translation key — "de ce nu e X în
            // ofertă" is the first question the person approving it asks.
            'dropped' => $this->notices($json['dropped'] ?? null),
            'warnings' => $this->notices($json['warnings'] ?? null),
            'messages' => $this->requestMessages($offer, $conversation, $json),
            // The uuid and not a URL: the screen builds the route itself, and it
            // has to be able to render with the inbox route absent.
            'conversation_uuid' => $conversation === null
                ? null
                : $this->str($conversation->getAttribute('uuid')),
            'model' => $this->str($json['model'] ?? null),
            'regenerations' => $this->int($json['regenerations'] ?? null),
            // The visible failed state, and the in-flight one. Either a rebuild
            // that produced nothing, recorded on the offer, or the last attempt
            // on this conversation — which may still be queued, or may have
            // failed after this offer was drafted.
            'attempt' => $this->attempt($json, $this->latestAttempt($workspaceId, $conversation)),
            'can_regenerate' => (string) $offer->status === 'draft',
        ];
    }

    /**
     * "Cererea clientului" — the customer's own words, verbatim, oldest first.
     *
     * The messages the draft was actually built from when the run recorded them,
     * which is what makes this the request and not just the recent thread. With
     * no record — an older draft — the last few inbound messages before the offer
     * was written, which is the same question answered less precisely.
     *
     * @param  array<string, mixed>  $json
     * @return list<array<string, mixed>>
     */
    private function requestMessages(Offer $offer, ?Conversation $conversation, array $json): array
    {
        if ($conversation === null) {
            return [];
        }

        $ids = [];

        foreach ($this->listOf($json['source_message_ids'] ?? null) as $id) {
            $id = $this->id($id);

            if ($id !== null) {
                $ids[] = $id;
            }
        }

        $messages = $ids === []
            ? $this->inbound($conversation, self::REQUEST_MESSAGES, $offer)
            : $this->inbound($conversation, count($ids), null, $ids);

        return $messages->map(fn (Message $message): array => [
            'id' => $this->int($message->getKey()),
            // Through Demo::text, which is what Message::demoMask() asks for and
            // what toArray() would have applied. A hand-built prop array gets
            // neither for free, and the customer's own words are the one thing on
            // this page that is somebody's personal data.
            'body' => Demo::text($this->str($message->getAttribute('body'))),
            'type' => $this->str($message->getAttribute('type')) ?? 'text',
            'at' => $message->getAttribute('sent_at') ?? $message->getAttribute('created_at'),
        ])->values()->all();
    }

    /**
     * The per-line justification, as the bullets the screen draws.
     *
     * @return list<array{name: string, reason: string}>
     */
    private function reasons(mixed $lines): array
    {
        $out = [];

        foreach ($this->listOf($lines) as $line) {
            $row = $this->arrayOf($line);
            $reason = $this->cut($this->str($row['reason'] ?? null), 300);

            if ($reason === null) {
                continue;
            }

            $out[] = [
                'name' => (string) ($this->cut($this->str($row['name'] ?? null), 255) ?? ''),
                'reason' => $reason,
            ];
        }

        return array_slice($out, 0, OfferDrafter::MAX_NOTICES);
    }

    /**
     * A dropped-or-flagged list, cleaned.
     *
     * `key` is a translation key the screen resolves; `name` and `detail` are the
     * drafter's own words about the firm's own catalogue, never a provider's.
     *
     * @return list<array{key: string, name: string, detail: string}>
     */
    private function notices(mixed $notices): array
    {
        $out = [];

        foreach ($this->listOf($notices) as $notice) {
            $row = $this->arrayOf($notice);
            $key = $this->str($row['key'] ?? null);

            if ($key === null) {
                continue;
            }

            $out[] = [
                'key' => $this->reasonKey($key),
                'name' => (string) ($this->cut($this->str($row['name'] ?? null), 255) ?? ''),
                'detail' => (string) ($this->cut($this->str($row['detail'] ?? null), 255) ?? ''),
            ];
        }

        return array_slice($out, 0, OfferDrafter::MAX_NOTICES);
    }

    /**
     * Where the drafting of this offer stands: failed, still queued, or done.
     *
     * ONE prop for two sources, because from the screen they are the same thing.
     * The offer's own record wins whenever a rebuild has been tried: it is both
     * the more recent of the two and the one the person just caused. Otherwise
     * the last attempt on the conversation, which is what tells the screen a
     * background draft is still in flight and it should keep looking.
     *
     * `reason` is a translation key the screen resolves with t(), in exactly the
     * vocabulary the inbound path writes into offer_draft_attempts.reason — so
     * the same failure reads the same whether it happened in the background or
     * under somebody's finger. null on an attempt that succeeded.
     *
     * @param  array<string, mixed>  $json
     * @return array{status: string, reason: string|null, at: string|null}|null
     */
    private function attempt(array $json, ?OfferDraftAttempt $latest): ?array
    {
        $own = $this->arrayOf($json['failure'] ?? null);
        $reason = $this->str($own['reason'] ?? null);

        if ($reason !== null) {
            return [
                'status' => OfferDraftAttempt::STATUS_FAILED,
                'reason' => $this->reasonKey($reason),
                'at' => $this->str($own['at'] ?? null),
            ];
        }

        if ($latest === null) {
            return null;
        }

        $stored = $this->str($latest->reason);
        $created = $latest->getAttribute('created_at');

        return [
            'status' => (string) $latest->status,
            'reason' => $stored === null ? null : $this->reasonKey($stored),
            'at' => $created instanceof Carbon ? $created->toIso8601String() : null,
        ];
    }

    /**
     * A stored reason, reduced to something that is definitely a translation key.
     *
     * SEVERAL FAMILIES land in these columns and all of them are legitimate:
     * OfferDrafter's own `offers.draft_*` and `offers.drop_*` and `offers.warn_*`,
     * OfferDraftAttempt's `offers.ai_*`, and LlmGateway's `ai.structured.*` where
     * the drafter has not mapped them. So this is a shape check and not an
     * allow-list — a new key on any side must not need a second edit here to be
     * storable.
     *
     * What it exists to stop is the other thing: a provider's own words reaching
     * a column that is rendered on a page. An error body can echo the prompt
     * back, and the prompt carries the customer's words and the firm's floor
     * prices. Anything that is not a short dotted lower-case key is not one.
     */
    private function reasonKey(string $reason): string
    {
        return mb_strlen($reason) <= 64 && preg_match('/^[a-z][a-z0-9_]*(?:\.[a-z0-9_]+)+$/', $reason) === 1
            ? $reason
            : OfferDraftAttempt::REASON_UNEXPECTED;
    }

    /**
     * The most recent draft attempt on one conversation.
     *
     * Reads (workspace_id, conversation_id, created_at) end to end. With no
     * conversation there is nothing to read and nothing is asked.
     */
    private function latestAttempt(int $workspaceId, ?Conversation $conversation): ?OfferDraftAttempt
    {
        if ($conversation === null) {
            return null;
        }

        return OfferDraftAttempt::query()
            ->where('workspace_id', $workspaceId)
            ->where('conversation_id', (int) $conversation->id)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->first();
    }

    /**
     * What the customer wrote, oldest first.
     *
     * messages carries no workspace_id of its own — it is reached only through a
     * conversation. So the conversation is confirmed to be this workspace's
     * before a single message is read, and never afterwards; the conversation id
     * is named in the query again so a list of ids from ai_json cannot pull a row
     * out of another thread.
     *
     * Bounded, always: a thread can hold thousands of messages, and reading all
     * of one to keep the last eight is the query that makes the offer page slow
     * for exactly the firms that use the product most.
     *
     * @param  list<int>|null  $ids  the specific messages a draft was built from
     * @return EloquentCollection<int, Message>
     */
    private function inbound(Conversation $conversation, int $limit, ?Offer $before = null, ?array $ids = null): EloquentCollection
    {
        $query = Message::query()
            ->where('conversation_id', (int) $conversation->id)
            ->where('direction', 'in');

        if ($ids !== null) {
            $query->whereIn('id', $ids);
        } elseif ($before !== null) {
            $query->where('created_at', '<=', $before->getAttribute('created_at') ?? now());
        }

        // Newest first with a LIMIT, then reversed — the same read DraftOfferJob
        // makes, for the same reason.
        return $query->orderByDesc('id')
            ->limit(max(1, $limit))
            ->get()
            ->reverse()
            ->values();
    }

    /**
     * This offer's conversation, but only if it is this workspace's.
     *
     * conversation_id is a plain column with no foreign key — an integer somebody
     * could have written, not a promise.
     *
     * Deliberately NOT conversationFor(), which falls back to the contact's most
     * recent thread: that is the right guess for where to send an offer and the
     * wrong one for "here is what the customer said", where a guess prints
     * somebody else's words above a quote.
     */
    private function ownedConversation(int $workspaceId, Offer $offer): ?Conversation
    {
        if ($offer->conversation_id === null) {
            return null;
        }

        return Conversation::query()
            ->where('workspace_id', $workspaceId)
            ->whereKey((int) $offer->conversation_id)
            ->first();
    }

    /**
     * The five boxes of "ce a înțeles agentul", cleaned to exactly the shape
     * OfferDrafter accepts back.
     *
     * The keys are the Romanian words the screen shows and the drafter emits —
     * cere, buget, termen, cerințe, pentru. They stay in that vocabulary in the
     * payload, in ai_json and in the prompt, so the screen, the column and the
     * model cannot drift into three different names for the same five boxes.
     * This is the one place in the module where that is true; everything else is
     * English, as the project requires.
     *
     * Every field is a string and never null, and cerinte is always a list —
     * that is the contract OfferDrafter::cleanInterpretation() declares, and a
     * null arriving where it expects a string is a TypeError in a queued job.
     *
     * @param  array<string, mixed>  $data
     * @return array{cere: string, buget: string, termen: string, cerinte: list<string>, pentru: string}
     */
    private function reading(array $data): array
    {
        $cerinte = [];

        foreach ($this->listOf($data['cerinte'] ?? null) as $line) {
            $line = $this->cut($this->str($line), OfferDrafter::MAX_FIELD_CHARS);

            if ($line !== null) {
                $cerinte[] = $line;
            }
        }

        return [
            'cere' => $this->field($data, 'cere'),
            'buget' => $this->field($data, 'buget'),
            'termen' => $this->field($data, 'termen'),
            'cerinte' => array_slice($cerinte, 0, OfferDrafter::MAX_REQUIREMENTS),
            'pentru' => $this->field($data, 'pentru'),
        ];
    }

    /**
     * One field of the reading: a trimmed, cut string, and '' for a box somebody
     * left empty.
     *
     * @param  array<string, mixed>  $data
     */
    private function field(array $data, string $key): string
    {
        return (string) ($this->cut($this->str($data[$key] ?? null), OfferDrafter::MAX_FIELD_CHARS) ?? '');
    }

    /**
     * The reading currently on the offer — the corrected one where a person has
     * corrected it, otherwise the one the agent wrote.
     *
     * @return array{cere: string, buget: string, termen: string, cerinte: list<string>, pentru: string}
     */
    private function readingOf(Offer $offer): array
    {
        return $this->reading($this->arrayOf($this->aiJson($offer)['interpretation'] ?? null));
    }

    /**
     * ai_json with some keys replaced.
     *
     * Merged and not overwritten: the column holds the reading, the
     * justification, what was dropped and the last failure, and the two writers
     * of it each replacing the whole object would each erase the other's keys —
     * drafted_at, written once by the job, most of all. A null in the patch
     * clears its key, which is how a fixed failure stops being reported.
     *
     * @param  array<string, mixed>  $patch
     * @return array<string, mixed>
     */
    private function mergeAiJson(Offer $offer, array $patch): array
    {
        return array_filter(
            array_merge($this->aiJson($offer), $patch),
            static fn (mixed $value): bool => $value !== null,
        );
    }

    /** @return array<string, mixed> */
    private function aiJson(Offer $offer): array
    {
        return $this->arrayOf($offer->ai_json);
    }

    /**
     * Whether this firm has switched the agent's drafting on. Off by default.
     *
     * Read straight from ClientSetting, on the same key and with the same
     * filter_var reading as
     * App\Modules\Offers\Listeners\DraftOfferFromMessageListener: the column is
     * text, and the string "false" is truthy in PHP. A gate that ships false must
     * not depend on being present in somebody's DEFAULTS array to stay false,
     * which is why it is not one of OfferSettings' five money settings.
     *
     * Per client and not per workspace, because that is the grain client_settings
     * has and where the rest of the offer settings already live. A workspace with
     * no client has nowhere to store it, so it reads false.
     */
    private function aiDraftingEnabled(?int $clientId): bool
    {
        if ($clientId === null) {
            return false;
        }

        return filter_var(
            ClientSetting::get($clientId, self::AI_DRAFTING_KEY, '0'),
            FILTER_VALIDATE_BOOLEAN,
        );
    }

    /**
     * Only a draft the agent prepared can have its reading corrected or its lines
     * rebuilt.
     *
     * Both refusals are said in Romanian rather than aborted: the person is on
     * the offer's own page, and a 403 there reads as the application being broken.
     */
    private function assertAiDraft(Offer $offer): void
    {
        if ((string) $offer->source !== 'ai') {
            throw ValidationException::withMessages([
                'source' => __('Only an offer the agent prepared can be rebuilt.'),
            ]);
        }

        if ((string) $offer->status !== 'draft') {
            throw ValidationException::withMessages([
                'status' => __('This offer is no longer a draft, so the agent can no longer rebuild it.'),
            ]);
        }
    }

    // ------------------------------------------------- reading untrusted maps

    /**
     * Everything the drafter hands back, and everything read out of ai_json,
     * comes through these six.
     *
     * They take mixed on purpose. The answer has already been through a model and
     * a JSON decode, and ai_json is a column holding rows written by every past
     * version of the job — a shape this controller merely assumes is a 500 on the
     * offer page the first time one of them is a little different.
     *
     * @return array<string, mixed>
     */
    private function arrayOf(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $out = [];

        foreach ($value as $key => $item) {
            $out[(string) $key] = $item;
        }

        return $out;
    }

    /** @return list<mixed> */
    private function listOf(mixed $value): array
    {
        return is_array($value) ? array_values($value) : [];
    }

    private function str(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }

    private function int(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }

    private function cut(?string $value, int $length): ?string
    {
        return $value === null ? null : mb_substr($value, 0, $length);
    }

    // --------------------------------------------------------------- guards

    /**
     * The uuid route binding finds the row in any workspace — the check that it
     * belongs to this one is ours to make, every time.
     */
    private function authorise(Request $request, Offer $offer): void
    {
        abort_unless((int) $offer->workspace_id === (int) $request->user()->workspace_id, 403);
    }

    /**
     * Only a draft is editable.
     *
     * A sent offer is a document the customer is holding a copy of; changing
     * the prices underneath it would mean the two sides are reading different
     * quotes with the same number on them.
     */
    private function assertEditable(Offer $offer): void
    {
        if ((string) $offer->status !== 'draft') {
            throw ValidationException::withMessages([
                'status' => __('This offer is no longer a draft and can no longer be edited.'),
            ]);
        }
    }

    /**
     * A contact id this workspace actually owns, or null.
     *
     * withTrashed for the same reason the catalogue check uses it: the question
     * is whose contact this is. Anything else is a validation error rather than
     * a quietly unassigned offer.
     */
    private function ownedContactId(int $workspaceId, mixed $contactId): ?int
    {
        $id = $this->id($contactId);

        if ($id === null) {
            return null;
        }

        $owned = Contact::withTrashed()
            ->where('workspace_id', $workspaceId)
            ->whereKey($id)
            ->exists();

        if (! $owned) {
            throw ValidationException::withMessages([
                'contact_id' => __('This contact does not belong to your workspace.'),
            ]);
        }

        return $id;
    }

    // -------------------------------------------------------------- helpers

    /**
     * The rules both writes share.
     *
     * The lines are shape-checked here and ownership-checked in lines(): a rule
     * cannot ask the catalogue a question about a whole array at once without
     * running one query per row.
     *
     * @return array<string, array<int, string>>
     */
    private function itemRules(): array
    {
        return [
            'items' => ['sometimes', 'nullable', 'array', 'max:'.self::MAX_ITEMS],
            'items.*.catalog_item_id' => ['nullable', 'integer', 'min:1'],
            'items.*.name' => ['nullable', 'string', 'max:255'],
            'items.*.unit' => ['nullable', 'string', 'max:32'],
            'items.*.quantity' => ['nullable', 'numeric', 'min:0', 'max:999999'],
            // Bani. 99999999999 is a hundred million lei, which is well past any
            // quote a firm of this size writes, and short of the column's range.
            'items.*.unit_price_cents' => ['nullable', 'integer', 'min:0', 'max:99999999999'],
            // Lei, for a caller that speaks them. See unitPrice().
            'items.*.unit_price' => ['nullable', 'numeric', 'min:0', 'max:999999999'],
        ];
    }

    /**
     * The items out of a validated payload, as a list.
     *
     * @param  array<string, mixed>  $data
     * @return array<int, mixed>
     */
    private function itemsIn(array $data): array
    {
        $items = $data['items'] ?? null;

        return is_array($items) ? array_values($items) : [];
    }

    /**
     * One query parameter, as a trimmed string.
     *
     * Deliberately not $request->string(), which casts through Stringable and
     * raises "Array to string conversion" on ?search[]=x — a 500 on a URL
     * anybody can type. Anything that is not a string is simply not a filter.
     */
    private function input(Request $request, string $key): string
    {
        $value = $request->input($key);

        return is_string($value) ? trim($value) : '';
    }

    /**
     * A calendar day from the query string, or null.
     *
     * Shape-checked rather than parsed: anything that is not YYYY-MM-DD is not a
     * date filter, and never reaches the query.
     */
    private function day(Request $request, string $key): ?string
    {
        $value = $this->input($request, $key);

        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1 ? $value : null;
    }

    /** A positive id, or null for a box the person left empty. */
    private function id(mixed $value): ?int
    {
        if ($value === null || $value === '' || ! is_numeric($value)) {
            return null;
        }

        $id = (int) $value;

        return $id > 0 ? $id : null;
    }

    /** A trimmed string, or null for a box the person left empty. */
    private function text(mixed $value): ?string
    {
        $value = is_string($value) ? trim($value) : '';

        return $value === '' ? null : $value;
    }
}
