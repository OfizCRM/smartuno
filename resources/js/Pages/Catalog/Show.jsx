import { Head, Link, router, useForm } from '@inertiajs/react';
import { ArrowLeft, Layers, Package, Trash2 } from 'lucide-react';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { toast } from 'sonner';
import { Button, Input, ItemPicker, Modal, NudgeBanner, TagInput, Toggle } from '@/Components/ui';
import ClientLayout from '@/Layouts/ClientLayout';
import { formatLei, leiFromCents, normaliseLei } from '@/Utils/money';

/**
 * One catalogue item.
 *
 * Two things are edited here and they are saved separately, because they are
 * two different jobs: what the row *is* — name, code, price, stock — goes to
 * client.catalog.update, and what the agent needs to *know* about it — the
 * description, the two tag lists, what it goes with, the lowest price that is
 * still a sale, and, for an ansamblu, what is inside it — goes to
 * client.catalog.knowledge. A firm fixing a typo in a price should not have to
 * re-post a description, and an owner filling in tags should not restate the
 * stock level.
 *
 * Down the right-hand side is what the item has actually done: how often it was
 * proposed, how often that was accepted, what it sold this month, and which
 * ansambluri it sits inside. None of it is editable and none of it is
 * guaranteed to be there — see the note on the panel.
 *
 * Photos are still absent; they are not in this stage at all.
 *
 * Money is stored in bani. Both forms only ever speak lei: they seed from
 * *_cents / 100 and post a decimal string back, and the controller does the
 * conversion. Nothing on this page sends cents.
 */

/** An integer column that is allowed to be unset. 0 is a real stock level, null is not. */
function intOrBlank(value) {
    return value === null || value === undefined ? '' : String(value);
}

/**
 * A quantity as the box should show it.
 *
 * decimal(12,3) comes back from MySQL as "2.000", and in Romanian that reads as
 * two thousand. LineItemsEditor learnt this the same way and keeps its own copy
 * private on purpose — a component quantity and an offer line are not the same
 * number and must not start sharing a formatter that one of them later changes.
 */
function quantityText(value) {
    const raw = String(value ?? '').trim();

    return raw.includes('.') ? raw.replace(/\.?0+$/, '') : raw;
}

/**
 * The first message Laravel returned for a field or for any of its rows.
 *
 * A rejected tag comes back as "fits.0" and a rejected component as
 * "components.2.quantity"; a screen that only looks up "fits" shows nothing and
 * the person is left pressing a button that does not appear to do anything.
 * That is exactly how the Stage 2 screen failed.
 */
function errorFor(errors, field) {
    const key = Object.keys(errors).find(name => name === field || name.startsWith(`${field}.`));

    return key ? errors[key] : undefined;
}

/** A link row, as the two editors on this page hold it. */
function linkRow(link) {
    return {
        id: link.related_item_id,
        name: link.related_name ?? '',
        code: link.related_code ?? null,
        quantity: quantityText(link.quantity ?? 1) || '1',
    };
}

/** A picked catalogue row, in the same shape. The picker calls the code `sku`. */
function pickedRow(picked) {
    return { id: picked.id, name: picked.name, code: picked.sku ?? null, quantity: '1' };
}

const TEXTAREA = 'w-full rounded-xl border border-warm-border bg-white px-3 py-2 text-[13px] text-warm-gray-900 shadow-inner transition duration-150 placeholder:text-warm-gray-500 focus:border-brand-500 focus:outline-none focus:ring-2 focus:ring-brand-500/20 dark:border-neutral-600 dark:bg-neutral-800 dark:text-neutral-100 dark:placeholder:text-neutral-500';
const QTY = 'w-full rounded-lg border border-neutral-300 bg-white px-2 py-1.5 text-right text-sm tabular-nums text-neutral-900 transition focus:border-brand-500 focus:outline-none focus:ring-2 focus:ring-brand-500/20 dark:border-neutral-600 dark:bg-neutral-800 dark:text-neutral-100';
const CARD = 'rounded-2xl border border-neutral-200 bg-white p-5 dark:border-neutral-700 dark:bg-neutral-900';
const PANEL_TITLE = 'text-[11px] font-semibold uppercase tracking-wider text-neutral-400';

/**
 * A quantity or a percentage as a Romanian reads it: 12, 2,25, 7,4.
 *
 * Not quantityText() above, which exists to put a decimal(12,3) back into an
 * input box that will be posted — that one must keep the point the server
 * parses. This one is for text nobody types back, where "2.25" is read as two
 * hundred and twenty-five.
 */
const AMOUNT = new Intl.NumberFormat('ro-RO', { maximumFractionDigits: 3 });

/** One label and one number, on one line. Drawn only when there is a number. */
function Figure({ label, value }) {
    return (
        <div className="flex items-baseline justify-between gap-3">
            <span className="text-[13px] text-ink-muted dark:text-neutral-400">{label}</span>
            <span className="shrink-0 text-[13px] font-semibold tabular-nums text-neutral-900 dark:text-neutral-100">
                {value}
            </span>
        </div>
    );
}

/**
 * "Cum se vinde" — what this item has actually done on offers.
 *
 * The caller draws this ONLY when `sells` arrived. Null means the server has
 * not seen enough decided offers containing this item to divide by, and the
 * answer to that is an absent panel, never a zeroed one: every figure here is a
 * ratio, and "acceptat 0%" in week one describes the week, not the product. A
 * person cannot tell a real nought from an empty denominator, so they are not
 * shown one.
 *
 * The same rule applies one level down. A figure the server could not work out
 * comes back null and its line is missing too — there is no dash and no 0%
 * anywhere in this panel.
 *
 * "Vândut luna asta" is the one figure whose zero is honest — nothing was sold
 * this month — and it is still left out, because on the second of the month it
 * is always zero and reads as a collapse.
 */
function HowItSells({ sells, unit, t }) {
    const lines = [
        [t('catalog.proposed_in'), t('catalog.in_offers_count', { count: sells.proposed_count ?? 0 })],
    ];

    if (typeof sells.accepted_rate === 'number') {
        lines.push([
            t('catalog.accepted_in'),
            `${t('catalog.in_offers_count', { count: sells.accepted_count ?? 0 })} · ${sells.accepted_rate}%`,
        ]);
    }

    if (Number(sells.units_this_month ?? 0) > 0) {
        lines.push([
            t('catalog.sold_this_month'),
            `${AMOUNT.format(Number(sells.units_this_month))} ${unit} · ${formatLei(sells.revenue_cents_this_month ?? 0)}`,
        ]);
    }

    // Null when the firm never discounted this item, which is not the same as
    // discounting it by nothing — see OfferStats::discount().
    if (typeof sells.average_discount_percent === 'number') {
        // A real but tiny average must not print as a flat 0%: one four-percent
        // discount in a hundred sales is 0,04%, and "0%" would tell the firm it
        // never discounts at all. Below a tenth of a percent it says so instead.
        const pct = sells.average_discount_percent;
        lines.push([
            t('catalog.average_discount'),
            pct > 0 && pct < 0.1 ? t('catalog.under_a_tenth') : `${AMOUNT.format(pct)}%`,
        ]);
    }

    return (
        <div className={CARD}>
            <h2 className={PANEL_TITLE}>{t('catalog.how_it_sells')}</h2>
            <div className="mt-3 space-y-2">
                {lines.map(([label, value]) => <Figure key={label} label={label} value={value} />)}
            </div>
        </div>
    );
}

/**
 * "Apare în ansamblurile" — the kits this item is part of.
 *
 * A warning as much as a list: the price edited on this page is the price every
 * one of these quotes, and a firm that has forgotten a kit exists finds out
 * when the offer goes out. Each row links to the ansamblu itself.
 */
function AppearsInBundles({ bundles, t }) {
    return (
        <div className={CARD}>
            <h2 className={PANEL_TITLE}>{t('catalog.appears_in_bundles')}</h2>
            <ul className="mt-3 space-y-1">
                {bundles.map(bundle => (
                    <li key={bundle.uuid}>
                        <Link
                            href={route('client.catalog.show', bundle.uuid)}
                            className="flex items-center gap-2 rounded-lg px-2 py-1.5 -mx-2 text-[13px] text-neutral-700 transition hover:bg-neutral-50 dark:text-neutral-300 dark:hover:bg-neutral-800/60"
                        >
                            <Layers className="h-3.5 w-3.5 shrink-0 text-neutral-400" aria-hidden />
                            <span className="truncate">{bundle.name}</span>
                            <span className="ml-auto shrink-0 tabular-nums text-ink-muted dark:text-neutral-500">
                                ×{AMOUNT.format(Number(bundle.quantity ?? 1) || 1)}
                            </span>
                        </Link>
                    </li>
                ))}
            </ul>
        </div>
    );
}

export default function CatalogShow({
    item,
    categories = [],
    components = [],
    // Null until there are enough decided offers to divide by. Absent, not
    // zeroed — see HowItSells.
    sells = null,
    // The cross-sell correlation, null far more often than not.
    insight = null,
    bundles = [],
}) {
    const { t } = useTranslation();
    const [confirming, setConfirming] = useState(false);
    const [bundling, setBundling] = useState(false);

    const isBundle = item.type === 'bundle';
    const tags = item.tags ?? [];
    const links = item.links ?? [];

    const labelsOf = kind => tags.filter(tag => tag.kind === kind).map(tag => tag.label);

    // `components` is the bundle's own prop; if the controller folded them into
    // `links` instead, they are picked out of there. Either way this page never
    // shows a bundle_component among the cross-sell chips.
    const seedComponents = (components.length > 0 ? components : links.filter(l => l.kind === 'bundle_component'))
        .map(linkRow);

    const form = useForm({
        // Kept as it arrives. 'bundle' is not offered by the toggle below — an
        // ansamblu is made in the list, not by retyping a product's type — and
        // defaulting it to 'product' on load would quietly rewrite the row.
        type: item.type ?? 'product',
        name: item.name ?? '',
        code: item.code ?? '',
        category: item.category ?? '',
        unit: item.unit ?? 'buc',
        price: leiFromCents(item.price_cents),
        stock: intOrBlank(item.stock),
        low_stock_threshold: intOrBlank(item.low_stock_threshold),
        is_active: !! item.is_active,
    });

    const knowledge = useForm({
        description: item.description ?? '',
        min_price: leiFromCents(item.min_price_cents),
        fits: labelsOf('fits'),
        excludes: labelsOf('excludes'),
        cross_sell: links.filter(l => l.kind === 'cross_sell').map(linkRow),
        components: seedComponents,
    });

    const save = (e) => {
        e.preventDefault();
        // "240,50" is what a Romanian keyboard produces and what Laravel's
        // numeric rule refuses. Normalise the separator; lei stays the unit.
        form.transform(data => ({ ...data, price: normaliseLei(data.price) }));
        form.put(route('client.catalog.update', item.uuid), {
            preserveScroll: true,
            onSuccess: () => toast.success(t('catalog.saved')),
        });
    };

    const saveKnowledge = () => {
        knowledge.transform((data) => {
            const payload = {
                description: data.description.trim(),
                min_price: normaliseLei(data.min_price),
                fits: data.fits,
                excludes: data.excludes,
                // `links`, of {related_item_id}, is what knowledgeRules()
                // validates. Sent under any other name it is dropped by
                // validate() with no error at all — the save reported success
                // and the chips vanished on the next reload.
                links: data.cross_sell.map(row => ({ related_item_id: row.id })),
            };

            // A product never sends the key at all, so a controller that reads
            // an empty array as "delete every component" cannot be handed one by
            // a screen that never drew the editor.
            if (isBundle) {
                payload.components = data.components.map(row => ({
                    related_item_id: row.id,
                    quantity: normaliseLei(row.quantity) ?? '1',
                }));
            }

            return payload;
        });

        knowledge.put(route('client.catalog.knowledge', item.uuid), {
            preserveScroll: true,
            onSuccess: () => toast.success(t('catalog.knowledge_saved')),
            onError: (errors) => {
                // Every field below shows its own message, but a rule the screen
                // has no box for — a related id from another workspace, say —
                // would otherwise be a button that visibly does nothing. Stage 2
                // shipped that screen once already.
                const first = Object.values(errors)[0];
                if (first) toast.error(String(first));
            },
        });
    };

    const destroy = () => {
        router.delete(route('client.catalog.destroy', item.uuid), {
            onSuccess: () => toast.success(t('catalog.deleted')),
        });
    };

    /**
     * "Fă un ansamblu din ele" — the agent's observation, acted on.
     *
     * One press makes the kit and puts both items in it, then lands on the new
     * ansamblu so the person can name it properly and price it. It goes to
     * client.catalog.store and NOT to client.catalog.knowledge: the knowledge
     * endpoint replaces the components of an item that already exists and has
     * no way to bring one into being, so an ansamblu of two products that are
     * not themselves ansambluri cannot be expressed through it.
     *
     * The name is a placeholder built from the two items — data, not a sentence,
     * so it is not translated — and 255 is what the column and the rule take.
     *
     * `bundling` is the guard against a second press while the first is in the
     * air: NudgeBanner's action has no disabled state, and two presses would be
     * two kits.
     */
    const makeBundle = () => {
        if (! observation || bundling) return;

        setBundling(true);
        router.post(route('client.catalog.store'), {
            type: 'bundle',
            name: `${item.name} + ${observation.item.name}`.slice(0, 255),
            components: [
                { related_item_id: item.id, quantity: '1' },
                { related_item_id: observation.item.id, quantity: '1' },
            ],
        }, {
            // A component from another workspace, or a rule the sidebar has no
            // box for, would otherwise be a button that visibly does nothing.
            onError: (errors) => {
                const first = Object.values(errors)[0];
                if (first) toast.error(String(first));
            },
            onFinish: () => setBundling(false),
        });
    };

    // A Romanian keyboard offers the comma as the decimal separator and the
    // browser will happily hand us "240,00", which fails `numeric` server-side.
    const setPrice = (value) => form.setData('price', value.replace(',', '.'));

    /**
     * The knowledge fields sit inside the base form's <form> element — the
     * mockup puts the minimum price in the price card, and a form cannot be
     * nested in another. Enter in one of them would therefore submit the form
     * that wraps them and save the *other* card. It does nothing instead: this
     * panel saves on its own button, deliberately. A textarea keeps its newline
     * and a button keeps its activation.
     */
    const holdEnter = (e) => {
        const tag = e.target.tagName;
        if (e.key === 'Enter' && tag !== 'TEXTAREA' && tag !== 'BUTTON') e.preventDefault();
    };

    const addCrossSell = (picked) => {
        // Already on the list: nothing to do, and the chip is right there.
        if (knowledge.data.cross_sell.some(row => row.id === picked.id)) return;
        knowledge.setData('cross_sell', [...knowledge.data.cross_sell, pickedRow(picked)]);
    };

    const addComponent = (picked) => {
        const at = knowledge.data.components.findIndex(row => row.id === picked.id);

        // The same item twice is one row with a bigger number — that is what the
        // person meant, and (catalog_item_id, related_item_id, kind) is unique
        // so a second row could not be saved anyway.
        if (at !== -1) {
            const next = [...knowledge.data.components];
            next[at] = { ...next[at], quantity: String((Number(normaliseLei(next[at].quantity)) || 0) + 1) };
            knowledge.setData('components', next);

            return;
        }

        knowledge.setData('components', [...knowledge.data.components, pickedRow(picked)]);
    };

    const setComponentQuantity = (index, value) => {
        const next = [...knowledge.data.components];
        next[index] = { ...next[index], quantity: value };
        knowledge.setData('components', next);
    };

    const dropComponent = index => knowledge.setData(
        'components',
        knowledge.data.components.filter((_, i) => i !== index),
    );

    const knowledgeButton = (
        <Button
            size="sm"
            onClick={saveKnowledge}
            disabled={knowledge.processing || ! knowledge.isDirty}
            className="bg-brand-600 hover:bg-brand-700"
        >
            {knowledge.processing ? t('common.saving') : t('common.save')}
        </Button>
    );

    const subline = [item.code, item.category].filter(Boolean).join(' · ');

    /**
     * The cross-sell finding, but only when it is the finding the screen can
     * actually say.
     *
     * OfferStats reports the difference signed, on purpose: a companion that is
     * accepted LESS often is a real result and hiding the sign would turn this
     * panel into an advertisement. But there is one sentence for it —
     * "rata de acceptare urcă de la X% la Y%" — and one action, "fă un ansamblu
     * din ele", and neither is true of a companion that sells worse together.
     * Printing the sentence anyway would be a lie with a number in it, so a
     * negative or flat difference draws nothing at all.
     *
     * That does lose a finding worth having ("nu le propune împreună"). It needs
     * its own sentence and its own action, not this one bent backwards.
     */
    const observation = insight && insight.difference > 0 ? insight : null;

    /**
     * Is there anything to put down the right-hand side?
     *
     * On a new catalogue there is not — no offers, no ansambluri, no
     * correlation — and the page stays the single narrow column it has always
     * been rather than becoming a wide one with an empty half. The sidebar
     * arrives when the firm has done enough for it to say something.
     */
    const hasSidebar = !! sells || !! observation || bundles.length > 0;

    return (
        <ClientLayout title={item.name}>
            <Head title={item.name} />

            <div className={hasSidebar ? 'mx-auto max-w-6xl' : 'mx-auto max-w-3xl'}>
                <Link
                    href={route('client.catalog.index')}
                    className="inline-flex items-center gap-1.5 text-sm text-ink-muted transition hover:text-neutral-800 dark:hover:text-neutral-200"
                >
                    <ArrowLeft className="h-4 w-4" /> {t('catalog.back')}
                </Link>

                <div className={`mt-4 ${hasSidebar ? 'grid gap-4 lg:grid-cols-[minmax(0,1fr)_20rem] lg:items-start' : ''}`}>
                    <form onSubmit={save} className="space-y-4">
                        {/* Header */}
                        <div className="flex flex-wrap items-start justify-between gap-3">
                            <div className="min-w-0">
                                <h1 className="truncate text-2xl font-bold text-neutral-900 dark:text-white">{item.name}</h1>
                                {subline && (
                                    <p className="mt-0.5 truncate text-sm text-ink-muted dark:text-neutral-400">{subline}</p>
                                )}
                            </div>
                            <Button type="submit" disabled={form.processing} className="bg-brand-600 hover:bg-brand-700">
                                {form.processing ? t('common.saving') : t('common.save')}
                            </Button>
                        </div>

                        {/* ── Informații de bază ── */}
                        <div className={CARD}>
                            <h2 className="text-base font-semibold text-neutral-900 dark:text-neutral-100">{t('catalog.basic_info')}</h2>

                            <div className="mt-4 grid gap-4 sm:grid-cols-2">
                                <div className="sm:col-span-2">
                                    <Input
                                        label={t('catalog.name')}
                                        value={form.data.name}
                                        error={form.errors.name}
                                        onChange={e => form.setData('name', e.target.value)}
                                    />
                                </div>

                                <Input
                                    label={t('catalog.code')}
                                    value={form.data.code}
                                    error={form.errors.code}
                                    onChange={e => form.setData('code', e.target.value)}
                                />

                                {/* Free text, but the workspace's own categories are offered as
                                    you type so nobody ends up with "Servicii" and "servicii". */}
                                <div>
                                    <Input
                                        label={t('catalog.category')}
                                        value={form.data.category}
                                        error={form.errors.category}
                                        list="catalog-category-options"
                                        onChange={e => form.setData('category', e.target.value)}
                                    />
                                    <datalist id="catalog-category-options">
                                        {categories.map(name => <option key={name} value={name} />)}
                                    </datalist>
                                </div>

                                <Input
                                    label={t('catalog.unit')}
                                    value={form.data.unit}
                                    error={form.errors.unit}
                                    placeholder="buc"
                                    onChange={e => form.setData('unit', e.target.value)}
                                />

                                {/* The toggle offers product and service only, so an ansamblu
                                    does not get one: a stray click on "Produs" would turn a
                                    bundle into a plain row and leave its components behind,
                                    unreachable. Converting one is a delete and a retype. */}
                                {! isBundle && (
                                    <div>
                                        <span className="mb-1.5 block text-sm font-medium text-neutral-700 dark:text-neutral-300">
                                            {t('catalog.type')}
                                        </span>
                                        <div className="inline-flex rounded-xl border border-warm-border p-0.5 dark:border-neutral-600">
                                            {[
                                                ['product', t('catalog.type_product')],
                                                ['service', t('catalog.type_service')],
                                                ['bundle', t('catalog.type_bundle')],
                                            ].map(([value, label]) => (
                                                <button
                                                    key={value}
                                                    type="button"
                                                    onClick={() => form.setData('type', value)}
                                                    className={`rounded-[10px] px-3.5 py-1.5 text-[13px] font-medium transition ${
                                                        form.data.type === value
                                                            ? 'bg-brand-600 text-white'
                                                            : 'text-neutral-600 hover:bg-neutral-100 dark:text-neutral-300 dark:hover:bg-neutral-800'
                                                    }`}
                                                >
                                                    {label}
                                                </button>
                                            ))}
                                        </div>
                                        {form.errors.type && (
                                            <p className="mt-1.5 text-sm text-coral-600 dark:text-coral-400">{form.errors.type}</p>
                                        )}
                                    </div>
                                )}
                            </div>
                        </div>

                        {/* ── Ce trebuie să știe agentul ── */}
                        <div className={CARD} onKeyDown={holdEnter}>
                            <h2 className="text-base font-semibold text-neutral-900 dark:text-neutral-100">{t('catalog.agent_panel')}</h2>
                            <p className="mt-0.5 text-sm text-ink-muted dark:text-neutral-400">{t('catalog.agent_panel_hint')}</p>

                            {/* Descrierea */}
                            <div className="mt-4">
                                <label htmlFor="catalog-description" className="mb-1.5 block text-sm font-medium text-neutral-700 dark:text-neutral-300">
                                    {t('catalog.description')}
                                </label>
                                <textarea
                                    id="catalog-description"
                                    rows={4}
                                    value={knowledge.data.description}
                                    onChange={e => knowledge.setData('description', e.target.value)}
                                    className={TEXTAREA}
                                />
                                {errorFor(knowledge.errors, 'description') ? (
                                    <p className="mt-1.5 text-sm text-coral-600 dark:text-coral-400">
                                        {errorFor(knowledge.errors, 'description')}
                                    </p>
                                ) : (
                                    <p className="mt-1.5 text-sm text-ink-muted dark:text-neutral-400">{t('catalog.description_hint')}</p>
                                )}
                            </div>

                            {/* Pentru cine este / Nu îl propune dacă — one concept, opposite sign. */}
                            <div className="mt-5 grid gap-5 sm:grid-cols-2">
                                <div>
                                    <TagInput
                                        label={t('catalog.fits')}
                                        value={knowledge.data.fits}
                                        onChange={next => knowledge.setData('fits', next)}
                                        tone="positive"
                                        placeholder={t('catalog.add_tag')}
                                    />
                                    {errorFor(knowledge.errors, 'fits') ? (
                                        <p className="mt-1.5 text-sm text-coral-600 dark:text-coral-400">
                                            {errorFor(knowledge.errors, 'fits')}
                                        </p>
                                    ) : (
                                        <p className="mt-1.5 text-sm text-ink-muted dark:text-neutral-400">{t('catalog.fits_hint')}</p>
                                    )}
                                </div>

                                <div>
                                    <TagInput
                                        label={t('catalog.excludes')}
                                        value={knowledge.data.excludes}
                                        onChange={next => knowledge.setData('excludes', next)}
                                        tone="negative"
                                        placeholder={t('catalog.add_tag')}
                                    />
                                    {errorFor(knowledge.errors, 'excludes') ? (
                                        <p className="mt-1.5 text-sm text-coral-600 dark:text-coral-400">
                                            {errorFor(knowledge.errors, 'excludes')}
                                        </p>
                                    ) : (
                                        <p className="mt-1.5 text-sm text-ink-muted dark:text-neutral-400">{t('catalog.excludes_hint')}</p>
                                    )}
                                </div>
                            </div>

                            {/* Merge bine împreună cu — neutral chips on purpose: green and red
                                already mean "offer this" and "do not", and a third colour here
                                would read as a third verdict. */}
                            <div className="mt-5 border-t border-neutral-100 pt-4 dark:border-neutral-800">
                                <span className="mb-1.5 block text-sm font-medium text-neutral-700 dark:text-neutral-300">
                                    {t('catalog.goes_with')}
                                </span>

                                {knowledge.data.cross_sell.length > 0 && (
                                    <div className="mb-3 flex flex-wrap gap-2">
                                        {knowledge.data.cross_sell.map(row => (
                                            <span
                                                key={row.id}
                                                className="inline-flex max-w-full items-center gap-1.5 rounded-full border border-neutral-200 bg-neutral-50 px-2.5 py-1 text-xs font-medium text-neutral-700 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-200"
                                            >
                                                <Package className="h-3 w-3 shrink-0 text-neutral-400" aria-hidden />
                                                <span className="truncate">{row.name}</span>
                                                {row.code && <span className="shrink-0 text-neutral-400">{row.code}</span>}
                                                <button
                                                    type="button"
                                                    onClick={() => knowledge.setData(
                                                        'cross_sell',
                                                        knowledge.data.cross_sell.filter(x => x.id !== row.id),
                                                    )}
                                                    aria-label={`${t('catalog.remove')} — ${row.name}`}
                                                    className="shrink-0 text-neutral-400 transition hover:text-coral-600 dark:hover:text-coral-400"
                                                >
                                                    <Trash2 className="h-3 w-3" aria-hidden />
                                                </button>
                                            </span>
                                        ))}
                                    </div>
                                )}

                                <ItemPicker onPick={addCrossSell} placeholder={t('catalog.search_placeholder')} />

                                {errorFor(knowledge.errors, 'cross_sell') ? (
                                    <p className="mt-1.5 text-sm text-coral-600 dark:text-coral-400">
                                        {errorFor(knowledge.errors, 'cross_sell')}
                                    </p>
                                ) : (
                                    <p className="mt-1.5 text-sm text-ink-muted dark:text-neutral-400">{t('catalog.goes_with_hint')}</p>
                                )}
                            </div>

                            <div className="mt-5 flex justify-end border-t border-neutral-100 pt-4 dark:border-neutral-800">
                                {knowledgeButton}
                            </div>
                        </div>

                        {/* ── Compoziția ansamblului ── */}
                        {isBundle && (
                            <div className={CARD} onKeyDown={holdEnter}>
                                <h2 className="text-base font-semibold text-neutral-900 dark:text-neutral-100">{t('catalog.components')}</h2>
                                <p className="mt-0.5 text-sm text-ink-muted dark:text-neutral-400">{t('catalog.components_hint')}</p>

                                {knowledge.data.components.length > 0 && (
                                    <div className="mt-4 space-y-2">
                                        {knowledge.data.components.map((row, index) => {
                                            const rowError = errorFor(knowledge.errors, `components.${index}`);

                                            return (
                                                <div key={row.id}>
                                                    <div className="flex items-center gap-3 rounded-xl border border-neutral-200 px-3 py-2 dark:border-neutral-700">
                                                        <Package className="h-4 w-4 shrink-0 text-neutral-400" aria-hidden />
                                                        <div className="min-w-0 flex-1">
                                                            <p className="truncate text-sm font-medium text-neutral-800 dark:text-neutral-100">{row.name}</p>
                                                            {row.code && (
                                                                <p className="truncate text-xs text-ink-muted dark:text-neutral-400">{row.code}</p>
                                                            )}
                                                        </div>
                                                        <div className="w-24 shrink-0">
                                                            <label htmlFor={`component-qty-${row.id}`} className="sr-only">
                                                                {t('catalog.component_qty')}
                                                            </label>
                                                            <input
                                                                id={`component-qty-${row.id}`}
                                                                value={row.quantity}
                                                                inputMode="decimal"
                                                                onChange={e => setComponentQuantity(index, e.target.value)}
                                                                className={QTY}
                                                            />
                                                        </div>
                                                        <button
                                                            type="button"
                                                            onClick={() => dropComponent(index)}
                                                            aria-label={`${t('catalog.remove')} — ${row.name}`}
                                                            className="shrink-0 rounded-lg p-1.5 text-neutral-400 transition hover:bg-coral-50 hover:text-coral-600 dark:hover:bg-coral-900/20 dark:hover:text-coral-400"
                                                        >
                                                            <Trash2 className="h-4 w-4" aria-hidden />
                                                        </button>
                                                    </div>
                                                    {rowError && (
                                                        <p className="mt-1 text-sm text-coral-600 dark:text-coral-400">{rowError}</p>
                                                    )}
                                                </div>
                                            );
                                        })}
                                    </div>
                                )}

                                <div className="mt-3">
                                    <ItemPicker onPick={addComponent} placeholder={t('catalog.add_component')} />
                                </div>

                                {/* The exact key only. errorFor would also catch
                                    "components.0.quantity", which the row above already
                                    shows — the same complaint twice reads as two faults. */}
                                {knowledge.errors.components && (
                                    <p className="mt-1.5 text-sm text-coral-600 dark:text-coral-400">
                                        {knowledge.errors.components}
                                    </p>
                                )}

                                <div className="mt-5 flex justify-end border-t border-neutral-100 pt-4 dark:border-neutral-800">
                                    {knowledgeButton}
                                </div>
                            </div>
                        )}

                        {/* ── Preț și stoc ── */}
                        <div className={CARD}>
                            <h2 className="text-base font-semibold text-neutral-900 dark:text-neutral-100">{t('catalog.price_and_stock')}</h2>

                            <div className="mt-4 grid gap-4 sm:grid-cols-3">
                                <Input
                                    label={t('catalog.price')}
                                    value={form.data.price}
                                    error={form.errors.price}
                                    inputMode="decimal"
                                    placeholder="0.00"
                                    className="tabular-nums"
                                    onChange={e => setPrice(e.target.value)}
                                />

                                <Input
                                    label={t('catalog.stock')}
                                    type="number"
                                    min="0"
                                    step="1"
                                    value={form.data.stock}
                                    error={form.errors.stock}
                                    className="tabular-nums"
                                    onChange={e => form.setData('stock', e.target.value)}
                                />

                                <Input
                                    label={t('catalog.low_stock_threshold')}
                                    type="number"
                                    min="0"
                                    step="1"
                                    value={form.data.low_stock_threshold}
                                    error={form.errors.low_stock_threshold}
                                    className="tabular-nums"
                                    onChange={e => form.setData('low_stock_threshold', e.target.value)}
                                />
                            </div>

                            {/* The floor the agent may not go under. It sits with the price
                                because that is where a person looks for it, but it is part of
                                what the agent knows and saves with the panel above. */}
                            <div
                                className="mt-5 border-t border-neutral-100 pt-4 dark:border-neutral-800"
                                onKeyDown={holdEnter}
                            >
                                <div className="flex flex-wrap items-end justify-between gap-3">
                                    <div className="w-full sm:w-56">
                                        <Input
                                            label={t('catalog.min_price')}
                                            value={knowledge.data.min_price}
                                            error={errorFor(knowledge.errors, 'min_price')}
                                            hint={t('catalog.min_price_hint')}
                                            inputMode="decimal"
                                            placeholder="0.00"
                                            className="tabular-nums"
                                            onChange={e => knowledge.setData('min_price', e.target.value)}
                                        />
                                    </div>
                                    {knowledgeButton}
                                </div>
                            </div>

                            <div className="mt-5 border-t border-neutral-100 pt-4 dark:border-neutral-800">
                                <Toggle
                                    checked={form.data.is_active}
                                    onChange={value => form.setData('is_active', value)}
                                    label={t('common.active')}
                                />
                                <p className="mt-1.5 text-sm text-ink-muted dark:text-neutral-400">{t('catalog.active_hint')}</p>
                            </div>
                        </div>

                        <div className="flex flex-wrap items-center gap-3">
                            <Button type="submit" disabled={form.processing} className="bg-brand-600 hover:bg-brand-700">
                                {form.processing ? t('common.saving') : t('common.save')}
                            </Button>
                            <button
                                type="button"
                                onClick={() => setConfirming(true)}
                                className="ml-auto inline-flex items-center gap-1.5 text-sm font-medium text-coral-600 transition hover:underline dark:text-coral-400"
                            >
                                <Trash2 className="h-4 w-4" /> {t('catalog.delete')}
                            </button>
                        </div>
                    </form>

                    {/* ── Cum se vinde ── */}
                    {hasSidebar && (
                        <aside className="space-y-4">
                            {sells && <HowItSells sells={sells} unit={item.unit ?? 'buc'} t={t} />}

                            {bundles.length > 0 && <AppearsInBundles bundles={bundles} t={t} />}

                            {/* The agent's observation. A NudgeBanner and not a
                                card of its own: it is exactly what that component
                                is for — the screen works, but it would work better
                                if one more thing were set up — and it comes with
                                the single action this needs. Brand and not amber,
                                because nothing here is a warning. */}
                            {observation && (
                                <NudgeBanner
                                    tone="brand"
                                    title={t('catalog.agent_observation', {
                                        item: observation.item.name,
                                        from: observation.accepted_rate_without,
                                        to: observation.accepted_rate_with,
                                    })}
                                    action={{ label: t('catalog.make_a_bundle'), onClick: makeBundle }}
                                />
                            )}
                        </aside>
                    )}
                </div>
            </div>

            <Modal show={confirming} onClose={() => setConfirming(false)} maxWidth="md">
                <div className="p-5">
                    <h3 className="text-base font-semibold text-neutral-900 dark:text-neutral-100">{t('catalog.delete')}</h3>
                    <p className="mt-2 text-sm text-neutral-600 dark:text-neutral-400">{t('catalog.delete_confirm')}</p>
                    <div className="mt-5 flex justify-end gap-2">
                        <Button variant="ghost" onClick={() => setConfirming(false)}>{t('common.cancel')}</Button>
                        <Button variant="danger" onClick={destroy}>{t('common.delete')}</Button>
                    </div>
                </div>
            </Modal>
        </ClientLayout>
    );
}
