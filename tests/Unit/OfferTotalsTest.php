<?php

namespace Tests\Unit;

use App\Modules\Offers\Services\OfferSettings;
use App\Modules\Offers\Services\OfferTotals;
use App\Support\Romania;
use Tests\TestCase;

/**
 * The arithmetic behind every offer, tested where it lives.
 *
 * OfferTotals is pure — no database, no request, no session, no writes — so
 * there is no RefreshDatabase here and nothing in this file touches a table.
 * That is the point of the service being pure: the sums a customer will add up
 * by hand are checked without a migration in sight.
 *
 * The application IS booted, for one reason only: when a seller's profile
 * carries no rate of its own, the service falls back to the legislated standard
 * rate, and that table is config/romania.php. A config read is not a database
 * read, but it does need the container — so the alternative to booting is
 * leaving the fallback untested, which is the branch every firm that has not
 * filled in its VAT rate will actually take.
 *
 * Everything below is bani. 24000 is 240,00 lei. A figure written as lei
 * anywhere in this file is a bug in the file.
 *
 * The failures being guarded against are all quiet ones. A total that is one ban
 * off does not throw; it goes out on a PDF, the customer adds the column up
 * himself, and the firm looks like it cannot count.
 */
class OfferTotalsTest extends TestCase
{
    private OfferTotals $totals;

    protected function setUp(): void
    {
        parent::setUp();
        $this->totals = new OfferTotals;
    }

    /**
     * Settings as OfferSettings::get() hands them over, with the parts this
     * service reads overridable per test.
     *
     * Defaults to shipping and free-shipping BOTH disabled, so a test that is
     * about VAT or about a discount is not quietly also a test about delivery.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function settings(array $overrides = []): array
    {
        return array_merge(OfferSettings::DEFAULTS, [
            'shipping_cents' => 0,
            'free_shipping_cents' => 0,
            'default_discount_percent' => 0,
        ], $overrides);
    }

    /**
     * One line, in the shape the controller builds from the form.
     *
     * @return array<string, mixed>
     */
    private function line(mixed $quantity, int $unitPriceCents): array
    {
        return ['quantity' => $quantity, 'unit_price_cents' => $unitPriceCents];
    }

    /**
     * The identity every offer must satisfy, asserted on the result itself.
     *
     * subtotal - discount + shipping + vat = total, exactly, in integers. There
     * is no tolerance and no epsilon: these are bani, and a discrepancy of one
     * is a discrepancy.
     *
     * @param  array<string, mixed>  $result
     */
    private function assertAddsUp(array $result, string $context = ''): void
    {
        $this->assertSame(
            $result['subtotal_cents'] - $result['discount_cents'] + $result['shipping_cents'] + $result['vat_cents'],
            $result['total_cents'],
            'The total does not equal subtotal - discount + shipping + vat'.($context === '' ? '' : " ({$context})"),
        );
    }

    // ─── lines and the subtotal ──────────────────────────────────────────

    public function test_two_lines_one_of_them_a_fractional_quantity_add_up(): void
    {
        $result = $this->totals->compute(
            [
                // 2,5 ore × 240,00 lei
                $this->line('2.5', 24000),
                // 1 buc × 150,50 lei
                $this->line('1', 15050),
            ],
            $this->settings(),
            'none',
            null,
        );

        $this->assertSame(60000, $result['lines'][0]['line_total_cents']);
        $this->assertSame(15050, $result['lines'][1]['line_total_cents']);
        $this->assertSame(75050, $result['subtotal_cents']);
        $this->assertSame(75050, $result['total_cents']);
        $this->assertAddsUp($result);
    }

    /**
     * A Romanian keyboard offers the comma as the decimal separator, so "1,5"
     * is what a person actually types into the quantity box. Reaching (float)
     * unnormalised it becomes 1 — half the parcel, invoiced at full price, with
     * nothing on the screen saying so.
     */
    public function test_a_quantity_typed_with_a_comma_is_read_as_a_fraction_and_not_truncated(): void
    {
        $result = $this->totals->compute([$this->line('1,5', 24000)], $this->settings(), 'none', null);

        $this->assertSame(36000, $result['subtotal_cents']);
    }

    /**
     * THE ROUNDING TEST.
     *
     * Three lines of 0,333 × 9,99 lei. Rounded once per line, each is 333 bani
     * and the subtotal is 999. Summed unrounded and rounded once at the end,
     * 3 × 332,667 = 998,001 rounds to 998.
     *
     * The customer sees the per-line column on the PDF and adds it up. If the
     * subtotal is 998 while the three lines each read 3,33, the offer is wrong
     * in the one way anybody checks.
     */
    public function test_rounding_happens_once_per_line_and_not_once_at_the_end(): void
    {
        $result = $this->totals->compute(
            [
                $this->line('0.333', 999),
                $this->line('0.333', 999),
                $this->line('0.333', 999),
            ],
            $this->settings(),
            'none',
            null,
        );

        foreach ($result['lines'] as $line) {
            $this->assertSame(333, $line['line_total_cents']);
        }

        $this->assertSame(999, $result['subtotal_cents']);
        // Named explicitly so the failure message says what went wrong rather
        // than only that two integers differ.
        $this->assertNotSame(998, $result['subtotal_cents'], 'The subtotal was rounded once at the end instead of once per line.');
        $this->assertAddsUp($result);
    }

    public function test_an_offer_with_no_lines_is_zero_rather_than_an_error(): void
    {
        $result = $this->totals->compute([], $this->settings(['shipping_cents' => 2500]), 'standard', 21.0);

        $this->assertSame(0, $result['subtotal_cents']);
        $this->assertSame(0, $result['discount_cents']);
        $this->assertSame([], $result['lines']);
        $this->assertAddsUp($result);
    }

    // ─── discount ────────────────────────────────────────────────────────

    public function test_a_ten_percent_discount_comes_off_the_subtotal(): void
    {
        $result = $this->totals->compute(
            [$this->line('2.5', 24000), $this->line('1', 15050)],
            $this->settings(['default_discount_percent' => 10]),
            'none',
            null,
        );

        $this->assertSame(75050, $result['subtotal_cents']);
        $this->assertSame(7505, $result['discount_cents']);
        $this->assertSame(67545, $result['total_cents']);
        $this->assertAddsUp($result);
    }

    /** Half a ban goes up, the way a price is rounded on paper. */
    public function test_a_discount_that_lands_on_half_a_ban_rounds_up(): void
    {
        $result = $this->totals->compute(
            [$this->line('1', 7505)],
            $this->settings(['default_discount_percent' => 10]),
            'none',
            null,
        );

        // 750,5 bani. Down would be 750.
        $this->assertSame(751, $result['discount_cents']);
        $this->assertSame(6754, $result['total_cents']);
        $this->assertAddsUp($result);
    }

    /**
     * discount_cents is unsigned, and a total below zero is not a quote — it is
     * a promise to pay the customer. A discount can take the goods to nothing
     * and no further.
     */
    public function test_a_full_discount_leaves_nothing_owed_rather_than_a_negative_total(): void
    {
        $result = $this->totals->compute(
            [$this->line('1', 24000)],
            $this->settings(['default_discount_percent' => 100]),
            'none',
            null,
        );

        $this->assertSame(24000, $result['discount_cents']);
        $this->assertSame(0, $result['total_cents']);
        $this->assertAddsUp($result);
    }

    // ─── free shipping ───────────────────────────────────────────────────

    /**
     * The threshold, at the three values that matter. No discount in any of
     * them, so the answer cannot depend on which base the rule reads — that is
     * pinned separately below.
     *
     * @return array<string, array{0: int, 1: int}>
     */
    public static function thresholdCases(): array
    {
        return [
            'one ban below the threshold' => [29999, 2500],
            'exactly at the threshold' => [30000, 0],
            'one ban above the threshold' => [30001, 0],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('thresholdCases')]
    public function test_free_delivery_starts_at_the_threshold_and_not_a_ban_later(int $subtotal, int $expectedShipping): void
    {
        $result = $this->totals->compute(
            [$this->line('1', $subtotal)],
            $this->settings(['shipping_cents' => 2500, 'free_shipping_cents' => 30000]),
            'none',
            null,
        );

        $this->assertSame($subtotal, $result['subtotal_cents']);
        $this->assertSame($expectedShipping, $result['shipping_cents']);
        $this->assertSame($subtotal + $expectedShipping, $result['total_cents']);
        $this->assertAddsUp($result);
    }

    /**
     * A free_shipping_cents of 0 is "this firm does not do free delivery", not
     * "everything ships free" — the difference between charging 25 lei and
     * charging nothing on every single offer.
     */
    public function test_a_zero_threshold_means_no_free_delivery_rather_than_free_delivery_on_everything(): void
    {
        $result = $this->totals->compute(
            [$this->line('1', 100000)],
            $this->settings(['shipping_cents' => 2500, 'free_shipping_cents' => 0]),
            'none',
            null,
        );

        $this->assertSame(2500, $result['shipping_cents']);
        $this->assertSame(102500, $result['total_cents']);
    }

    /**
     * The discount is taken first, so a discount can push an order back under
     * the free-delivery line. The seller has to see that on the offer, not
     * discover it when the parcel is priced.
     */
    public function test_the_threshold_is_measured_after_the_discount(): void
    {
        $result = $this->totals->compute(
            [$this->line('1', 31000)],
            $this->settings([
                'shipping_cents' => 2500,
                'free_shipping_cents' => 30000,
                'default_discount_percent' => 10,
            ]),
            'none',
            null,
        );

        // 310,00 lei is over the line; 279,00 lei after the discount is not.
        $this->assertSame(3100, $result['discount_cents']);
        $this->assertSame(2500, $result['shipping_cents']);
        $this->assertSame(30400, $result['total_cents']);
        $this->assertAddsUp($result);
    }

    // ─── VAT ─────────────────────────────────────────────────────────────

    /**
     * A registered seller charges VAT on the goods AND on the delivery: in
     * Romania transport invoiced together with the goods is part of the taxable
     * base, not a VAT-free extra bolted on at the end.
     */
    public function test_a_registered_seller_charges_vat_on_the_goods_and_the_delivery(): void
    {
        $result = $this->totals->compute(
            [$this->line('1', 10000)],
            $this->settings(['shipping_cents' => 2500]),
            'standard',
            21.0,
        );

        $this->assertSame(10000, $result['subtotal_cents']);
        $this->assertSame(2500, $result['shipping_cents']);
        // 21% of 125,00 lei, not of 100,00.
        $this->assertSame(2625, $result['vat_cents']);
        $this->assertSame(15125, $result['total_cents']);
        $this->assertAddsUp($result);
    }

    /** VAT is charged on what is left after the discount, never before it. */
    public function test_vat_is_charged_on_the_discounted_amount(): void
    {
        $result = $this->totals->compute(
            [$this->line('1', 10000)],
            $this->settings(['default_discount_percent' => 10]),
            'standard',
            21.0,
        );

        $this->assertSame(1000, $result['discount_cents']);
        // 21% of 90,00 lei. Charged before the discount it would be 2100.
        $this->assertSame(1890, $result['vat_cents']);
        $this->assertSame(10890, $result['total_cents']);
        $this->assertAddsUp($result);
    }

    /**
     * TVA la încasare changes when the state collects the tax and what the
     * document has to say, not what the customer is asked to pay.
     */
    public function test_a_seller_on_vat_at_collection_charges_the_same_vat(): void
    {
        $result = $this->totals->compute([$this->line('1', 10000)], $this->settings(), 'on_collection', 21.0);

        $this->assertSame(2100, $result['vat_cents']);
        $this->assertSame(12100, $result['total_cents']);
        $this->assertAddsUp($result);
    }

    /**
     * The rate the seller's own profile carries wins. An offer snapshots it at
     * creation precisely so it keeps reproducing the same figures after the law
     * moves — as it did on 2025-08-01, when 19% became 21%.
     */
    public function test_the_sellers_own_rate_is_used_in_preference_to_the_current_legislated_one(): void
    {
        $result = $this->totals->compute([$this->line('1', 10000)], $this->settings(), 'standard', 19.0);

        $this->assertSame(1900, $result['vat_cents']);
        $this->assertSame(11900, $result['total_cents']);
    }

    /** A profile that never filled the rate in falls back to the law. */
    public function test_a_registered_seller_without_a_rate_falls_back_to_the_legislated_standard_rate(): void
    {
        $result = $this->totals->compute([$this->line('1', 10000)], $this->settings(), 'standard', null);

        $expected = (int) round(10000 * Romania::standardVatRate() / 100);

        $this->assertSame($expected, $result['vat_cents']);
        $this->assertGreaterThan(0, $result['vat_cents'], 'The legislated standard rate resolved to nothing.');
        $this->assertAddsUp($result);
    }

    /**
     * The seller under the registration threshold — a great many of the firms
     * this is sold to. Inventing VAT for them overcharges the customer by a
     * fifth and puts a tax on the offer that the firm is not allowed to collect.
     *
     * @return array<string, array{0: string|null}>
     */
    public static function unregisteredSellers(): array
    {
        return [
            'not registered for VAT' => ['none'],
            'no fiscal profile at all' => [null],
            'a status this application does not know' => ['neplatitor'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('unregisteredSellers')]
    public function test_an_unregistered_seller_charges_no_vat(?string $vatStatus): void
    {
        $result = $this->totals->compute(
            [$this->line('1', 10000)],
            $this->settings(['shipping_cents' => 2500]),
            $vatStatus,
            // A stale rate left on the profile must not resurrect the charge.
            21.0,
        );

        $this->assertSame(0, $result['vat_cents']);
        $this->assertSame(12500, $result['total_cents']);
        $this->assertAddsUp($result);
    }

    // ─── the property that must hold everywhere ──────────────────────────

    /**
     * The identity, across every combination of the things that move: awkward
     * quantities, awkward prices, a discount, a delivery charge either side of
     * the threshold, and both VAT regimes.
     *
     * This is the test that catches an ordering change nobody meant to make —
     * VAT applied before the discount, shipping added after the total, a
     * rounding step moved. None of those break a single figure loudly; they
     * break the identity.
     */
    public function test_the_figures_add_up_exactly_across_every_combination(): void
    {
        $quantities = ['0.333', '1', '1,5', '2.75', '13'];
        $prices = [1, 999, 7505, 24000, 123457];
        $discounts = [0, 7, 10, 33, 100];
        $shippings = [0, 1, 2500];
        $thresholds = [0, 30000];
        $sellers = [['none', null], ['standard', 21.0], ['on_collection', 19.0], ['standard', null]];

        $checked = 0;

        foreach ($quantities as $quantity) {
            foreach ($prices as $price) {
                foreach ($discounts as $discount) {
                    foreach ($shippings as $shipping) {
                        foreach ($thresholds as $threshold) {
                            foreach ($sellers as [$vatStatus, $vatRate]) {
                                $result = $this->totals->compute(
                                    [$this->line($quantity, $price), $this->line('2', $price)],
                                    $this->settings([
                                        'default_discount_percent' => $discount,
                                        'shipping_cents' => $shipping,
                                        'free_shipping_cents' => $threshold,
                                    ]),
                                    $vatStatus,
                                    $vatRate,
                                );

                                $context = "qty {$quantity}, price {$price}, discount {$discount}%, shipping {$shipping}, threshold {$threshold}, seller ".($vatStatus ?? 'null');

                                $this->assertAddsUp($result, $context);

                                // Nothing may go negative: every one of these is
                                // an unsigned column.
                                foreach (['subtotal_cents', 'discount_cents', 'shipping_cents', 'vat_cents', 'total_cents'] as $key) {
                                    $this->assertGreaterThanOrEqual(0, $result[$key], "{$key} went negative ({$context})");
                                    $this->assertIsInt($result[$key], "{$key} is not an integer ({$context})");
                                }

                                // The subtotal is the sum of the lines that were
                                // returned, not a separately derived number.
                                $this->assertSame(
                                    array_sum(array_column($result['lines'], 'line_total_cents')),
                                    $result['subtotal_cents'],
                                    "The subtotal is not the sum of the lines ({$context})",
                                );

                                $checked++;
                            }
                        }
                    }
                }
            }
        }

        $this->assertSame(3000, $checked, 'The matrix did not run the number of combinations it claims to.');
    }
}
