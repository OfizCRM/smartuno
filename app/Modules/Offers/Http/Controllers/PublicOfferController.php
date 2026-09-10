<?php

namespace App\Modules\Offers\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\ClientProfile;
use App\Models\Workspace;
use App\Modules\Offers\Models\Offer;
use App\Modules\Offers\Models\OfferItem;
use App\Modules\Offers\Services\OfferSettings;
use App\Modules\Shared\Models\Contact;
use App\Support\Romania;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\URL;

/**
 * The offer as the customer sees it, on a link they can open without an account.
 *
 * THIS IS THE FIRST UNAUTHENTICATED ROUTE IN THE PRODUCT THAT SERVES A TENANT'S
 * OWN DATA. Everything that protects it is on this page and on the four lines
 * that register the route. There is no session to resolve a workspace from, no
 * EnsureClientScope, no middleware that will notice a mistake here — a leak is a
 * competitor reading a firm's prices, so the reasoning is written out.
 *
 * WHAT STANDS BETWEEN A STRANGER AND AN OFFER
 *
 * 1. The uuid. Offers are addressed by uuid, never by id: the URL carries no
 *    sequence to walk, there is no listing endpoint, and there is no "next
 *    offer" of any shape. Guessing one is guessing 122 bits.
 *
 * 2. A relative signature over the whole path and query. Relative — generated
 *    with absolute:false, checked with hasValidRelativeSignature() — because the
 *    host in an absolute signature is part of what is signed, and this
 *    application is reached on a different host than the one it generates from
 *    in some deployments (TRUSTED_PROXIES is '*' and X-Forwarded-Host is
 *    trusted), which would turn every link into a 403 on those installs. The
 *    uuid is inside the signed path, so offer A's signature does not open offer
 *    B: change the uuid and the HMAC no longer matches.
 *
 * 3. An expiry that is the offer's own valid_until, capped at thirty days. A
 *    quote that is no longer valid is not a page anybody needs, and the link
 *    dies with the document rather than living forever in a WhatsApp thread that
 *    gets forwarded.
 *
 * 4. The signature is checked twice: by the `signed:relative` middleware on the
 *    route, and again on the first line of show(). Belt and braces on purpose —
 *    a route re-registered one day without that middleware would otherwise
 *    publish every offer in the database, and this is not the file to find that
 *    out in.
 *
 * WHY A BLADE VIEW AND NOT AN INERTIA PAGE
 *
 * HandleInertiaRequests shares the whole User model with every Inertia response
 * (C1 in the debt register). An Inertia page here would ship that, plus whatever
 * else the authenticated layout shares, into a document a stranger is holding.
 * This renders server-side, standalone, with no app layout, no Inertia and no
 * session — the route is deliberately outside the `web` group, so it sets no
 * session cookie and reads none, and every response header it needs it sets
 * itself.
 *
 * WHAT THE PAGE MAY CONTAIN
 *
 * Exactly what resources/views/pdf/offer.blade.php contains: the seller's
 * printed identity, the buyer as addressed, the lines, the totals, the validity
 * and the notes. Nothing else is passed to the view — not ai_json, not the
 * attempt rows, not the internal notes, not the conversation, not another
 * contact, not a workspace name that was never on the document. The view cannot
 * print what it was not given, which is why the props are assembled here by
 * hand and never as a model dump.
 *
 * TENANCY, WITH NO SESSION TO ANCHOR IT
 *
 * The workspace is not resolved from a user, because there is no user. It is
 * taken from the offer the signature named, and every further read — the lines,
 * the contact, the firm's own settings — is filtered by that workspace_id on its
 * own table rather than reached through a relation. offer_items carries
 * workspace_id itself; it is used.
 */
class PublicOfferController extends Controller
{
    /** The route the link points at. One name, referred to nowhere else by string. */
    private const ROUTE = 'offers.public';

    /**
     * The longest a link may live, whatever the offer says.
     *
     * A firm can set validity_days up to 365. Thirty days is the ceiling for the
     * link regardless: the page has no login behind it, so its lifetime is the
     * window in which a forwarded WhatsApp message is still a working key to a
     * firm's prices. An offer valid longer than that is re-shared from the app,
     * which mints a fresh link, rather than left open for a year.
     */
    private const MAX_LINK_DAYS = 30;

    /**
     * The policy this page serves itself under.
     *
     * Nothing loads: no script, no image, no font, no stylesheet, no form
     * target, no frame that may embed it. The only inline thing is the <style>
     * block the page ships with, which is why style-src is the single exception.
     * It is set on the response rather than left to SecureHeaders — that
     * middleware is not in this route's stack at all, and even where it is it
     * steps aside for a response that already carries its own policy.
     */
    private const CSP = "default-src 'none'; style-src 'unsafe-inline'; base-uri 'none'; form-action 'none'; frame-ancestors 'none'";

    public function __construct(
        private readonly OfferSettings $settings,
    ) {}

    /**
     * The offer, for whoever is holding a valid link to it.
     *
     * The uuid arrives as a string rather than through route-model binding, so
     * the lookup, the 404 and the "has this actually been sent" check are all
     * visible here rather than happening in a resolver.
     */
    public function show(Request $request, string $offer): Response
    {
        // ?signature[]=x reaches Laravel's own (string) cast on the query
        // parameter and raises an ErrorException — an unauthenticated 500, one
        // ERROR line per request, and a full stack trace on any install left
        // with APP_DEBUG on. Checked before anything reads it.
        abort_unless(
            is_string($request->query('signature')) && is_string($request->query('expires')),
            403,
        );

        // Laravel treats a MISSING expires as "never expires", so a signature
        // minted without one would be a permanent link. MAX_LINK_DAYS is a
        // minting-time convention; this is the only place it is enforced on the
        // way in, which is what makes it a rule rather than a habit.
        $expires = (int) $request->query('expires');
        abort_if($expires <= 0 || $expires > now()->addDays(self::MAX_LINK_DAYS)->getTimestamp(), 403);

        // Defence in depth behind the route's own `signed:relative`. See the
        // note at the top of this file.
        abort_unless($request->hasValidRelativeSignature(), 403);

        // Soft-deleted offers are excluded by the model's own SoftDeletes: an
        // offer the firm threw away stops answering, link or no link.
        $record = Offer::query()->where('uuid', $offer)->first();

        // 404 and not 403 for both cases below: a link that no longer opens
        // anything should not confirm which of the two reasons applies.
        abort_if($record === null, 404);
        abort_unless($this->viewable($record), 404);

        // From here on, this is the tenancy anchor. It comes from the offer the
        // signature named, and it is applied to every table read below.
        $workspaceId = (int) $record->workspace_id;

        $items = OfferItem::query()
            ->where('workspace_id', $workspaceId)
            ->where('offer_id', $record->id)
            ->orderBy('position')
            ->orderBy('id')
            ->get();

        // Its own workspace clause rather than $record->contact: contact_id is a
        // plain column, and this is the one page where a wrong row is a
        // stranger's name and phone number on somebody else's quote.
        $contact = $record->contact_id === null ? null : Contact::query()
            ->where('workspace_id', $workspaceId)
            ->whereKey((int) $record->contact_id)
            ->first();

        $clientId = $this->clientId($workspaceId);
        $settings = $this->settings->get($clientId);

        return response()
            ->view('offers.public', [
                'offer' => $record,
                'items' => $items,
                'contact' => $contact,
                'seller' => $this->seller($clientId),
                // Two keys, not the whole settings array. The view needs the
                // footer line and needs to know whether the firm offers free
                // delivery at all; the standing discount, the validity window
                // and the delivery charge are the firm's internal configuration
                // and have no business travelling to a customer's browser.
                'settings' => [
                    'footer_text' => (string) $settings['footer_text'],
                    'free_shipping_cents' => (int) $settings['free_shipping_cents'],
                ],
                // Written the way a Romanian reads them, pinned to Romanian by
                // App\Support\Romania whatever the app locale is — the person
                // reading this page is always the Romanian customer.
                'issuedAt' => Romania::longDate($record->getAttribute('created_at')),
                'validUntil' => $record->valid_until !== null ? Romania::longDate($record->valid_until) : null,
                // One formatter for the lines and the totals both, so a column
                // cannot end up formatted two ways on the same page.
                'money' => static fn (mixed $cents): string => self::lei($cents),
                'quantity' => static fn (mixed $value): string => self::quantity($value),
            ])
            ->withHeaders([
                'Content-Security-Policy' => self::CSP,
                // frame-ancestors above is the real control; this is for the
                // browsers that never implemented it.
                'X-Frame-Options' => 'DENY',
                'X-Content-Type-Options' => 'nosniff',
                // The signature lives in the query string, so it must not walk
                // out in a Referer header. The page links nowhere, and this
                // keeps it that way if it ever does.
                'Referrer-Policy' => 'no-referrer',
                'X-Robots-Tag' => 'noindex, nofollow, noarchive, nosnippet',
                // Tenant data on a URL anyone holding the link can fetch: no
                // shared cache, no proxy copy, no back-button restore after the
                // link has expired.
                'Cache-Control' => 'private, no-store, no-cache, must-revalidate, max-age=0',
                'Pragma' => 'no-cache',
            ]);
    }

    /**
     * The link for the "Vezi ca client" button, or null when there is none.
     *
     * NULL IS THE NORMAL ANSWER FOR A DRAFT. A draft is a document the firm is
     * still writing; it has no link, and the button is not drawn. A link exists
     * from the moment the offer was actually sent, and stops existing when the
     * offer stops being valid — an accepted quote is still openable by the
     * customer who accepted it, right up to the day it was valid until.
     *
     * Nothing is stored. The link is minted fresh on every render of the offer
     * page, so there is no token column to leak, to rotate, or to forget to
     * revoke; the signature and its expiry are the whole of it.
     */
    public static function linkFor(Offer $offer): ?string
    {
        if ($offer->sent_at === null || ! self::viewableStatus((string) $offer->status)) {
            return null;
        }

        $expiresAt = self::linkExpiry($offer);

        if ($expiresAt === null) {
            return null;
        }

        // absolute:false — see the note at the top of this file. url() then puts
        // the host the person is actually on in front of it, which is the host
        // they are about to paste into a message.
        return url(URL::signedRoute(
            self::ROUTE,
            ['offer' => $offer->uuid],
            $expiresAt,
            absolute: false,
        ));
    }

    /**
     * When the link stops working: the day the offer stops being valid, or
     * thirty days from now, whichever comes first.
     *
     * The date is read as a Romanian calendar day and expires at midnight in
     * Bucharest, not at midnight UTC — valid_until is cast to a date, so
     * untreated it would expire at three in the morning local time on the day
     * after the one printed on the document.
     *
     * Returns null when that moment has already passed. An offer whose validity
     * ran out yesterday gets no link at all, rather than a link that is dead the
     * instant it is copied.
     */
    private static function linkExpiry(Offer $offer): ?CarbonImmutable
    {
        $now = CarbonImmutable::now();
        $expiresAt = $now->addDays(self::MAX_LINK_DAYS);

        if ($offer->valid_until !== null) {
            $validUntil = CarbonImmutable::parse(
                $offer->valid_until->toDateString(),
                Romania::DEFAULT_TIMEZONE,
            )->endOfDay();

            if ($validUntil->lessThan($expiresAt)) {
                $expiresAt = $validUntil;
            }
        }

        return $expiresAt->greaterThan($now) ? $expiresAt : null;
    }

    /**
     * Whether this offer may be shown to a guest at all.
     *
     * A draft never may: it is unfinished, its prices move under the person
     * editing it, and it is not a document anybody was handed. Everything that
     * has genuinely left the building — sent, and then whatever the customer
     * decided about it — stays readable, because the customer keeps the right to
     * re-open the quote they were sent. sent_at is checked as well as the
     * status: the status column is a string, and "it was sent" is a fact about
     * the timestamp.
     */
    private function viewable(Offer $offer): bool
    {
        if ($offer->sent_at === null || ! self::viewableStatus((string) $offer->status)) {
            return false;
        }

        // Re-checked on use, not only when the link was minted. A firm that
        // moves valid_until back, or lets the sweep mark the offer expired, has
        // withdrawn the quote — and the link that went out a fortnight ago must
        // stop opening it rather than keep showing a price nobody will honour.
        $validUntil = $offer->getAttribute('valid_until');

        return $validUntil === null || ! Carbon::parse((string) $validUntil)->endOfDay()->isPast();
    }

    private static function viewableStatus(string $status): bool
    {
        return ! in_array($status, ['draft', 'expired'], true);
    }

    /**
     * The firm behind this workspace, or null.
     *
     * workspaces.client_id is nullable and legitimately null for a workspace
     * created before the company profile was filled in, so both this and
     * seller() answer for that case rather than assuming a row.
     */
    private function clientId(int $workspaceId): ?int
    {
        $clientId = Workspace::query()->whereKey($workspaceId)->value('client_id');

        return is_numeric($clientId) ? (int) $clientId : null;
    }

    /**
     * The seller's identity as it is printed on the offer, and not one field
     * more.
     *
     * The same six values pdf.offer prints in its header. The mobile number, the
     * website, the contact person, the industry, the share capital and the
     * social links are all on the same client_profiles row and none of them are
     * read: this is the seller's fiscal identity on a commercial document, not
     * their profile page.
     *
     * The address is composed through Romania::composeAddress rather than by
     * gluing the four columns together here — it is the shared helper for
     * exactly this, it writes the county out in full instead of as a code, and
     * it is the reason a firm in Iași does not read "Iași, Iași".
     *
     * @return array<string, string|null>
     */
    private function seller(?int $clientId): array
    {
        if ($clientId === null) {
            return [];
        }

        $profile = ClientProfile::query()->where('client_id', $clientId)->first();

        if ($profile === null) {
            return [];
        }

        return [
            'legal_name' => $profile->legal_name,
            'cui' => $profile->cui,
            'trade_register_no' => $profile->trade_register_no,
            'iban' => $profile->iban,
            'bank_name' => $profile->bank_name,
            'address' => Romania::composeAddress([
                'address_street' => $profile->address_street,
                'address_city' => $profile->address_city,
                'address_county' => $profile->address_county,
                'address_postcode' => $profile->address_postcode,
            ]),
        ];
    }

    /**
     * Bani as a Romanian reads them: "1.234,50". The currency is added by the
     * view.
     *
     * The same two lines as OfferPdfRenderer's own formatter, which is private
     * to that service and belongs to it — this stage does not reach into another
     * stage's class to make it public. If a third caller ever appears, the pair
     * of them move to Catalog\Support\Money together; two is not yet a pattern.
     */
    private static function lei(mixed $cents): string
    {
        return number_format(((int) $cents) / 100, 2, ',', '.');
    }

    /** "2", "2,5", "0,75" — three decimals are stored, none shown unless they mean something. */
    private static function quantity(mixed $value): string
    {
        $formatted = number_format((float) (is_scalar($value) ? $value : 0), 3, ',', '');

        return rtrim(rtrim($formatted, '0'), ',');
    }
}
