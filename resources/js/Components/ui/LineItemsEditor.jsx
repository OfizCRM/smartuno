import { Plus, Trash2 } from 'lucide-react';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { formatLei, leiFromCents, normaliseLei } from '@/Utils/money';

/**
 * The lines of an offer: what is being sold, how many, at what price.
 *
 * Everything in here is bani. A price is typed in lei because that is what a
 * person writes on an offer, and it becomes an integer before it leaves the
 * input — nothing in this file multiplies two decimals together, because
 * 24.05 * 100 is 2404.999... and an offer that is one ban short of its own
 * total is the kind of thing a customer notices and a firm cannot explain.
 *
 * The totals shown here are a preview. The server recomputes every one of them
 * from the same integers on save, and its answer wins.
 */

/** For a workspace that prices in something other than lei. */
const OTHER_CURRENCY = new Intl.NumberFormat('ro-RO', { minimumFractionDigits: 2, maximumFractionDigits: 2 });

/**
 * What the person typed, in bani, by string surgery rather than arithmetic.
 *
 * `@/Utils/money` deliberately has no lei-to-bani function: the catalogue posts
 * the decimal and lets `Catalog\Support\Money::bani()` be the only copy of that
 * rule. An offer line cannot wait for the server — the total next to it has to
 * move as the price is typed — so this reproduces that one rule, digit for
 * digit, and stays private to this file so it can never be mistaken for a
 * second public converter.
 *
 * The rule, from Money::bani: the last separator is the decimal point and any
 * earlier ones group thousands, so "1.234,50", "1,234.50" and "1234.5" all mean
 * 123450 bani. Fractions round rather than truncate, and a negative reads as
 * zero because the column is unsigned.
 */
function baniFromLei(text) {
    const decimal = normaliseLei(text);
    if (decimal === null || decimal.startsWith('-')) return 0;

    const digits = decimal.replace(/[^0-9.]/g, '');
    const cut = digits.lastIndexOf('.');
    const whole = (cut === -1 ? digits : digits.slice(0, cut)).replace(/\./g, '');
    const frac = cut === -1 ? '' : digits.slice(cut + 1);

    // Three digits kept, then rounded to two — "0.239" is 24 bani, not 23.
    return (Number(whole || '0') * 100) + Math.round(Number(`${frac}000`.slice(0, 3)) / 10);
}

/**
 * A quantity as the box should show it.
 *
 * MySQL hands back decimal(12,3) as "1.000", and in Romanian that reads as one
 * thousand. The trailing zeros go; the dot only counts as a decimal point when
 * there is one, so "10" is left alone.
 */
function quantityText(value) {
    const raw = String(value ?? '').trim();

    return raw.includes('.') ? raw.replace(/\.?0+$/, '') : raw;
}

const INPUT = 'w-full rounded-lg border border-neutral-300 bg-white px-2 py-1.5 text-right text-sm tabular-nums text-neutral-900 transition focus:border-brand-500 focus:outline-none focus:ring-2 focus:ring-brand-500/20 dark:border-neutral-600 dark:bg-neutral-800 dark:text-neutral-100';
const TH = 'px-3 py-2 text-left text-[11px] font-semibold uppercase tracking-wide text-neutral-500 dark:text-neutral-400';

/**
 * Props:
 *   items    – [{ key, name, unit, quantity, unit_price_cents, line_total_cents,
 *                catalog_item_id, added_by }]. `key` is the row's identity for
 *                React and for edits; it is not the database id, which a line
 *                that has never been saved does not have.
 *   onChange – called with the whole next array on every edit and every removal.
 *   onAdd    – called when the add-a-line button is pressed. Where the row comes
 *              from — the picker, a blank line — is the caller's business.
 *   currency – ISO code, for display only.
 *   readOnly – a sent offer is a record, not a draft.
 */
export default function LineItemsEditor({ items = [], onChange, onAdd, currency = 'RON', readOnly = false }) {
    const { t } = useTranslation();

    // While a box has the caret in it, what is in the box is what was typed —
    // "12," has to survive long enough to become "12,5". On blur the draft is
    // dropped and the row goes back to showing the value it actually holds.
    const [drafts, setDrafts] = useState({});
    const draftFor = (key, field, fallback) => drafts[`${key}:${field}`] ?? fallback;
    const putDraft = (key, field, value) => setDrafts(d => ({ ...d, [`${key}:${field}`]: value }));
    const dropDraft = (key, field) => setDrafts(d => {
        const next = { ...d };
        delete next[`${key}:${field}`];

        return next;
    });

    /** Three decimals is what decimal(12,3) keeps; anything finer is a lie on screen. */
    const clampQuantity = (raw) => {
        const value = normaliseLei(raw);

        if (value === null) return '';
        const n = Number(value);

        return Number.isFinite(n) ? String(Math.round(n * 1000) / 1000) : '';
    };

    const price = (cents) => (currency === 'RON'
        ? formatLei(cents)
        : `${OTHER_CURRENCY.format((Number(cents) || 0) / 100)} ${currency}`);

    /**
     * The same arithmetic the server does, so the cell and the subtotal under it
     * cannot disagree.
     *
     * The quantity is a decimal(12,3), so it is carried as thousandths and
     * multiplied as an integer: Math.round(0.705 * 2900) is 2044 in floating
     * point and 2045 the way the column is actually summed. Half a percent of
     * ordinary trade quantities land on that difference, and the customer sees
     * a line column that does not add up to the total printed beneath it.
     */
    const lineTotal = (item) => {
        const milli = Math.round((Number(item.quantity) || 0) * 1000);
        const cents = Number(item.unit_price_cents) || 0;

        return Math.floor((milli * cents + 500) / 1000);
    };

    const patch = (key, changes) => onChange?.(items.map((item) => {
        if (item.key !== key) return item;
        const next = { ...item, ...changes };

        return { ...next, line_total_cents: lineTotal(next) };
    }));

    const remove = (key) => onChange?.(items.filter(item => item.key !== key));

    return (
        <div className="overflow-hidden rounded-xl border border-neutral-200 bg-white dark:border-neutral-700 dark:bg-neutral-900">
            {items.length === 0 ? (
                <p className="px-4 py-8 text-center text-sm text-neutral-500 dark:text-neutral-400">
                    {t('offers.no_items')}
                </p>
            ) : (
                <div className="overflow-x-auto">
                    <table className="w-full">
                        <thead className="border-b border-neutral-100 dark:border-neutral-800">
                            <tr>
                                <th scope="col" className={TH}>{t('offers.item')}</th>
                                <th scope="col" className={`${TH} w-24 text-right`}>{t('offers.qty')}</th>
                                <th scope="col" className={`${TH} w-32 text-right`}>{t('offers.unit_price')}</th>
                                <th scope="col" className={`${TH} w-32 text-right`}>{t('offers.line_total')}</th>
                                {!readOnly && <th scope="col" className="w-10 px-3 py-2"><span className="sr-only">{t('offers.remove_line')}</span></th>}
                            </tr>
                        </thead>
                        <tbody>
                            {items.map(item => (
                                <tr key={item.key} className="border-b border-neutral-100 last:border-0 dark:border-neutral-800">
                                    <td className="px-3 py-2">
                                        {readOnly || item.catalog_item_id ? (
                                            <p className="text-sm font-medium text-neutral-900 dark:text-neutral-100">{item.name}</p>
                                        ) : (
                                            /* A free line has no catalogue row to take its name from, and
                                               without an input here it could never be given one — the save
                                               was refused for an empty name and the whole offer with it. */
                                            <input
                                                aria-label={t('offers.item')}
                                                placeholder={t('offers.line_name_placeholder')}
                                                value={item.name ?? ''}
                                                onChange={(e) => patch(item.key, { name: e.target.value })}
                                                className={`${INPUT} text-left font-medium`}
                                            />
                                        )}
                                        {readOnly || item.catalog_item_id ? (
                                            item.unit && (
                                                <p className="mt-0.5 text-xs text-neutral-500 dark:text-neutral-400">{item.unit}</p>
                                            )
                                        ) : (
                                            <input
                                                aria-label={t('offers.unit')}
                                                placeholder={t('offers.unit')}
                                                value={item.unit ?? ''}
                                                onChange={(e) => patch(item.key, { unit: e.target.value })}
                                                className={`${INPUT} mt-1 text-left text-xs`}
                                            />
                                        )}
                                    </td>

                                    <td className="px-3 py-2 text-right">
                                        {readOnly ? (
                                            <span className="text-sm tabular-nums text-neutral-700 dark:text-neutral-300">
                                                {quantityText(item.quantity)}
                                            </span>
                                        ) : (
                                            <input
                                                inputMode="decimal"
                                                aria-label={t('offers.qty')}
                                                value={draftFor(item.key, 'quantity', quantityText(item.quantity))}
                                                onChange={(e) => {
                                                    putDraft(item.key, 'quantity', e.target.value);
                                                    // Clamped here as well as on the server: the row
                                                    // showed 123,45 while the save quietly stored
                                                    // 123,50, and nobody was told.
                                                    patch(item.key, { quantity: clampQuantity(e.target.value) });
                                                }}
                                                onBlur={() => dropDraft(item.key, 'quantity')}
                                                className={INPUT}
                                            />
                                        )}
                                    </td>

                                    <td className="px-3 py-2 text-right">
                                        {readOnly ? (
                                            <span className="text-sm tabular-nums text-neutral-700 dark:text-neutral-300">
                                                {price(item.unit_price_cents)}
                                            </span>
                                        ) : (
                                            <input
                                                inputMode="decimal"
                                                aria-label={t('offers.unit_price')}
                                                value={draftFor(item.key, 'unit_price', leiFromCents(item.unit_price_cents))}
                                                onChange={(e) => {
                                                    putDraft(item.key, 'unit_price', e.target.value);
                                                    patch(item.key, { unit_price_cents: baniFromLei(e.target.value) });
                                                }}
                                                onBlur={() => dropDraft(item.key, 'unit_price')}
                                                className={INPUT}
                                            />
                                        )}
                                    </td>

                                    <td className="px-3 py-2 text-right text-sm font-semibold tabular-nums text-neutral-900 dark:text-neutral-100">
                                        {price(lineTotal(item))}
                                    </td>

                                    {!readOnly && (
                                        <td className="px-3 py-2 text-right">
                                            <button
                                                type="button"
                                                onClick={() => remove(item.key)}
                                                aria-label={t('offers.remove_line')}
                                                title={t('offers.remove_line')}
                                                className="rounded-lg p-1.5 text-neutral-400 transition hover:bg-coral-50 hover:text-coral-600 dark:hover:bg-coral-900/20 dark:hover:text-coral-400"
                                            >
                                                <Trash2 className="h-4 w-4" aria-hidden />
                                            </button>
                                        </td>
                                    )}
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            )}

            {!readOnly && onAdd && (
                <button
                    type="button"
                    onClick={onAdd}
                    className="flex w-full items-center gap-2 border-t border-neutral-100 px-4 py-2.5 text-left text-sm font-medium text-brand-700 transition hover:bg-brand-50 dark:border-neutral-800 dark:text-brand-300 dark:hover:bg-brand-900/20"
                >
                    <Plus className="h-4 w-4 shrink-0" aria-hidden />
                    {t('offers.add_line')}
                </button>
            )}
        </div>
    );
}
