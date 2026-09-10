<?php

namespace App\Modules\Offers\Services;

use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * What the catalogue has actually done on offers: how often an item was quoted,
 * how often the customer said yes, what it sold this month, and what it is
 * usually accepted alongside.
 *
 * THREE QUESTIONS, THREE AGGREGATE QUERIES. Every method here answers with one
 * grouped statement whose cost is set by the indexes and not by how many offers
 * the workspace holds. A firm with four thousand offers pays what a firm with
 * four pays. In particular forCatalogueList() answers the whole page of rows at
 * once: the "ÎN OFERTE" column is never a query per row, because twenty-five
 * rows times one query is how a list screen that felt fine in testing becomes a
 * two-second page for the first customer who fills their catalogue.
 *
 * TENANCY IS SAID OUT LOUD, ON EVERY TABLE. offer_items carries its own
 * workspace_id precisely so an aggregate never has to reach the tenant through
 * a relation, and both sides of every join here are filtered on it — offers,
 * offer_items and catalog_items each state their own workspace_id, in their own
 * ON clause. There is no global scope in this application to catch an omission,
 * and these are queries that read one firm's prices.
 *
 * SOFT DELETES ARE FILTERED BY HAND. These are query-builder statements, not
 * Eloquent ones, so the SoftDeletes scope is not applied for us: every join to
 * offers and to catalog_items says `deleted_at is null` itself. An offer in the
 * bin is not a proposal the firm made.
 *
 * WHAT COUNTS AS A PROPOSAL, AND WHAT COUNTS AS AN ANSWER — the two definitions
 * everything below rests on:
 *
 *  - A DRAFT IS NOT A PROPOSAL. Nobody has seen it. An item quoted on six
 *    drafts and nothing else has been proposed to no one, and the catalogue
 *    list says "niciodată" about it, which is the truth.
 *  - AN OPEN OFFER IS NOT A REFUSAL. The acceptance rate is computed over
 *    offers that were actually answered — accepted plus refused — exactly as
 *    OfferController::stats() already computes the tile above the offers list.
 *    The same words must not mean two different things on two screens, so the
 *    definition is copied deliberately rather than improved on here. 'expired'
 *    is outside it for the same reason: the list tile does not count it, and a
 *    sweep that changed a status is not a customer who said no.
 *
 * AND NOTHING IS DIVIDED UNTIL THERE IS SOMETHING TO DIVIDE. See
 * MIN_DECIDED_OFFERS. Every percentage on these screens has a denominator that
 * is zero for the first weeks of a firm's life, and a tile reading "acceptat
 * 0%" on day three reports a business failing when nothing has happened yet.
 * The counts are facts and are always returned; the ratios are null and the
 * sample is flagged short, so the caller can render nothing at all.
 */
class OfferStats
{
    /**
     * The fewest answered offers that must carry an item before this service
     * divides by anything.
     *
     * Twenty, and not the ten or fourteen that feel like plenty:
     *
     *  - A proportion over a sample this size is already coarse. At twenty
     *    answered offers one customer changing his mind is worth five points,
     *    and the 95% interval around a rate near half is roughly ±22 points.
     *    Twenty is where a percentage becomes worth printing, not where it
     *    becomes precise.
     *  - The cross-sell observation SPLITS that sample in two — offers that
     *    also carried the companion item, and offers that did not. Twenty is at
     *    best ten a side. At fourteen it is seven a side, and one customer
     *    moves the comparison by fourteen points, which is larger than most of
     *    the differences such a panel would be reporting as an insight. A
     *    correlation over fourteen offers is not a small finding; it is noise
     *    with a sentence written under it.
     *  - The two ways of being wrong do not cost the same. A panel that is
     *    absent costs the firm nothing and fills itself in within a few weeks.
     *    A panel that says "acceptat în 25% din cazuri" off four offers tells a
     *    firm to stop selling something that is selling perfectly well.
     *
     * Below this line forItem() still returns its counts — a count is a fact,
     * not a statistic — with sample_short set and every ratio null, and
     * crossSellInsight() returns null outright.
     */
    public const MIN_DECIDED_OFFERS = 20;

    /**
     * The fewest answered offers each SIDE of the cross-sell comparison needs.
     *
     * MIN_DECIDED_OFFERS guards the sample as a whole; this guards the split.
     * Nineteen offers with the companion and one without clears the first gate
     * and still compares a rate against a single customer. Five is a floor and
     * not a comfort — at five, one answer is twenty points — so it is paired
     * with a candidate chosen by frequency rather than by strength; see
     * crossSellInsight().
     */
    public const MIN_COMPARISON_ARM = 5;

    /**
     * The statuses that mean the offer left the building.
     *
     * Everything except 'draft'. 'expired' belongs here — it went to a customer
     * who then let it lapse, which is a proposal that was made — even though it
     * is deliberately absent from DECIDED_STATUSES below.
     *
     * @var list<string>
     */
    private const PROPOSED_STATUSES = ['sent', 'accepted', 'refused', 'expired'];

    /**
     * The statuses that mean the customer answered.
     *
     * The denominator of every rate here, and the same pair
     * OfferController::stats() uses for the tile above the offers list.
     *
     * @var list<string>
     */
    private const DECIDED_STATUSES = ['accepted', 'refused'];

    /**
     * How many offers each catalogue item has been quoted on — the "ÎN OFERTE"
     * column, for a whole page of rows in ONE grouped query.
     *
     * Answered from the (workspace_id, catalog_item_id) index that was put on
     * offer_items in stage 2 for exactly this read, then joined to offers by
     * primary key to drop the drafts and the deleted.
     *
     * COUNT(DISTINCT offer_id) and not COUNT(*): the same product on two lines
     * of one offer — two lengths of the same cable, the same consultation
     * twice — is one offer it appeared on, not two.
     *
     * Every id asked about comes back, zero included, so the caller never has
     * to remember a `?? 0`; zero is what the column renders as "niciodată".
     *
     * @param  list<int>|array<array-key, int|string>  $itemIds  one page of catalogue rows
     * @return array<int, int> catalogue item id => offers it has been quoted on
     */
    public function forCatalogueList(int $workspaceId, array $itemIds): array
    {
        $ids = $this->ids($itemIds);

        if ($workspaceId <= 0 || $ids === []) {
            return [];
        }

        $counts = array_fill_keys($ids, 0);

        $rows = DB::table('offer_items as oi')
            ->join('offers as o', fn (JoinClause $join) => $this->proposedOffers($join, $workspaceId))
            ->where('oi.workspace_id', $workspaceId)
            ->whereIn('oi.catalog_item_id', $ids)
            ->groupBy('oi.catalog_item_id')
            ->selectRaw('oi.catalog_item_id as item_id, count(distinct oi.offer_id) as offers')
            ->get();

        foreach ($rows as $row) {
            $row = (array) $row;
            $id = (int) ($row['item_id'] ?? 0);

            if (isset($counts[$id])) {
                $counts[$id] = (int) ($row['offers'] ?? 0);
            }
        }

        return $counts;
    }

    /**
     * How one catalogue item sells — the "CUM SE VINDE" panel, in one query.
     *
     * Counts and money in the same statement, which is safe here because the
     * join fans out only the way the data does: one row per line of this item
     * on one offer. The per-offer figures are COUNT(DISTINCT offer_id), so an
     * offer carrying the item twice is still one offer; the per-line figures
     * are sums over those same rows, so both of its lines are still sold.
     *
     * WHAT EACH FIGURE MEANS:
     *
     *  - proposed_count — offers that went to a customer carrying this item.
     *    Matches the catalogue list's column exactly; the same drafts are
     *    excluded, so clicking a row never changes the number.
     *  - accepted_count / refused_count — of those, the ones that came back
     *    answered. Their sum is sample_size, and it is the denominator of
     *    accepted_rate: an offer still open is not a loss.
     *  - units_this_month / revenue_cents_this_month — ACCEPTED offers only. A
     *    proposal is not a sale, and a month that counts proposals is a month
     *    report the firm cannot reconcile against its bank. Dated from
     *    decided_at, the only column that says when the customer said yes; an
     *    accepted offer with no decision timestamp is not counted into a month
     *    it cannot be placed in.
     *  - average_discount_percent — see discount() below. Null, never zero,
     *    when nothing was discounted.
     *
     * Money is bani, as everywhere in this module. Units are a decimal quantity
     * (0,5 ore and 2,25 m are ordinary lines), so they come back as a float
     * rounded to the three decimals the column holds.
     *
     * @return array{
     *     proposed_count: int,
     *     accepted_count: int,
     *     refused_count: int,
     *     accepted_rate: int|null,
     *     units_this_month: float,
     *     revenue_cents_this_month: int,
     *     average_discount_percent: float|null,
     *     sample_size: int,
     *     sample_short: bool
     * }
     */
    public function forItem(int $workspaceId, int $itemId): array
    {
        if ($workspaceId <= 0 || $itemId <= 0) {
            return $this->emptyItemStats();
        }

        // Half-open, [this month, next month): a decision recorded at 23:59:59
        // on the last day of the month belongs to that month, and a closed
        // range against endOfMonth() drops it.
        $from = Carbon::now()->startOfMonth();
        $to = (clone $from)->addMonth();

        $row = (array) (DB::table('offer_items as oi')
            ->join('offers as o', fn (JoinClause $join) => $this->proposedOffers($join, $workspaceId))
            // LEFT, and not INNER: the catalogue row is needed only to price the
            // discount against its list price. An item deleted from the
            // catalogue must still be able to show what it sold, with the
            // discount figures simply absent.
            ->leftJoin('catalog_items as ci', function (JoinClause $join) use ($workspaceId) {
                $join->on('ci.id', '=', 'oi.catalog_item_id')
                    ->where('ci.workspace_id', '=', $workspaceId);
            })
            ->where('oi.workspace_id', $workspaceId)
            ->where('oi.catalog_item_id', $itemId)
            ->selectRaw(
                <<<'SQL'
                    count(distinct oi.offer_id) as proposed_count,
                    count(distinct case when o.status = 'accepted' then oi.offer_id end) as accepted_count,
                    count(distinct case when o.status = 'refused' then oi.offer_id end) as refused_count,
                    coalesce(sum(case when o.status = 'accepted' and o.decided_at >= ? and o.decided_at < ? then oi.quantity end), 0) as units_this_month,
                    coalesce(sum(case when o.status = 'accepted' and o.decided_at >= ? and o.decided_at < ? then oi.line_total_cents end), 0) as revenue_this_month,
                    -- Averaged over every accepted line, full-price ones
                    -- included. The tile answers "how much do I give away when I
                    -- sell this", and a sale at list price is a sale where the
                    -- firm gave nothing — so it belongs in the average. The
                    -- other reading, the mean size of the discounts actually
                    -- granted, is a different and less actionable number; the
                    -- label says which one this is, and displayAverage() keeps a
                    -- real but tiny figure from printing as a flat 0%.
                    avg(case when o.status = 'accepted' and ci.price_cents > 0
                        then case when oi.unit_price_cents >= ci.price_cents then 0
                            else (ci.price_cents - oi.unit_price_cents) * 100.0 / ci.price_cents end
                        end) as discount_percent,
                    sum(case when o.status = 'accepted' and ci.price_cents > 0 and oi.unit_price_cents < ci.price_cents then 1 else 0 end) as discounted_lines
                    SQL,
                [$from, $to, $from, $to],
            )
            ->first() ?? []);

        $accepted = (int) ($row['accepted_count'] ?? 0);
        $refused = (int) ($row['refused_count'] ?? 0);
        $sample = $accepted + $refused;
        $short = $sample < self::MIN_DECIDED_OFFERS;

        return [
            'proposed_count' => (int) ($row['proposed_count'] ?? 0),
            'accepted_count' => $accepted,
            'refused_count' => $refused,
            // A whole percentage, like the tile above the offers list, and null
            // — not zero — while the sample is short.
            'accepted_rate' => $short ? null : (int) round($accepted * 100 / max(1, $sample)),
            // decimal(12,3) arrives as a string. Rounded at the scale the column
            // actually holds rather than left as whatever the float printed.
            'units_this_month' => round((float) ($row['units_this_month'] ?? 0), 3),
            'revenue_cents_this_month' => (int) ($row['revenue_this_month'] ?? 0),
            'average_discount_percent' => $short ? null : $this->discount($row),
            'sample_size' => $sample,
            // The whole point of this key: the caller renders NOTHING when it is
            // true. Not a zero, not a dash, not "0%".
            'sample_short' => $short,
        ];
    }

    /**
     * What this item is usually accepted alongside — "OBSERVAȚIA AGENTULUI" —
     * or null when there is not enough answered business to say anything.
     *
     * The comparison: among answered offers carrying this item, the acceptance
     * rate of those that ALSO carried the companion, against the rate of those
     * that did not. Both arms come out of the same sample, so the two rates are
     * comparable and their difference is a number about this firm's own selling
     * rather than about the market.
     *
     * THE CANDIDATE IS CHOSEN BY FREQUENCY, NEVER BY STRENGTH. It is the item
     * accepted alongside this one most often; the rates are then reported for
     * whatever that item turns out to be, difference included when it is
     * negative. Ranking candidates by the size of the gap instead — picking the
     * best-looking of forty companions — would manufacture an insight out of any
     * catalogue at all, because the largest of forty differences is large by
     * construction. That is the entire failure mode of this kind of panel, and
     * choosing by frequency is what avoids it.
     *
     * ONE STATEMENT. The per-companion counts are one grouped aggregate; the
     * sample totals are a one-row aggregate cross-joined to it, so the "without"
     * arm is arithmetic on figures the same statement produced rather than a
     * second round trip, and the whole thing is answered before PHP sees a row.
     * Companions whose own arm — or whose complement — is under
     * MIN_COMPARISON_ARM are dropped in SQL rather than in PHP, so a thin top
     * companion does not silently blank a panel that a real one could fill.
     *
     * Only an active, undeleted catalogue row can be named: advising a firm to
     * sell something it has withdrawn is worse than saying nothing.
     *
     * @return array{
     *     item: array{id: int, uuid: string, name: string},
     *     sample_size: int,
     *     together_count: int,
     *     together_accepted: int,
     *     accepted_rate_with: int,
     *     alone_count: int,
     *     alone_accepted: int,
     *     accepted_rate_without: int,
     *     difference: int
     * }|null
     */
    public function crossSellInsight(int $workspaceId, int $itemId): ?array
    {
        if ($workspaceId <= 0 || $itemId <= 0) {
            return null;
        }

        // Every answered offer carrying this item: the sample both arms are cut
        // out of, and the reason the two rates can be compared at all.
        $sample = fn (): Builder => DB::table('offer_items as mine')
            ->join('offers as o', fn (JoinClause $join) => $this->decidedOffers($join, $workspaceId))
            ->where('mine.workspace_id', $workspaceId)
            ->where('mine.catalog_item_id', $itemId);

        $totals = $sample()->selectRaw(
            <<<'SQL'
                count(distinct o.id) as sample_size,
                count(distinct case when o.status = 'accepted' then o.id end) as sample_accepted
                SQL
        );

        $companions = $sample()
            ->join('offer_items as other', function (JoinClause $join) use ($workspaceId, $itemId) {
                $join->on('other.offer_id', '=', 'o.id')
                    ->where('other.workspace_id', '=', $workspaceId)
                    ->whereNotNull('other.catalog_item_id')
                    ->where('other.catalog_item_id', '!=', $itemId);
            })
            ->groupBy('other.catalog_item_id')
            ->selectRaw(
                <<<'SQL'
                    other.catalog_item_id as related_id,
                    count(distinct o.id) as together_count,
                    count(distinct case when o.status = 'accepted' then o.id end) as together_accepted
                    SQL
            );

        $row = (array) (DB::query()
            ->fromSub($companions, 'c')
            ->crossJoinSub($totals, 't')
            ->join('catalog_items as ci', function (JoinClause $join) use ($workspaceId) {
                $join->on('ci.id', '=', 'c.related_id')
                    ->where('ci.workspace_id', '=', $workspaceId)
                    ->where('ci.is_active', '=', true)
                    ->whereNull('ci.deleted_at');
            })
            ->where('t.sample_size', '>=', self::MIN_DECIDED_OFFERS)
            ->where('c.together_count', '>=', self::MIN_COMPARISON_ARM)
            ->whereRaw('t.sample_size - c.together_count >= ?', [self::MIN_COMPARISON_ARM])
            ->select(
                'c.related_id',
                'c.together_count',
                'c.together_accepted',
                't.sample_size',
                't.sample_accepted',
                'ci.uuid',
                'ci.name',
            )
            // Frequency first, and only frequency. Ordering by together_accepted
            // — the numerator of the rate the panel goes on to report — is
            // selecting on the outcome: it picks the arm that looks best and
            // manufactures an insight out of any catalogue. Measured: a
            // companion on 7 offers all accepted outranked one on 12 offers,
            // and the panel printed "urcă de la 46% la 100%" off seven
            // customers. Acceptance is the tiebreak, not the ranking.
            ->orderByDesc('c.together_count')
            ->orderByDesc('c.together_accepted')
            // Two companions accepted equally often would otherwise swap places
            // between page loads, and a panel whose observation changes when you
            // press F5 is one nobody believes twice.
            ->orderBy('c.related_id')
            ->limit(1)
            ->first() ?? []);

        if ($row === []) {
            return null;
        }

        $sampleSize = (int) ($row['sample_size'] ?? 0);
        $sampleAccepted = (int) ($row['sample_accepted'] ?? 0);
        $togetherCount = (int) ($row['together_count'] ?? 0);
        $togetherAccepted = (int) ($row['together_accepted'] ?? 0);

        // The offers that carried this item WITHOUT the companion. Subtraction
        // and not a third query: the companion's offers are a subset of the
        // sample, both figures come from the same statement, and one query that
        // has already been paid for is the cheapest arm there is.
        $aloneCount = $sampleSize - $togetherCount;
        $aloneAccepted = $sampleAccepted - $togetherAccepted;

        // Belt and braces on the SQL gates above. If either arm is empty, there
        // is no comparison to draw and the panel says nothing at all.
        if ($togetherCount < self::MIN_COMPARISON_ARM || $aloneCount < self::MIN_COMPARISON_ARM) {
            return null;
        }

        $rateWith = (int) round($togetherAccepted * 100 / $togetherCount);
        $rateWithout = (int) round($aloneAccepted * 100 / $aloneCount);

        return [
            'item' => [
                'id' => (int) ($row['related_id'] ?? 0),
                'uuid' => (string) ($row['uuid'] ?? ''),
                'name' => (string) ($row['name'] ?? ''),
            ],
            'sample_size' => $sampleSize,
            'together_count' => $togetherCount,
            'together_accepted' => $togetherAccepted,
            'accepted_rate_with' => $rateWith,
            'alone_count' => $aloneCount,
            'alone_accepted' => $aloneAccepted,
            'accepted_rate_without' => $rateWithout,
            // Percentage POINTS, and signed. A companion that is accepted less
            // often is a finding too, and hiding the sign would turn the panel
            // into an advertisement.
            'difference' => $rateWith - $rateWithout,
        ];
    }

    /**
     * The join every "was it proposed" figure hangs off: this workspace's
     * offers, not in the bin, not still a draft.
     */
    private function proposedOffers(JoinClause $join, int $workspaceId): JoinClause
    {
        return $join->on('o.id', '=', 'oi.offer_id')
            ->where('o.workspace_id', '=', $workspaceId)
            ->whereNull('o.deleted_at')
            ->whereIn('o.status', self::PROPOSED_STATUSES);
    }

    /**
     * The same join, narrowed to the offers the customer actually answered —
     * the only population a rate may be computed over.
     */
    private function decidedOffers(JoinClause $join, int $workspaceId): JoinClause
    {
        return $join->on('o.id', '=', 'mine.offer_id')
            ->where('o.workspace_id', '=', $workspaceId)
            ->whereNull('o.deleted_at')
            ->whereIn('o.status', self::DECIDED_STATUSES);
    }

    /**
     * The average discount given on accepted lines, as a percentage of the list
     * price — or NULL when the firm never discounted this item.
     *
     * NULL AND NOT ZERO, and the difference is the whole point: "discount mediu
     * 0%" reads as a measurement, as though the firm had been asked and had
     * held the line. Nothing was measured. There were no discounted lines, so
     * there is no average, and the caller prints nothing.
     *
     * ONE DECIMAL, unlike the whole-percent rates beside it, because rounding a
     * real 0,4% average down to a whole "0%" prints exactly the false zero this
     * class exists to keep off the screen.
     *
     * TWO THINGS TO KNOW ABOUT THE FIGURE:
     *
     *  - The comparison is against the catalogue's price TODAY. The line
     *    snapshots what was charged, but nothing snapshots what was asked, so a
     *    firm that raised its list price last week will see last month's
     *    discounts widen. There is no other column to read; the alternative is
     *    not a better number, it is no number.
     *  - A line priced ABOVE today's list price contributes zero, never a
     *    negative. That line is not a negative discount granted to a customer,
     *    it is a catalogue price that has moved since — and letting it subtract
     *    would net two unrelated stories into one meaningless average.
     *
     * @param  array<string, mixed>  $row  the aggregate row from forItem()
     */
    private function discount(array $row): ?float
    {
        $discounted = (int) ($row['discounted_lines'] ?? 0);
        $average = $row['discount_percent'] ?? null;

        if ($discounted <= 0 || $average === null || ! is_numeric($average)) {
            return null;
        }

        return round((float) $average, 1);
    }

    /**
     * Positive integer ids, each once. Anything else a caller hands over —
     * a null in the array, a string id out of a request, a duplicate — is
     * dropped here rather than reaching an IN clause.
     *
     * @param  array<array-key, mixed>  $itemIds
     * @return list<int>
     */
    private function ids(array $itemIds): array
    {
        $ids = [];

        foreach ($itemIds as $id) {
            if (! is_numeric($id)) {
                continue;
            }

            $id = (int) $id;

            if ($id > 0) {
                $ids[$id] = $id;
            }
        }

        return array_values($ids);
    }

    /**
     * What an item with no history looks like: every count zero, every ratio
     * null, and the sample flagged short — which is exactly what a brand-new
     * catalogue row is, and exactly what the panel must not draw.
     *
     * @return array{
     *     proposed_count: int,
     *     accepted_count: int,
     *     refused_count: int,
     *     accepted_rate: int|null,
     *     units_this_month: float,
     *     revenue_cents_this_month: int,
     *     average_discount_percent: float|null,
     *     sample_size: int,
     *     sample_short: bool
     * }
     */
    private function emptyItemStats(): array
    {
        return [
            'proposed_count' => 0,
            'accepted_count' => 0,
            'refused_count' => 0,
            'accepted_rate' => null,
            'units_this_month' => 0.0,
            'revenue_cents_this_month' => 0,
            'average_discount_percent' => null,
            'sample_size' => 0,
            'sample_short' => true,
        ];
    }
}
