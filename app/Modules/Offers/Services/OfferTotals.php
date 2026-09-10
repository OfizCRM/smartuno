<?php

namespace App\Modules\Offers\Services;

use App\Support\Romania;

/**
 * What an offer adds up to.
 *
 * Pure in the sense that matters: no database, no request, no session, no
 * writes, and the same arguments always produce the same figures. The one thing
 * it reads outside its arguments is the legislated VAT table, through
 * App\Support\Romania — the alternative is an inline 21 that goes quietly wrong
 * the next time Parliament moves the rate, which it did on 2025-08-01.
 *
 * Everything here is an integer number of bani. Money::bani() is the lei-to-bani
 * parser at the edge, where a person types "1.234,50" into a box; by the time a
 * line reaches this service it is already bani and is never re-parsed.
 *
 * THE ORDER IS DELIBERATE, and each step depends on the one before it:
 *
 *   1. Each line is rounded ONCE, on its own. Rounding the sum of unrounded
 *      lines instead would put the offer a ban or two away from the column the
 *      customer can add up himself, which is the one arithmetic error a person
 *      always spots.
 *   2. Subtotal is the sum of those already-rounded lines.
 *   3. Discount comes off the subtotal, never off the total: a discount taken
 *      after VAT would hand the seller's discount to the state.
 *   4. Free shipping is decided on what is left AFTER the discount. A 10%
 *      discount can drop an order back under the free-shipping line, and the
 *      seller has to be able to see that on the offer rather than discover it
 *      when the parcel is priced.
 *   5. VAT is charged on (subtotal - discount + shipping), because in Romania
 *      transport invoiced with the goods is part of the taxable base — not a
 *      VAT-free extra bolted on at the end.
 *   6. Total is the sum of the four.
 *
 * Reordering any two of these changes what the customer is asked to pay.
 */
class OfferTotals
{
    /**
     * The offer, priced.
     *
     * @param  array<array-key, array<string, mixed>>  $lines  each with quantity and unit_price_cents
     * @param  array<string, mixed>  $settings  as OfferSettings::get() returns them
     * @param  string|null  $vatStatus  the SELLER's regime: none | standard | on_collection
     * @param  float|null  $vatRate  the seller's own rate; the legislated standard one when null
     * @return array{
     *     subtotal_cents: int,
     *     discount_cents: int,
     *     shipping_cents: int,
     *     vat_cents: int,
     *     total_cents: int,
     *     lines: array<array-key, array<string, mixed>>
     * }
     */
    public function compute(array $lines, array $settings, ?string $vatStatus, ?float $vatRate): array
    {
        // Nothing quoted is worth nothing. Without this, a brand-new draft is
        // created carrying the shipping fee and VAT on it — and the list's
        // "valoare în lucru" sums those phantom totals, while the PDF prints
        // "Oferta nu conține produse" above a Total de plată.
        if ($lines === []) {
            return [
                'lines' => [],
                'subtotal_cents' => 0,
                'discount_cents' => 0,
                'shipping_cents' => 0,
                'vat_cents' => 0,
                'total_cents' => 0,
            ];
        }

        // 1 and 2.
        $priced = [];
        $subtotal = 0;

        foreach ($lines as $key => $line) {
            $milliQuantity = $this->milliQuantity($line['quantity'] ?? 1);
            $unitPrice = max(0, (int) ($line['unit_price_cents'] ?? 0));

            // Half-up in integers rather than round($q * $price): the quantity
            // column holds three decimals, so the product is exact in
            // thousandths and the rounding never has to trust a float.
            $lineTotal = intdiv($milliQuantity * $unitPrice + 500, 1000);

            $subtotal += $lineTotal;

            $priced[$key] = array_merge($line, [
                // Written back the way the column stores it, so a line that has
                // been through here and one read back from the database compare
                // equal.
                'quantity' => number_format($milliQuantity / 1000, 3, '.', ''),
                'unit_price_cents' => $unitPrice,
                'line_total_cents' => $lineTotal,
            ]);
        }

        // 3. Never below nothing, never more than the goods are worth — both
        // columns are unsigned, and a discount larger than the subtotal is a
        // typo in the settings, not a refund.
        $percent = max(0.0, (float) ($settings['default_discount_percent'] ?? OfferSettings::DEFAULTS['default_discount_percent']));
        $discount = min($subtotal, max(0, (int) round($subtotal * $percent / 100, 0, PHP_ROUND_HALF_UP)));

        // 4. A free_shipping_cents of 0 means the firm does not offer free
        // shipping at all, not that everything ships free.
        $shipping = max(0, (int) ($settings['shipping_cents'] ?? OfferSettings::DEFAULTS['shipping_cents']));
        $freeFrom = max(0, (int) ($settings['free_shipping_cents'] ?? OfferSettings::DEFAULTS['free_shipping_cents']));

        if ($freeFrom > 0 && ($subtotal - $discount) >= $freeFrom) {
            $shipping = 0;
        }

        // 5.
        $rate = $this->rateFor($vatStatus, $vatRate);
        $vatBase = $subtotal - $discount + $shipping;
        $vat = $rate > 0.0 ? (int) round($vatBase * $rate / 100, 0, PHP_ROUND_HALF_UP) : 0;

        return [
            'subtotal_cents' => $subtotal,
            'discount_cents' => $discount,
            'shipping_cents' => $shipping,
            'vat_cents' => $vat,
            // 6.
            'total_cents' => $vatBase + $vat,
            'lines' => $priced,
        ];
    }

    /**
     * The rate to charge, as a percentage.
     *
     * 'standard' and 'on_collection' both charge VAT — TVA la încasare changes
     * when the state collects it and which mention the document carries, not the
     * arithmetic. 'none' is the seller under the registration threshold, and an
     * unrecognised value is treated the same way: inventing VAT for a firm that
     * is not registered to charge it is the expensive direction to be wrong in.
     *
     * The seller's own rate wins when the profile carries one, because an offer
     * snapshots it at creation and must keep reproducing the same figures after
     * the law moves. The legislated standard rate is only the fallback for a
     * profile that never filled it in.
     */
    private function rateFor(?string $vatStatus, ?float $vatRate): float
    {
        if ($vatStatus === null || $vatStatus === 'none' || ! in_array($vatStatus, Romania::vatStatuses(), true)) {
            return 0.0;
        }

        if ($vatRate !== null && $vatRate > 0.0) {
            return $vatRate;
        }

        return Romania::standardVatRate();
    }

    /**
     * A quantity in thousandths, which is exactly what decimal(12,3) holds.
     *
     * The comma is normalised because a Romanian types "1,5" and a stray one
     * reaching (float) would silently become 1 — half the parcel, at full price.
     */
    private function milliQuantity(mixed $quantity): int
    {
        $normalised = str_replace(',', '.', (string) (is_scalar($quantity) ? $quantity : 0));

        return max(0, (int) round(((float) $normalised) * 1000, 0, PHP_ROUND_HALF_UP));
    }
}
