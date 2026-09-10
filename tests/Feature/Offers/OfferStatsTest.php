<?php

namespace Tests\Feature\Offers;

use App\Modules\Catalog\Models\CatalogItem;
use App\Modules\Catalog\Models\CatalogItemLink;
use App\Modules\Offers\Models\Offer;
use App\Modules\Offers\Models\OfferItem;
use App\Modules\Offers\Services\OfferStats;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * What the catalogue screens say about how a product actually sells.
 *
 * THE RULE THIS FILE EXISTS TO HOLD. Every ratio on these screens has a
 * denominator that is zero for the first weeks of a firm's life. A tile reading
 * "acceptat 0%" on day three does not report a slow start — it reports a
 * business that is failing, before anything has happened, and the person
 * reading it cannot tell the two apart. So under the sample threshold every
 * ratio is NULL and the sample is flagged short: not a zero, not a dash, not
 * "0%". Those assertions are the point of the file and must never be relaxed to
 * make an implementation pass.
 *
 * THE SECOND RULE is that a firm with four thousand offers costs the same
 * number of queries as one with four. The "ÎN OFERTE" column is one grouped
 * query for a whole page of rows, and the last test here is what proves it.
 *
 * THE CONTRACT, since two screens and one service read these numbers:
 *
 *   Catalog/Index   items.data[*].offer_count   int — offers this item was put
 *                                                     in front of a customer on;
 *                                                     0 renders "niciodată"
 *
 *   Catalog/Show    sells    array — OfferStats::forItem(); sample_short says
 *                                    the screen must draw nothing at all
 *                   insight  array|null — OfferStats::crossSellInsight()
 *                   bundles  list — "APARE ÎN ANSAMBLURILE"
 *
 * A DRAFT IS NOT A PROPOSAL and AN OPEN OFFER IS NOT A REFUSAL: the list column
 * and the panel's first figure use one rule, so clicking a row never changes
 * the number, and every rate is divided by the offers a customer actually
 * answered.
 */
class OfferStatsTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<string, mixed> */
    private array $ctx;

    /** Offer numbers are unique per workspace; this is what keeps them so. */
    private int $seq = 0;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->ctx = $this->createWorkspaceContext();
    }

    // ─── fixtures ────────────────────────────────────────────────────────

    private function workspaceId(): int
    {
        return (int) $this->ctx['workspace']->id;
    }

    /** @param  array<string, mixed>  $attrs */
    private function item(array $attrs = [], ?int $workspaceId = null): CatalogItem
    {
        return CatalogItem::create(array_merge([
            'workspace_id' => $workspaceId ?? $this->workspaceId(),
            'type' => 'product',
            'name' => 'Cremă hidratantă 50ml',
            'unit' => 'buc',
            'price_cents' => 24000,
            'is_active' => true,
        ], $attrs));
    }

    /**
     * One offer with its lines, written straight to the tables.
     *
     * Not through the controller: these tests need twenty offers in a decided
     * state to get past the gate, and every property under test is read off the
     * columns rather than off the editor.
     *
     * @param  array<string, mixed>  $attrs
     * @param  array<int, array<string, mixed>>  $lines
     */
    private function offerWith(array $attrs = [], array $lines = [], ?int $workspaceId = null): Offer
    {
        $wid = $workspaceId ?? $this->workspaceId();

        $subtotal = 0;
        foreach ($lines as $line) {
            $item = $line['item'] ?? null;
            $price = (int) ($line['unit_price_cents'] ?? ($item?->price_cents ?? 0));
            $subtotal += (int) round(((float) ($line['quantity'] ?? 1)) * $price);
        }

        $offer = Offer::create(array_merge([
            'workspace_id' => $wid,
            'number' => 'OF-'.now()->format('Y').'-'.str_pad((string) ++$this->seq, 5, '0', STR_PAD_LEFT),
            'status' => 'sent',
            'source' => 'human',
            'currency' => 'RON',
            'subtotal_cents' => $subtotal,
            'total_cents' => $subtotal,
            'sent_at' => now(),
        ], $attrs));

        $this->addLines($offer, $lines, $wid);

        return $offer->fresh();
    }

    /** @param  array<int, array<string, mixed>>  $lines */
    private function addLines(Offer $offer, array $lines, int $workspaceId): void
    {
        $position = (int) OfferItem::where('offer_id', $offer->id)->count();

        foreach ($lines as $line) {
            $item = $line['item'] ?? null;
            $quantity = (float) ($line['quantity'] ?? 1);
            $price = (int) ($line['unit_price_cents'] ?? ($item?->price_cents ?? 0));

            OfferItem::create([
                // Overridable on purpose: one tenancy case below plants a line
                // whose own workspace_id disagrees with its offer's, which is
                // the exact shape a half-filtered join lets through.
                'workspace_id' => (int) ($line['workspace_id'] ?? $workspaceId),
                'offer_id' => $offer->id,
                'catalog_item_id' => $item?->id,
                'name' => $item?->name ?? (string) ($line['name'] ?? 'Linie liberă'),
                'unit' => $item?->unit ?? 'buc',
                'quantity' => $quantity,
                'unit_price_cents' => $price,
                'line_total_cents' => (int) round($quantity * $price),
                'position' => $position++,
                'added_by' => 'human',
            ]);
        }
    }

    /**
     * An offer the customer answered — the only kind either threshold counts.
     *
     * @param  array<int, array<string, mixed>>  $lines
     * @param  array<string, mixed>  $attrs
     */
    private function decided(string $decision, array $lines, array $attrs = [], ?int $workspaceId = null): Offer
    {
        return $this->offerWith(array_merge([
            'status' => $decision,
            'decision' => $decision,
            'decided_at' => now(),
        ], $attrs), $lines, $workspaceId);
    }

    /**
     * Answered offers carrying an item, enough of them that a test about one
     * figure is not silently a test about the gate.
     *
     * @param  array<int, array<string, mixed>>  $extraLines
     */
    private function fillTheSample(CatalogItem $item, int $already = 0, array $extraLines = []): void
    {
        for ($i = $already; $i < OfferStats::MIN_DECIDED_OFFERS; $i++) {
            $this->decided('refused', array_merge([['item' => $item, 'quantity' => 1]], $extraLines));
        }
    }

    /** @return array<string, mixed> */
    private function listProps(): array
    {
        return $this->actingAs($this->ctx['user'])
            ->get(route('client.catalog.index'))
            ->viewData('page')['props'];
    }

    /** @return array<string, mixed> */
    private function itemProps(CatalogItem $item): array
    {
        return $this->actingAs($this->ctx['user'])
            ->get(route('client.catalog.show', $item->uuid))
            ->viewData('page')['props'];
    }

    /**
     * The raw figures behind the panel.
     *
     * The screen is handed null while the sample is short — that is the gate,
     * and it is asserted on its own — so the counts underneath are read here,
     * from the service the screen reads them from.
     *
     * @return array<string, mixed>
     */
    private function sells(CatalogItem $item): array
    {
        return app(OfferStats::class)->forItem($this->workspaceId(), (int) $item->id);
    }

    /** The "ÎN OFERTE" cell for one item, off the list screen. */
    private function offerCountOnList(CatalogItem $item): int
    {
        foreach ($this->listProps()['items']['data'] as $row) {
            if ((int) $row['id'] === (int) $item->id) {
                $this->assertArrayHasKey('offer_count', $row, 'the list row carries no "ÎN OFERTE" figure');

                return (int) $row['offer_count'];
            }
        }

        $this->fail('the item was missing from its own firm\'s catalogue list');
    }

    // ─── the "ÎN OFERTE" column ──────────────────────────────────────────

    public function test_the_list_says_how_many_offers_an_item_has_appeared_on(): void
    {
        $quoted = $this->item(['name' => 'Cremă hidratantă 50ml']);
        $never = $this->item(['name' => 'Ceva ce nu a cerut nimeni']);

        $this->offerWith([], [['item' => $quoted, 'quantity' => 1]]);
        $this->offerWith([], [['item' => $quoted, 'quantity' => 2]]);

        // Twice on ONE offer is one offer. The column counts offers, not lines:
        // a firm that quoted two lengths of the same cable on one document has
        // quoted it once.
        $this->offerWith([], [
            ['item' => $quoted, 'quantity' => 1],
            ['item' => $quoted, 'quantity' => 3],
        ]);

        $this->assertSame(3, $this->offerCountOnList($quoted));

        // Zero, and never a missing key: "niciodată" is a word the screen
        // chooses for a number it was given, not a gap it has to guess at.
        $this->assertSame(0, $this->offerCountOnList($never));
    }

    public function test_a_draft_is_not_yet_an_offer_the_item_has_appeared_on(): void
    {
        $item = $this->item();

        $this->offerWith(['status' => 'draft', 'sent_at' => null], [['item' => $item, 'quantity' => 1]]);
        $this->offerWith([], [['item' => $item, 'quantity' => 1]]);

        // A draft has been shown to nobody. Counting it would put "în 2 oferte"
        // on the list beside "propus în 1 ofertă" on the item page, and a firm
        // that catches two numbers for one thing is right to trust neither.
        $this->assertSame(1, $this->offerCountOnList($item));

        // The panel itself is not drawn on a sample of one, so the same figure
        // is read off the service: the column and the panel must never be able
        // to disagree about what "proposed" means.
        $this->assertSame(1, (int) $this->sells($item)['proposed_count']);
    }

    public function test_an_offer_thrown_away_stops_counting(): void
    {
        $item = $this->item();

        $this->offerWith([], [['item' => $item, 'quantity' => 1]]);
        $this->offerWith([], [['item' => $item, 'quantity' => 1]])->delete();

        // These are query-builder statements, so nothing applies the SoftDeletes
        // scope for them. An offer in the bin is not a proposal the firm made.
        $this->assertSame(1, $this->offerCountOnList($item));
    }

    // ─── tenancy: both ends of the join say workspace_id out loud ────────

    public function test_the_column_counts_only_this_firms_offers(): void
    {
        $item = $this->item();
        $intruder = $this->createWorkspaceContext();

        $this->offerWith([], [['item' => $item, 'quantity' => 1]]);

        // The shape a half-filtered join lets through: another firm's offer,
        // with a line of its own pointing at OUR catalogue row. Nothing in the
        // product can create this — the editor refuses a foreign
        // catalog_item_id — but an aggregate that reaches the tenant through a
        // relation would count it, and the firm would see a product it has
        // never quoted reported as quoted twice.
        $this->offerWith([], [['item' => $item, 'quantity' => 1]], (int) $intruder['workspace']->id);

        $this->assertSame(1, $this->offerCountOnList($item));
    }

    public function test_a_line_whose_offer_belongs_to_another_firm_is_not_counted(): void
    {
        $item = $this->item();
        $intruder = $this->createWorkspaceContext();

        // The mirror image: the LINE claims our workspace, the offer it hangs
        // off belongs to theirs. Filtering offer_items alone would count it.
        // Both tables carry workspace_id precisely so both can be asserted.
        $this->offerWith(
            [],
            [['item' => $item, 'quantity' => 1, 'workspace_id' => $this->workspaceId()]],
            (int) $intruder['workspace']->id,
        );

        $this->assertSame(0, $this->offerCountOnList($item));

        // And their catalogue is untouched by our row either way.
        $theirs = $this->actingAs($intruder['user'])
            ->get(route('client.catalog.index'))
            ->viewData('page')['props'];
        $this->assertSame([], $theirs['items']['data']);
    }

    // ─── the gate in front of every ratio ────────────────────────────────

    public function test_the_threshold_is_at_least_twenty_answered_offers(): void
    {
        // Fourteen offers is not a correlation, it is a coincidence with a
        // percentage sign after it — and the cross-sell panel splits whatever
        // sample it is given in two, so fourteen is seven a side. The number is
        // pinned here so that lowering it has to be a deliberate edit to a test
        // that says why, rather than a quiet change in a service nobody reads.
        $this->assertGreaterThanOrEqual(
            20,
            OfferStats::MIN_DECIDED_OFFERS,
            'the screens are drawing conclusions from a sample too short to have any',
        );
    }

    public function test_below_the_threshold_no_figure_is_divided_by_anything(): void
    {
        $item = $this->item();

        // One short of the gate, and every one of them a clean, decided offer.
        for ($i = 0; $i < OfferStats::MIN_DECIDED_OFFERS - 1; $i++) {
            $this->decided($i === 0 ? 'accepted' : 'refused', [
                ['item' => $item, 'quantity' => 1, 'unit_price_cents' => 12000],
            ]);
        }

        $props = $this->itemProps($item);

        // The whole point of the gate: the screen is handed NOTHING. Not a
        // zero, not a dash, not "0%". "Acceptat 5%" off nineteen offers is a
        // number about the week, not about the business, and a firm cannot tell
        // the two apart from a tile.
        $this->assertNull($props['sells'], 'the panel was drawn on a short sample');
        $this->assertNull($props['insight'], 'the agent spoke on a short sample');

        // And underneath, the service withholds every ratio rather than the
        // screen having to remember to. The counts stay — a count is a fact,
        // not a statistic — which is what lets the panel fill in the moment the
        // twentieth answer arrives.
        $sells = $this->sells($item);
        $this->assertTrue((bool) $sells['sample_short']);
        $this->assertNull($sells['accepted_rate'], 'an acceptance rate was computed on a short sample');
        $this->assertNull($sells['average_discount_percent'], 'a discount average was computed on a short sample');
        $this->assertSame(OfferStats::MIN_DECIDED_OFFERS - 1, (int) $sells['sample_size']);
        $this->assertSame(1, (int) $sells['accepted_count']);
    }

    public function test_at_the_threshold_the_figures_appear(): void
    {
        $item = $this->item();
        $this->fillTheSample($item);

        $sells = $this->itemProps($item)['sells'];

        $this->assertFalse((bool) $sells['sample_short'], 'the panel is still shut at the threshold it declares');
        $this->assertSame(OfferStats::MIN_DECIDED_OFFERS, (int) $sells['sample_size']);
        $this->assertNotNull($sells['accepted_rate']);
    }

    // ─── the "CUM SE VINDE" figures ──────────────────────────────────────

    public function test_the_acceptance_rate_is_out_of_the_offers_that_were_answered(): void
    {
        $item = $this->item();

        $decided = max(OfferStats::MIN_DECIDED_OFFERS, 20);
        $accepted = 8;

        for ($i = 0; $i < $accepted; $i++) {
            $this->decided('accepted', [['item' => $item, 'quantity' => 1]]);
        }
        for ($i = 0; $i < $decided - $accepted; $i++) {
            $this->decided('refused', [['item' => $item, 'quantity' => 1]]);
        }

        // Two still waiting for an answer, one that lapsed, and one draft.
        $this->offerWith([], [['item' => $item, 'quantity' => 1]]);
        $this->offerWith([], [['item' => $item, 'quantity' => 1]]);
        $this->offerWith(['status' => 'expired'], [['item' => $item, 'quantity' => 1]]);
        $this->offerWith(['status' => 'draft', 'sent_at' => null], [['item' => $item, 'quantity' => 1]]);

        $sells = $this->itemProps($item)['sells'];

        // Proposed counts everything that left the building, the lapsed one
        // included — it did go to a customer.
        $this->assertSame($decided + 3, (int) $sells['proposed_count'], 'a draft was counted as something a customer saw');
        $this->assertSame($decided, (int) $sells['sample_size']);
        $this->assertSame($accepted, (int) $sells['accepted_count']);

        // Divided by the answers, never by everything sent. Counting the two
        // still open as refusals would report them as losses on the day they
        // went out.
        $this->assertSame((int) round($accepted * 100 / $decided), (int) $sells['accepted_rate']);
    }

    public function test_sold_this_month_counts_only_offers_the_customer_accepted(): void
    {
        $item = $this->item(['price_cents' => 24000]);

        // Two yeses this month: 2 and 3 units at 240,00 lei.
        $this->decided('accepted', [['item' => $item, 'quantity' => 2]]);
        $this->decided('accepted', [['item' => $item, 'quantity' => 3]]);

        // A no, this month. Nothing was sold.
        $this->decided('refused', [['item' => $item, 'quantity' => 7]]);

        // A yes, but last month — dated to the last day of it, so this holds on
        // the 31st as well as on the 1st.
        $this->decided('accepted', [['item' => $item, 'quantity' => 10]], [
            'decided_at' => now()->startOfMonth()->subDay(),
        ]);

        // Sent, and still open. A quote is not a sale.
        $this->offerWith([], [['item' => $item, 'quantity' => 9]]);

        // Another product's sale, on an accepted offer of its own.
        $other = $this->item(['name' => 'Altceva', 'price_cents' => 5000]);
        $this->decided('accepted', [['item' => $other, 'quantity' => 100]]);

        $this->fillTheSample($item, already: 4);

        $sells = $this->itemProps($item)['sells'];

        $this->assertEqualsWithDelta(5.0, (float) $sells['units_this_month'], 0.0001);
        $this->assertSame(120000, (int) $sells['revenue_cents_this_month']);
    }

    public function test_the_month_is_not_another_firms_month(): void
    {
        $item = $this->item(['price_cents' => 24000]);
        $intruder = $this->createWorkspaceContext();

        $this->fillTheSample($item);

        for ($i = 0; $i < 12; $i++) {
            $this->decided(
                'accepted',
                [['item' => $item, 'quantity' => 50]],
                [],
                (int) $intruder['workspace']->id,
            );
        }

        $sells = $this->itemProps($item)['sells'];

        $this->assertSame(OfferStats::MIN_DECIDED_OFFERS, (int) $sells['sample_size']);
        $this->assertSame(0, (int) $sells['accepted_count']);
        $this->assertSame(0, (int) $sells['revenue_cents_this_month']);
        $this->assertEqualsWithDelta(0.0, (float) $sells['units_this_month'], 0.0001);
    }

    public function test_the_average_discount_is_absent_rather_than_zero_when_nothing_was_discounted(): void
    {
        $item = $this->item(['price_cents' => 24000]);

        // A full sample, every line sold at the catalogue's own price.
        for ($i = 0; $i < OfferStats::MIN_DECIDED_OFFERS; $i++) {
            $this->decided('accepted', [['item' => $item, 'quantity' => 1, 'unit_price_cents' => 24000]]);
        }

        $sells = $this->itemProps($item)['sells'];

        // Null, not 0. "Discount mediu acordat: 0%" reads as a measurement — a
        // firm that was asked and held the line. Nothing was measured: nobody
        // ever asked, and the panel must not put words in its mouth.
        $this->assertFalse((bool) $sells['sample_short'], 'the sample was short, so this proves nothing about the discount');
        $this->assertNull(
            $sells['average_discount_percent'],
            'a firm that has never discounted was reported as averaging a 0% discount',
        );
    }

    public function test_the_average_discount_is_measured_across_the_lines_that_were_sold(): void
    {
        $item = $this->item(['price_cents' => 100000]);

        // Half the accepted lines at 20% off, half at the list price. The
        // average is 10% — the full-price sales are part of what the firm
        // averages, because the tile answers "how much do I give away when I
        // sell this", and a sale at list price is a sale where it gave nothing.
        // Averaging only the discounted lines would report 20% and tell the firm
        // it discounts twice as hard as it does.
        $half = (int) ceil(OfferStats::MIN_DECIDED_OFFERS / 2);

        for ($i = 0; $i < $half; $i++) {
            $this->decided('accepted', [['item' => $item, 'quantity' => 1, 'unit_price_cents' => 80000]]);
        }
        for ($i = 0; $i < OfferStats::MIN_DECIDED_OFFERS - $half; $i++) {
            $this->decided('accepted', [['item' => $item, 'quantity' => 1, 'unit_price_cents' => 100000]]);
        }

        $sells = $this->itemProps($item)['sells'];

        $expected = 20.0 * $half / OfferStats::MIN_DECIDED_OFFERS;

        $this->assertNotNull($sells['average_discount_percent']);
        $this->assertEqualsWithDelta($expected, (float) $sells['average_discount_percent'], 0.05);
    }

    public function test_a_line_sold_above_the_list_price_is_not_a_negative_discount(): void
    {
        $item = $this->item(['price_cents' => 100000]);

        // One line sold at 20% off, the rest sold ABOVE today's catalogue price
        // — which is what every historic line looks like the day after a firm
        // raises its prices. Those are not discounts of minus twenty percent
        // granted to a customer; letting them subtract would net two unrelated
        // stories into one meaningless average, and could even produce a
        // negative "discount acordat".
        $this->decided('accepted', [['item' => $item, 'quantity' => 1, 'unit_price_cents' => 80000]]);

        for ($i = 1; $i < OfferStats::MIN_DECIDED_OFFERS; $i++) {
            $this->decided('accepted', [['item' => $item, 'quantity' => 1, 'unit_price_cents' => 130000]]);
        }

        $average = $this->itemProps($item)['sells']['average_discount_percent'];

        $this->assertNotNull($average);
        $this->assertGreaterThan(0.0, (float) $average, 'a price rise was reported as a discount going the other way');
        $this->assertEqualsWithDelta(20.0 / OfferStats::MIN_DECIDED_OFFERS, (float) $average, 0.05);
    }

    // ─── "APARE ÎN ANSAMBLURILE" ─────────────────────────────────────────

    public function test_the_item_page_lists_only_this_firms_bundles(): void
    {
        $item = $this->item(['name' => 'Cremă hidratantă 50ml']);

        $bundle = $this->item(['type' => 'bundle', 'name' => 'Pachet îngrijire completă']);
        $this->bundleComponent($bundle, $item, $this->workspaceId());

        $intruder = $this->createWorkspaceContext();
        $theirBundle = $this->item(
            ['type' => 'bundle', 'name' => 'PACHETUL LOR SECRET'],
            (int) $intruder['workspace']->id,
        );
        $this->bundleComponent($theirBundle, $item, (int) $intruder['workspace']->id);

        $props = $this->itemProps($item);

        $this->assertArrayHasKey('bundles', $props);
        $this->assertCount(1, $props['bundles']);
        $this->assertSame($bundle->uuid, $props['bundles'][0]['uuid']);

        $this->assertStringNotContainsString('PACHETUL LOR SECRET', (string) json_encode($props));
    }

    private function bundleComponent(CatalogItem $bundle, CatalogItem $part, int $workspaceId): void
    {
        CatalogItemLink::create([
            'workspace_id' => $workspaceId,
            'catalog_item_id' => $bundle->id,
            'related_item_id' => $part->id,
            'kind' => CatalogItemLink::KIND_BUNDLE_COMPONENT,
            'quantity' => 1,
            'position' => 0,
        ]);
    }

    // ─── "OBSERVAȚIA AGENTULUI" ──────────────────────────────────────────

    public function test_the_observation_is_withheld_one_offer_short_of_the_threshold(): void
    {
        $item = $this->item(['name' => 'Cremă hidratantă 50ml']);
        $partner = $this->item(['name' => 'Ser vitamina C']);

        // One short, with a difference so large that any implementation willing
        // to speak at all would speak here.
        $this->buildCrossSellSample($item, $partner, total: OfferStats::MIN_DECIDED_OFFERS - 1);

        $props = $this->itemProps($item);

        $this->assertNull($props['insight'], 'the agent drew a conclusion from a sample it had declared too short');
        $this->assertNull($props['sells']);

        // And nothing of the observation leaks out around the gate, under any
        // key of the payload the screen is handed.
        $this->assertStringNotContainsString('Ser vitamina C', (string) json_encode($props['sells']));
        $this->assertStringNotContainsString('Ser vitamina C', (string) json_encode($props['insight']));
    }

    public function test_at_the_threshold_the_observation_compares_the_two_halves(): void
    {
        $item = $this->item(['name' => 'Cremă hidratantă 50ml']);
        $partner = $this->item(['name' => 'Ser vitamina C']);

        $this->buildCrossSellSample($item, $partner, total: 24);

        $insight = $this->itemProps($item)['insight'];

        $this->assertNotNull($insight, 'the sample reached the threshold and still produced nothing');
        $this->assertSame($partner->uuid, $insight['item']['uuid']);
        $this->assertSame(24, (int) $insight['sample_size']);

        // 9 of 12 with the serum, 3 of 12 without.
        $this->assertSame(12, (int) $insight['together_count']);
        $this->assertSame(12, (int) $insight['alone_count']);
        $this->assertSame(75, (int) $insight['accepted_rate_with']);
        $this->assertSame(25, (int) $insight['accepted_rate_without']);
        $this->assertSame(50, (int) $insight['difference']);
    }

    public function test_a_companion_with_no_one_to_compare_against_says_nothing(): void
    {
        $item = $this->item(['name' => 'Cremă hidratantă 50ml']);
        $partner = $this->item(['name' => 'Ser vitamina C']);

        // Well past the threshold, and the serum is on EVERY one of them. There
        // is no "fără" arm to compare the "cu" arm against, so there is no
        // observation — a rate quoted against nothing is not a finding.
        for ($i = 0; $i < OfferStats::MIN_DECIDED_OFFERS + 6; $i++) {
            $this->decided($i % 3 === 0 ? 'refused' : 'accepted', [
                ['item' => $item, 'quantity' => 1],
                ['item' => $partner, 'quantity' => 1],
            ]);
        }

        $this->assertNull($this->itemProps($item)['insight']);
    }

    public function test_the_companion_named_is_the_one_sold_alongside_most_often(): void
    {
        $item = $this->item(['name' => 'Cremă hidratantă 50ml']);
        $common = $this->item(['name' => 'Ser vitamina C']);
        $rare = $this->item(['name' => 'Mască de noapte']);

        // The serum: on twelve answered offers, half of them accepted.
        for ($i = 0; $i < 12; $i++) {
            $this->decided($i < 6 ? 'accepted' : 'refused', [
                ['item' => $item, 'quantity' => 1],
                ['item' => $common, 'quantity' => 1],
            ]);
        }

        // The mask: on five, every one of them accepted — a perfect record, and
        // by far the biggest-looking difference in the catalogue.
        for ($i = 0; $i < 5; $i++) {
            $this->decided('accepted', [
                ['item' => $item, 'quantity' => 1],
                ['item' => $rare, 'quantity' => 1],
            ]);
        }

        // Seven more with neither, so both companions have something to be
        // compared against.
        for ($i = 0; $i < 7; $i++) {
            $this->decided('refused', [['item' => $item, 'quantity' => 1]]);
        }

        $insight = $this->itemProps($item)['insight'];

        $this->assertNotNull($insight);

        // The mask is picked ONLY by an implementation that ranks candidates by
        // how good the difference looks. The largest of many differences is
        // large by construction — that is how this kind of panel manufactures an
        // insight out of any catalogue at all. Choosing by frequency is what
        // makes the observation a fact about the firm rather than about the
        // search.
        $this->assertSame(
            $common->uuid,
            $insight['item']['uuid'],
            'the observation named the best-looking companion instead of the most frequent one',
        );
    }

    public function test_an_observation_never_names_another_firms_product(): void
    {
        $item = $this->item(['name' => 'Cremă hidratantă 50ml']);
        $partner = $this->item(['name' => 'Ser vitamina C']);
        $intruder = $this->createWorkspaceContext();

        // Their whole sample, on our catalogue rows.
        for ($i = 0; $i < OfferStats::MIN_DECIDED_OFFERS + 6; $i++) {
            $this->decided(
                'accepted',
                [
                    ['item' => $item, 'quantity' => 1],
                    ['item' => $partner, 'quantity' => 1],
                ],
                [],
                (int) $intruder['workspace']->id,
            );
        }

        $props = $this->itemProps($item);

        $this->assertNull($props['insight']);
        $this->assertNull($props['sells']);
        $this->assertStringNotContainsString('Ser vitamina C', (string) json_encode($props['insight']));

        $sells = $this->sells($item);
        $this->assertSame(0, (int) $sells['sample_size']);
        $this->assertTrue((bool) $sells['sample_short']);
    }

    public function test_a_withdrawn_product_is_never_recommended(): void
    {
        $item = $this->item(['name' => 'Cremă hidratantă 50ml']);
        $partner = $this->item(['name' => 'Ser vitamina C']);

        $this->buildCrossSellSample($item, $partner, total: 24);

        // The firm stopped selling the serum. Advising it to push a product it
        // has withdrawn is worse than saying nothing at all.
        $partner->update(['is_active' => false]);

        $this->assertNull($this->itemProps($item)['insight']);
    }

    /**
     * Half the sample carries the companion and does well, half does not and
     * does badly: 9 of 12 accepted with, 3 of 12 accepted without.
     */
    private function buildCrossSellSample(CatalogItem $item, CatalogItem $partner, int $total): void
    {
        $with = intdiv($total, 2);
        $without = $total - $with;

        for ($i = 0; $i < $with; $i++) {
            $this->decided($i < (int) round($with * 0.75) ? 'accepted' : 'refused', [
                ['item' => $item, 'quantity' => 1],
                ['item' => $partner, 'quantity' => 1],
            ]);
        }

        for ($i = 0; $i < $without; $i++) {
            $this->decided($i < (int) round($without * 0.25) ? 'accepted' : 'refused', [
                ['item' => $item, 'quantity' => 1],
            ]);
        }
    }

    // ─── the cost of the list ────────────────────────────────────────────

    /**
     * The property that keeps the catalogue usable at four thousand rows.
     *
     * The "ÎN OFERTE" column is the obvious place to write one count per row,
     * and it would look perfectly fine on the developer's twelve products. This
     * says the number of queries is a property of the SCREEN and not of how
     * much the firm sells.
     */
    public function test_the_list_costs_the_same_number_of_queries_at_forty_items_as_at_three(): void
    {
        // A fixed pool of offers the items are quoted on, so the only thing
        // growing between the two measurements is the number of catalogue rows
        // on the page — which is the thing under test.
        $pool = [
            $this->offerWith(),
            $this->offerWith(),
            $this->decided('accepted', []),
        ];

        // Warm whatever the first request of a test resolves once — the user,
        // the locale, the settings. Measuring that in would hide a real
        // regression behind a constant.
        $this->actingAs($this->ctx['user'])->get(route('client.catalog.index'))->assertOk();

        $this->seedItemsOntoOffers(3, $pool);
        $this->assertCount(3, $this->listProps()['items']['data']);
        $small = $this->queriesForTheList();

        $this->seedItemsOntoOffers(37, $pool);
        $page = $this->listProps()['items'];
        // Read outside the measured window, so checking does not perturb the
        // count — and asserted, because a comparison between two screens that
        // both rendered nothing would pass while proving nothing.
        $this->assertSame(40, (int) $page['total']);
        $this->assertGreaterThan(3, count($page['data']));
        $large = $this->queriesForTheList();

        $this->assertSame(
            $small,
            $large,
            "the catalogue list ran {$small} queries for 3 items and {$large} for 40 — the \"ÎN OFERTE\" column is a query per row",
        );
    }

    /** @param  array<int, Offer>  $pool */
    private function seedItemsOntoOffers(int $count, array $pool): void
    {
        for ($i = 0; $i < $count; $i++) {
            $item = $this->item(['name' => 'Produs '.str_pad((string) ++$this->seq, 4, '0', STR_PAD_LEFT)]);

            foreach ($pool as $offer) {
                $this->addLines($offer, [['item' => $item, 'quantity' => 1]], $this->workspaceId());
            }
        }
    }

    private function queriesForTheList(): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();

        try {
            $this->actingAs($this->ctx['user'])
                ->get(route('client.catalog.index'))
                ->assertOk();

            return count(DB::getQueryLog());
        } finally {
            DB::disableQueryLog();
        }
    }
}
