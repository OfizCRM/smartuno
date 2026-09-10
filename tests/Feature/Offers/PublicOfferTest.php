<?php

namespace Tests\Feature\Offers;

use App\Models\ClientProfile;
use App\Modules\Catalog\Models\CatalogItem;
use App\Modules\Offers\Models\Offer;
use App\Modules\Offers\Models\OfferDraftAttempt;
use App\Modules\Offers\Models\OfferItem;
use App\Modules\Shared\Models\Contact;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Tests\Concerns\FakesPrivateDisk;
use Tests\TestCase;

/**
 * "Vezi ca client" — the offer on a link somebody opens with no account.
 *
 * THIS IS THE FIRST UNAUTHENTICATED ROUTE IN THE PRODUCT THAT SERVES A TENANT'S
 * OWN DATA, and it is the reason this file is longer than the feature it tests.
 * There is no session to resolve a workspace from, no EnsureClientScope, no
 * middleware that will notice a mistake. A leak here is a competitor reading a
 * Romanian firm's prices, and a firm that finds that out does not come back.
 *
 * The properties, in the order they matter:
 *
 *   1. No signature, a changed signature, or a signature whose time has passed
 *      opens nothing.
 *   2. One offer's signature does not open another's. The uuid is inside the
 *      signed path, and this is the test that proves it stays there.
 *   3. A draft has no public page at all. Its prices move under the person
 *      editing it and nobody was ever handed it.
 *   4. The page carries THIS offer and nothing else — no other offer, no other
 *      contact, no internal notes, no ai_json, no attempt rows, and nothing at
 *      all from HandleInertiaRequests, which shares the whole User model with
 *      every Inertia response (C1 in the debt register). That is why the page is
 *      a server-rendered blade and not an Inertia page, and there is an
 *      assertion here that keeps it one.
 *   5. It is not indexed, not framed, not cached by a proxy, and it serves its
 *      own Content-Security-Policy rather than the application's, which permits
 *      inline script.
 *   6. There is nothing to enumerate: no listing, no numeric id, no neighbour —
 *      and the route is rate limited, so guessing is bounded as well as
 *      hopeless.
 */
class PublicOfferTest extends TestCase
{
    use FakesPrivateDisk, RefreshDatabase;

    /** @var array<string, mixed> */
    private array $ctx;

    private int $seq = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakePrivateDisk();

        // Deliberately distinctive: every one of these strings is asserted
        // absent from a page a stranger is holding.
        $this->ctx = $this->createWorkspaceContext(
            ['name' => 'NUMELE INTERN AL CLIENTULUI'],
            ['name' => 'Angajatul Intern', 'email' => 'intern@nu-trebuie-sa-apara.ro'],
            ['name' => 'NUME INTERN DE WORKSPACE'],
        );
    }

    // ─── fixtures ────────────────────────────────────────────────────────

    private function workspaceId(): int
    {
        return (int) $this->ctx['workspace']->id;
    }

    /**
     * An offer that has genuinely left the building.
     *
     * forceFill rather than the send endpoint: sending needs a channel account
     * and a mocked driver, and none of that is what this file is about. What the
     * public page reads is the status and sent_at, and those are set here
     * exactly as a real send sets them.
     *
     * @param  array<string, mixed>  $attrs
     * @param  array<int, array{name: string, quantity: float|int, unit_price_cents: int}>  $lines
     */
    private function sentOffer(array $attrs = [], array $lines = [], ?int $workspaceId = null): Offer
    {
        $wid = $workspaceId ?? $this->workspaceId();

        $subtotal = 0;
        foreach ($lines as $line) {
            $subtotal += (int) round(((float) $line['quantity']) * (int) $line['unit_price_cents']);
        }

        $offer = Offer::create(array_merge([
            'workspace_id' => $wid,
            'number' => 'OF-'.now()->format('Y').'-'.str_pad((string) ++$this->seq, 5, '0', STR_PAD_LEFT),
            'status' => 'sent',
            'source' => 'human',
            'currency' => 'RON',
            'subtotal_cents' => $subtotal,
            'total_cents' => $subtotal,
            'valid_until' => now()->addDays(14)->toDateString(),
            'sent_at' => now(),
        ], $attrs));

        $position = 0;
        foreach ($lines as $line) {
            OfferItem::create([
                'workspace_id' => $wid,
                'offer_id' => $offer->id,
                'name' => $line['name'],
                'unit' => 'buc',
                'quantity' => $line['quantity'],
                'unit_price_cents' => $line['unit_price_cents'],
                'line_total_cents' => (int) round(((float) $line['quantity']) * (int) $line['unit_price_cents']),
                'position' => $position++,
                'added_by' => 'human',
            ]);
        }

        return $offer->fresh();
    }

    /** A link the test mints itself, so the route's own checking is what is under test. */
    private function signedUrl(Offer $offer, ?\DateTimeInterface $expiresAt = null): string
    {
        return URL::signedRoute(
            'offers.public',
            ['offer' => $offer->uuid],
            $expiresAt ?? now()->addDays(7),
            absolute: false,
        );
    }

    /** The same URL with no signature on it at all. */
    private function bareUrl(Offer $offer): string
    {
        return route('offers.public', ['offer' => $offer->uuid], false);
    }

    /** The link the application itself mints for the "Vezi ca client" button. */
    private function appMintedUrl(Offer $offer): ?string
    {
        $props = $this->actingAs($this->ctx['user'])
            ->get(route('client.offers.show', $offer->uuid))
            ->viewData('page')['props'];

        $url = $props['offer']['public_url'] ?? null;

        return is_string($url) ? $url : null;
    }

    /** The path and query of a URL, which is all a relative signature covers. */
    private function relative(string $url): string
    {
        $path = (string) parse_url($url, PHP_URL_PATH);
        $query = parse_url($url, PHP_URL_QUERY);

        return $path.($query === null ? '' : '?'.$query);
    }

    private function expiresOf(string $url): int
    {
        parse_str((string) parse_url($url, PHP_URL_QUERY), $params);

        $this->assertArrayHasKey('expires', $params, 'the public link carries no expiry at all');

        return (int) $params['expires'];
    }

    // ─── the signature ───────────────────────────────────────────────────

    public function test_an_unsigned_link_opens_nothing(): void
    {
        $offer = $this->sentOffer([], [['name' => 'Cremă hidratantă 50ml', 'quantity' => 1, 'unit_price_cents' => 24000]]);

        $response = $this->get($this->bareUrl($offer));

        $response->assertForbidden();
        $response->assertDontSee($offer->number);
    }

    public function test_a_changed_signature_opens_nothing(): void
    {
        $offer = $this->sentOffer([], [['name' => 'Cremă hidratantă 50ml', 'quantity' => 1, 'unit_price_cents' => 24000]]);

        $url = $this->signedUrl($offer);
        [$path, $query] = explode('?', $url, 2);
        parse_str($query, $params);

        $signature = (string) $params['signature'];
        $last = substr($signature, -1);
        $params['signature'] = substr($signature, 0, -1).($last === 'a' ? 'b' : 'a');

        $this->get($path.'?'.http_build_query($params))->assertForbidden();
    }

    public function test_a_link_whose_time_has_passed_opens_nothing(): void
    {
        $offer = $this->sentOffer([], [['name' => 'Cremă hidratantă 50ml', 'quantity' => 1, 'unit_price_cents' => 24000]]);

        // A link forwarded on in a WhatsApp thread is a working key to a firm's
        // prices for exactly as long as this allows.
        $this->get($this->signedUrl($offer, now()->subMinute()))->assertForbidden();
    }

    public function test_an_expiry_moved_forward_by_hand_opens_nothing(): void
    {
        $offer = $this->sentOffer([], [['name' => 'Cremă hidratantă 50ml', 'quantity' => 1, 'unit_price_cents' => 24000]]);

        $url = $this->signedUrl($offer, now()->subMinute());
        [$path, $query] = explode('?', $url, 2);
        parse_str($query, $params);
        $params['expires'] = (string) now()->addYear()->timestamp;

        // The expiry is inside what is signed, so editing it breaks the HMAC
        // rather than extending the link.
        $this->get($path.'?'.http_build_query($params))->assertForbidden();
    }

    public function test_one_offers_link_does_not_open_another(): void
    {
        $mine = $this->sentOffer([], [['name' => 'Cremă hidratantă 50ml', 'quantity' => 1, 'unit_price_cents' => 24000]]);
        $other = $this->sentOffer([], [['name' => 'CE AM QUOTAT ALTUI CLIENT', 'quantity' => 1, 'unit_price_cents' => 999000]]);

        // The customer who holds a valid link to their own offer swaps the uuid
        // for one they saw somewhere else. The uuid is inside the signed path,
        // so the HMAC no longer matches.
        $swapped = str_replace($mine->uuid, $other->uuid, $this->signedUrl($mine));

        $response = $this->get($swapped);

        $response->assertForbidden();
        $response->assertDontSee('CE AM QUOTAT ALTUI CLIENT');
        $response->assertDontSee($other->number);
    }

    public function test_another_firms_offer_is_no_more_reachable_than_any_other(): void
    {
        $intruder = $this->createWorkspaceContext();
        $theirs = $this->sentOffer(
            [],
            [['name' => 'PREȚUL LOR DE LISTĂ', 'quantity' => 1, 'unit_price_cents' => 777000]],
            (int) $intruder['workspace']->id,
        );

        $mine = $this->sentOffer([], [['name' => 'Cremă hidratantă 50ml', 'quantity' => 1, 'unit_price_cents' => 24000]]);

        $this->get($this->bareUrl($theirs))->assertForbidden();
        $this->get(str_replace($mine->uuid, $theirs->uuid, $this->signedUrl($mine)))->assertForbidden();

        // Signed in as the other firm changes nothing: this route has no session
        // and no notion of who is asking.
        $this->actingAs($this->ctx['user'])->get($this->bareUrl($theirs))->assertForbidden();
    }

    // ─── what may be published at all ────────────────────────────────────

    public function test_a_draft_has_no_public_page_and_no_link(): void
    {
        $draft = $this->sentOffer(['status' => 'draft', 'sent_at' => null], [
            ['name' => 'PREȚ PE CARE ÎL MAI SCHIMB', 'quantity' => 1, 'unit_price_cents' => 24000],
        ]);

        // Even holding a perfectly valid signature for it.
        $response = $this->get($this->signedUrl($draft));

        $response->assertNotFound();
        $response->assertDontSee('PREȚ PE CARE ÎL MAI SCHIMB');
        $response->assertDontSee($draft->number);

        // And the button that would produce the link is not drawn.
        $this->assertNull($this->appMintedUrl($draft), 'a draft was handed a "Vezi ca client" link');
    }

    public function test_an_offer_the_firm_threw_away_stops_answering(): void
    {
        $offer = $this->sentOffer([], [['name' => 'Cremă hidratantă 50ml', 'quantity' => 1, 'unit_price_cents' => 24000]]);
        $url = $this->signedUrl($offer);

        $this->get($url)->assertOk();

        $offer->delete();

        // The link was already out. Deleting the offer has to close it.
        $this->get($url)->assertNotFound();
    }

    public function test_the_link_survives_the_customers_own_decision(): void
    {
        $offer = $this->sentOffer([], [['name' => 'Cremă hidratantă 50ml', 'quantity' => 1, 'unit_price_cents' => 24000]]);

        $offer->forceFill(['status' => 'accepted', 'decision' => 'accepted', 'decided_at' => now()])->save();

        // The customer who said yes keeps the right to re-open the quote they
        // agreed to. A link that dies the moment the firm records the answer is
        // a document taken back from the person it was addressed to.
        $this->get($this->signedUrl($offer))->assertOk()->assertSee($offer->number);
        $this->assertNotNull($this->appMintedUrl($offer->fresh()));
    }

    // ─── how long the link lives ─────────────────────────────────────────

    public function test_the_link_dies_when_the_offer_stops_being_valid(): void
    {
        $offer = $this->sentOffer(['valid_until' => now()->addDays(3)->toDateString()]);

        $expires = $this->expiresOf((string) $this->appMintedUrl($offer));

        $this->assertGreaterThan(now()->addDays(2)->timestamp, $expires);
        $this->assertLessThanOrEqual(
            now()->addDays(4)->timestamp,
            $expires,
            'the link outlives the offer printed on it',
        );
    }

    public function test_a_long_validity_is_still_capped(): void
    {
        // A firm may set validity_days as high as 365. The page has no login
        // behind it, so the link's life is the window in which a forwarded
        // message is still a working key to a price list.
        $offer = $this->sentOffer(['valid_until' => now()->addDays(365)->toDateString()]);

        $expires = $this->expiresOf((string) $this->appMintedUrl($offer));

        $this->assertLessThanOrEqual(
            now()->addDays(30)->timestamp + 60,
            $expires,
            'a year-long quote minted a year-long public link',
        );
        $this->assertGreaterThan(now()->addDays(28)->timestamp, $expires);
    }

    public function test_an_offer_with_no_validity_date_is_capped_too(): void
    {
        $offer = $this->sentOffer(['valid_until' => null]);

        $expires = $this->expiresOf((string) $this->appMintedUrl($offer));

        $this->assertLessThanOrEqual(now()->addDays(30)->timestamp + 60, $expires);
        $this->assertGreaterThan(now()->addDays(28)->timestamp, $expires);
    }

    public function test_an_offer_that_has_already_lapsed_gets_no_link_at_all(): void
    {
        $offer = $this->sentOffer(['valid_until' => now()->subDay()->toDateString()]);

        // Better no button than a button producing a link that is dead the
        // instant it is copied.
        $this->assertNull($this->appMintedUrl($offer));
    }

    // ─── what the page contains, and everything it must not ──────────────

    public function test_the_page_carries_this_offer_and_nothing_else(): void
    {
        ClientProfile::create([
            'client_id' => $this->ctx['client']->id,
            'legal_name' => 'Cabinet Dentar Zâmbet SRL',
            'cui' => 'RO12345678',
            // On the same row, never printed on the document: this is the
            // seller's fiscal identity on a commercial paper, not their profile.
            'website' => 'https://nu-apare-pe-oferta.ro',
            'contact_person_name' => 'PERSOANA DE CONTACT INTERNĂ',
            'short_description' => 'DESCRIEREA INTERNĂ A FIRMEI',
        ]);

        $buyer = Contact::factory()->create([
            'workspace_id' => $this->workspaceId(),
            'first_name' => 'Maria',
            'last_name' => 'Ionescu',
        ]);

        // A second customer of the same firm, with nothing to do with this
        // quote.
        Contact::factory()->create([
            'workspace_id' => $this->workspaceId(),
            'first_name' => 'Vasile',
            'last_name' => 'POPESCUALTUL',
        ]);

        $offer = $this->sentOffer([
            'contact_id' => $buyer->id,
            'notes' => 'Prețurile includ deplasarea în Brașov.',
            'ai_reason' => 'MOTIVUL INTERN AL AGENTULUI',
            'ai_json' => ['ciorna' => 'CE A ÎNȚELES AGENTUL DIN MESAJ'],
            'message_body' => 'MESAJUL DE ÎNSOȚIRE INTERN',
        ], [
            ['name' => 'Cremă hidratantă 50ml', 'quantity' => 5, 'unit_price_cents' => 24690],
        ]);

        // Another offer of the same firm, to the same customer.
        $otherOffer = $this->sentOffer(['contact_id' => $buyer->id], [
            ['name' => 'CE AM QUOTAT SĂPTĂMÂNA TRECUTĂ', 'quantity' => 1, 'unit_price_cents' => 500000],
        ]);

        OfferDraftAttempt::create([
            'workspace_id' => $this->workspaceId(),
            'conversation_id' => 4242,
            'message_id' => 9191,
            'status' => OfferDraftAttempt::STATUS_FAILED,
            'reason' => OfferDraftAttempt::REASON_NO_MATCH,
            'tokens' => 731,
        ]);

        CatalogItem::create([
            'workspace_id' => $this->workspaceId(),
            'type' => 'product',
            'name' => 'PRODUS DIN CATALOG NEOFERTAT',
            'unit' => 'buc',
            'price_cents' => 111100,
        ]);

        $response = $this->get($this->relative((string) $this->appMintedUrl($offer)));
        $response->assertOk();
        $html = $response->getContent();

        // ── the document itself ──────────────────────────────────────────
        $this->assertStringContainsString($offer->number, $html);
        $this->assertStringContainsString('Cremă hidratantă 50ml', $html);
        // 5 x 246,90 = 1.234,50, written the way a Romanian reads it.
        $this->assertStringContainsString('1.234,50', $html);
        $this->assertStringContainsString('Cabinet Dentar Zâmbet SRL', $html);
        $this->assertStringContainsString('Ionescu', $html);
        // Printed on the PDF, so printed here: it is the seller's own wording
        // for the customer, and the two documents must not disagree.
        $this->assertStringContainsString('deplasarea în Brașov', $html);

        // ── another offer, another customer ──────────────────────────────
        $this->assertStringNotContainsString('CE AM QUOTAT SĂPTĂMÂNA TRECUTĂ', $html);
        $this->assertStringNotContainsString($otherOffer->number, $html);
        $this->assertStringNotContainsString($otherOffer->uuid, $html);
        $this->assertStringNotContainsString('POPESCUALTUL', $html);

        // ── the firm's own inside ────────────────────────────────────────
        $this->assertStringNotContainsString('MOTIVUL INTERN AL AGENTULUI', $html);
        $this->assertStringNotContainsString('CE A ÎNȚELES AGENTUL DIN MESAJ', $html);
        $this->assertStringNotContainsString('MESAJUL DE ÎNSOȚIRE INTERN', $html);
        $this->assertStringNotContainsString(OfferDraftAttempt::REASON_NO_MATCH, $html);
        $this->assertStringNotContainsString('PRODUS DIN CATALOG NEOFERTAT', $html);
        $this->assertStringNotContainsString('111100', $html);

        // ── the seller, beyond what is on the document ───────────────────
        $this->assertStringNotContainsString('nu-apare-pe-oferta.ro', $html);
        $this->assertStringNotContainsString('PERSOANA DE CONTACT INTERNĂ', $html);
        $this->assertStringNotContainsString('DESCRIEREA INTERNĂ A FIRMEI', $html);
        $this->assertStringNotContainsString('NUME INTERN DE WORKSPACE', $html);
        $this->assertStringNotContainsString('NUMELE INTERN AL CLIENTULUI', $html);
    }

    public function test_the_page_is_not_an_inertia_page(): void
    {
        $offer = $this->sentOffer([], [['name' => 'Cremă hidratantă 50ml', 'quantity' => 1, 'unit_price_cents' => 24000]]);

        $response = $this->get($this->signedUrl($offer));
        $response->assertOk();
        $html = (string) $response->getContent();

        // HandleInertiaRequests shares the whole User model with every Inertia
        // response — C1 in the debt register. An Inertia page here would ship
        // the signed-in seller's row, their workspaces, their permissions and a
        // CSRF token into a document a stranger is holding. This assertion is
        // what keeps the page a plain server-rendered document.
        $this->assertStringNotContainsString('data-page', $html, 'the public offer is being served as an Inertia page');
        $this->assertStringNotContainsString('csrf', strtolower($html));
        $this->assertStringNotContainsString('intern@nu-trebuie-sa-apara.ro', $html);
        $this->assertStringNotContainsString('Angajatul Intern', $html);

        // No session is started for it either, so no cookie goes out that could
        // be replayed against the application.
        $this->assertSame(
            [],
            $response->headers->getCookies(),
            'the public offer route set a cookie on a guest',
        );
    }

    public function test_the_page_refuses_to_be_indexed_framed_or_cached(): void
    {
        $offer = $this->sentOffer([], [['name' => 'Cremă hidratantă 50ml', 'quantity' => 1, 'unit_price_cents' => 24000]]);

        $response = $this->get($this->signedUrl($offer));
        $response->assertOk();

        // A quote sitting in Google's index is the same leak as an unsigned URL,
        // reached from a different direction.
        $this->assertStringContainsString('noindex', (string) $response->headers->get('X-Robots-Tag'));

        $this->assertSame('DENY', $response->headers->get('X-Frame-Options'));
        $this->assertStringContainsString('nosniff', (string) $response->headers->get('X-Content-Type-Options'));

        // Tenant data on a URL anyone holding the link can fetch: no shared
        // cache, no proxy copy, no back-button restore after it has expired.
        $cacheControl = (string) $response->headers->get('Cache-Control');
        $this->assertStringContainsString('no-store', $cacheControl);
        $this->assertStringNotContainsString('public', $cacheControl);

        // The signature lives in the query string and must not walk out in a
        // Referer header.
        $this->assertStringContainsString('no-referrer', (string) $response->headers->get('Referrer-Policy'));
    }

    public function test_the_page_serves_its_own_content_security_policy(): void
    {
        $offer = $this->sentOffer([], [['name' => 'Cremă hidratantă 50ml', 'quantity' => 1, 'unit_price_cents' => 24000]]);

        $response = $this->get($this->signedUrl($offer));
        $response->assertOk();

        $csp = (string) $response->headers->get('Content-Security-Policy');
        $this->assertNotSame('', $csp, 'the public offer page ships no Content-Security-Policy');

        // The application's own policy permits inline script, because its own
        // pages need it. This page is a document: it must not inherit that.
        $scriptSrc = $this->directive($csp, 'script-src') ?? $this->directive($csp, 'default-src');
        $this->assertNotNull($scriptSrc, 'the policy constrains neither script-src nor default-src');
        $this->assertStringNotContainsString(
            "'unsafe-inline'",
            $scriptSrc,
            'the public offer page allows inline script',
        );

        $this->assertSame("'none'", $this->directive($csp, 'frame-ancestors'));
    }

    /** One directive out of a CSP header, or null when it is not there. */
    private function directive(string $csp, string $name): ?string
    {
        foreach (explode(';', $csp) as $part) {
            $bits = preg_split('/\s+/', trim($part)) ?: [];

            if (($bits[0] ?? '') === $name) {
                return implode(' ', array_slice($bits, 1));
            }
        }

        return null;
    }

    // ─── nothing to enumerate, and guessing is bounded ───────────────────

    public function test_there_is_no_numeric_id_in_the_url(): void
    {
        $offer = $this->sentOffer([], [['name' => 'Cremă hidratantă 50ml', 'quantity' => 1, 'unit_price_cents' => 24000]]);

        $path = (string) parse_url((string) $this->appMintedUrl($offer), PHP_URL_PATH);

        // A sequence in the URL is a listing endpoint nobody wrote on purpose.
        $this->assertStringContainsString($offer->uuid, $path);
        $this->assertStringNotContainsString('/'.$offer->id, $path);
    }

    public function test_a_uuid_that_belongs_to_nothing_is_answered_the_same_way_as_a_draft(): void
    {
        $stranger = (string) Str::uuid();

        $url = URL::signedRoute('offers.public', ['offer' => $stranger], now()->addDay(), absolute: false);

        // Same answer for "there is no such offer" and "that one is a draft":
        // a link that no longer opens anything must not confirm which of the two
        // it is.
        $this->get($url)->assertNotFound();
    }

    public function test_the_route_is_rate_limited(): void
    {
        $offer = $this->sentOffer([], [['name' => 'Cremă hidratantă 50ml', 'quantity' => 1, 'unit_price_cents' => 24000]]);

        $url = $this->bareUrl($offer);
        $refused = false;

        // Guessing a uuid is hopeless, but "hopeless" is not a control. The
        // throttle stands before the signature check so a flood is refused
        // before any HMAC is computed.
        for ($i = 0; $i < 60; $i++) {
            if ($this->get($url)->getStatusCode() === 429) {
                $refused = true;
                break;
            }
        }

        $this->assertTrue($refused, 'the public offer route accepted 60 requests a minute from one address');
    }
}
