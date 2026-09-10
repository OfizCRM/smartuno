/**
 * Money, in one place.
 *
 * Every amount in the catalogue is stored as an integer number of bani, so the
 * only two conversions that exist are at the edges: bani to something a person
 * reads, and what a person typed back to a decimal the server can validate.
 *
 * This file exists because there were already three page-local formatters in
 * the app under three different names, all defaulting to dollars. The offer
 * builder needs the same two functions, and it will copy whichever it finds
 * first.
 */

const WHOLE = new Intl.NumberFormat('ro-RO', { maximumFractionDigits: 0 });
const EXACT = new Intl.NumberFormat('ro-RO', { minimumFractionDigits: 2, maximumFractionDigits: 2 });

/**
 * Bani as a price a Romanian reads: "240 lei", "1.234,50 lei".
 *
 * Whole amounts lose the decimals — a price list of ".00" twice per row is
 * harder to scan, and most prices in these catalogues are round.
 */
export function formatLei(cents) {
    const value = Number(cents ?? 0) / 100;

    return `${(Number.isInteger(value) ? WHOLE : EXACT).format(value)} lei`;
}

/**
 * Bani as the contents of an input box.
 *
 * Zero comes back empty rather than "0.00": a firm that has not priced
 * something yet is not selling it for nothing, and the list counts those
 * separately.
 */
export function leiFromCents(cents) {
    const n = Number(cents) || 0;

    return n > 0 ? (n / 100).toFixed(2) : '';
}

/**
 * What the person typed, as a decimal the server will accept.
 *
 * A Romanian keyboard and Romanian habit both produce "240,50"; a price copied
 * from a supplier's sheet produces "240.50". Both mean the same thing, and
 * Laravel's `numeric` rule rejects the comma — so the separator is normalised
 * here and the lei-to-bani conversion stays on the server, where there is
 * exactly one copy of it.
 *
 * Returns null for an empty box, which is not the same as zero.
 */
export function normaliseLei(input) {
    // Every separator, not the first: "1.234,5" went through a single
    // non-global replace as "1.234.5", previewed as 0 and was refused by the
    // server's numeric rule — taking the whole offer's save with it.
    const raw = String(input ?? '').trim().replace(/\s/g, '').replace(/\.(?=.*[.,])/g, '').replace(',', '.');

    return raw === '' ? null : raw;
}
