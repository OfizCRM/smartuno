import { Head, router, useForm, usePage } from '@inertiajs/react';
import { ChevronRight, Package, Plus, Search, Upload } from 'lucide-react';
import { useEffect, useMemo, useState } from 'react';
import { useTranslation } from 'react-i18next';
import EmptyState from '@/Components/EmptyState';
import { Button, Input, Modal, NudgeBanner, Pagination, Select } from '@/Components/ui';
import ClientLayout from '@/Layouts/ClientLayout';
import { formatLei, normaliseLei } from '@/Utils/money';
import AiDescribeModal from './AiDescribeModal';

/**
 * The catalogue — what the firm sells, written by hand.
 *
 * Deliberately not the ecommerce product list: that one mirrors a connected
 * shop and is overwritten on every sync. This is the list a dental clinic or a
 * plumber keeps themselves, and it is the source the inbox "share product"
 * button reads for firms that have no webshop at all.
 *
 * Every number on this screen arrives as a prop. The counts and the stats are
 * of the whole workspace, not of the filtered page: the tabs are how you
 * narrow, so a number that shrank as you typed would describe a list you had
 * already left. That is the same rule the contact chips follow.
 */

/** Blank means "not set", which is not the same as zero. */
function toIntOrNull(input) {
    const raw = String(input ?? '').trim();
    if (raw === '') return null;
    const value = Number(raw);

    return Number.isFinite(value) ? Math.trunc(value) : null;
}

/**
 * The tabs above the list. Keys match CatalogItem::TYPES.
 *
 * An ansamblu is not a fourth kind of thing sold alongside the other two — it is
 * a catalogue item whose components are links to other rows — but it is browsed
 * separately, because "trusa completa" and the eight parts inside it in one flat
 * list is how a price list stops being readable.
 */
const TYPE_TABS = [
    { key: null, labelKey: 'catalog.tab_all', countKey: 'all' },
    { key: 'product', labelKey: 'catalog.tab_products', countKey: 'product' },
    { key: 'service', labelKey: 'catalog.tab_services', countKey: 'service' },
    { key: 'bundle', labelKey: 'catalog.tab_bundles', countKey: 'bundle' },
];

/** What one press of "completează cu AI" is allowed to send. Matches the endpoint's cap. */
const AI_BATCH = 10;

/**
 * Is this row one of the ones the nudge is counting?
 *
 * The server knows the real answer — it counts items with neither a description
 * nor a tag — and if it ever puts that per row, that is what is used. Until then
 * the missing description is the proxy, which is the half of the test the list
 * prop can actually see. Getting it slightly wide is harmless: every proposal
 * still goes past a human before anything is written.
 */
/**
 * Whether the agent has enough to go on.
 *
 * The list row carries whether there IS a description and how many tags there
 * are, not the text of either: the screen never shows them, and shipping a page
 * of prose into a paginated prop would be a page nobody reads.
 */
function looksIncomplete(item) {
    return ! item.has_description && Number(item.tag_count ?? 0) === 0;
}

/**
 * One headline number.
 *
 * The tint only appears when there is something to look at: a red 0 under
 * "fără preț" would report a problem that does not exist, and four coloured
 * tiles on an empty catalogue say the business is on fire on day one.
 */
function CatalogStat({ label, value, hint, tone = 'neutral' }) {
    const tones = {
        neutral: 'text-neutral-900 dark:text-neutral-100',
        brand: 'text-brand-700 dark:text-brand-300',
        accent: 'text-accent-700 dark:text-accent-300',
        coral: 'text-coral-700 dark:text-coral-300',
    };
    const colour = value > 0 ? (tones[tone] ?? tones.neutral) : tones.neutral;

    return (
        <div className="rounded-xl border border-neutral-200 bg-white p-4 dark:border-neutral-700 dark:bg-neutral-900">
            <p className="text-xs font-medium text-neutral-500 dark:text-neutral-400">{label}</p>
            <p className={`mt-1 text-2xl font-bold tabular-nums ${colour}`}>{value}</p>
            {hint && <p className="mt-0.5 text-[11px] text-neutral-400">{hint}</p>}
        </div>
    );
}

/**
 * The stock cell.
 *
 * Same three states as the shop's product list — none, out, low, fine — kept
 * because they are the right three, restyled onto our own scales. A service has
 * no stock at all and gets a dash rather than a zero, which would read as "sold
 * out" for something that cannot run out.
 */
function StockCell({ item, t }) {
    const qty = item.stock;
    if (qty === null || qty === undefined) {
        return <span className="text-sm text-neutral-300 dark:text-neutral-600">—</span>;
    }

    if (qty <= 0) {
        return (
            <span className="inline-flex items-center rounded-full bg-coral-50 px-2 py-0.5 text-[11px] font-semibold text-coral-700 dark:bg-coral-950/40 dark:text-coral-300">
                {t('catalog.out_of_stock')}
            </span>
        );
    }

    const threshold = item.low_stock_threshold;
    const low = threshold !== null && threshold !== undefined && qty <= threshold;

    return (
        <span
            aria-label={t('catalog.in_stock', { count: qty })}
            className={`inline-flex items-center rounded-full px-2 py-0.5 text-[11px] font-semibold tabular-nums ${
                low
                    ? 'bg-accent-50 text-accent-800 dark:bg-accent-900/30 dark:text-accent-200'
                    : 'bg-neutral-100 text-neutral-700 dark:bg-neutral-800 dark:text-neutral-300'
            }`}
        >
            {qty}
        </span>
    );
}

/** One line of the price list. The whole row is the link; nothing else in it is clickable. */
function ItemRow({ item, t }) {
    const open = () => router.visit(route('client.catalog.show', item.uuid));

    return (
        <tr
            onClick={open}
            className={`cursor-pointer border-b border-neutral-100 transition-colors last:border-0 hover:bg-neutral-50 dark:border-neutral-800 dark:hover:bg-neutral-800/40 ${
                item.is_active ? '' : 'opacity-60'
            }`}
        >
            <td className="px-4 py-3">
                <div className="flex items-center gap-2">
                    <p className="truncate text-sm font-medium text-neutral-900 dark:text-neutral-100">{item.name}</p>
                    {item.type === 'service' && (
                        <span className="shrink-0 rounded-full bg-brand-50 px-2 py-0.5 text-[10px] font-semibold text-brand-700 dark:bg-brand-900/30 dark:text-brand-300">
                            {t('catalog.type_service')}
                        </span>
                    )}
                </div>
                {item.code && <p className="mt-0.5 truncate text-xs text-ink-muted dark:text-neutral-400">{item.code}</p>}
            </td>

            <td className="px-4 py-3">
                {item.category
                    ? <span className="text-sm text-neutral-700 dark:text-neutral-300">{item.category}</span>
                    : <span className="text-sm text-neutral-300 dark:text-neutral-600">—</span>}
            </td>

            {/* A missing price is named, not left blank: it is the one thing on
                this screen you cannot send to a customer. */}
            <td className="px-4 py-3">
                {item.price_cents > 0 ? (
                    <span className="text-sm font-semibold tabular-nums text-neutral-900 dark:text-neutral-100">
                        {formatLei(item.price_cents)}
                    </span>
                ) : (
                    <span className="text-sm text-accent-700 dark:text-accent-300">{t('catalog.no_price')}</span>
                )}
            </td>

            <td className="px-4 py-3 text-sm text-ink-muted dark:text-neutral-400">{item.unit}</td>

            <td className="px-4 py-3"><StockCell item={item} t={t} /></td>

            {/* How often this has actually been quoted. A count, not a rate, so
                it is not behind the minimum-sample gate the item page uses: one
                offer is one offer, and there is nothing to divide by.

                Zero is written out as "niciodată" and set in the muted grey the
                rest of this table uses for "nothing here". A 0 in the same
                column as 14 reads as a score. */}
            <td className="px-4 py-3">
                {(item.offer_count ?? 0) > 0 ? (
                    <span className="text-sm tabular-nums text-neutral-700 dark:text-neutral-300">
                        {t('catalog.in_offers_count', { count: item.offer_count })}
                    </span>
                ) : (
                    <span className="text-sm text-neutral-300 dark:text-neutral-600">
                        {t('catalog.never_offered')}
                    </span>
                )}
            </td>

            <td className="w-10 px-4 py-3 text-right">
                <ChevronRight className="ml-auto h-4 w-4 text-neutral-300 dark:text-neutral-600" aria-hidden />
            </td>
        </tr>
    );
}

/** Bring a price list in from a spreadsheet. One file, one field, no mapping wizard. */
function ImportModal({ onClose }) {
    const { t } = useTranslation();
    const [file, setFile] = useState(null);
    const [busy, setBusy] = useState(false);

    const submit = (e) => {
        e.preventDefault();
        if (!file) return;

        const data = new FormData();
        data.append('file', file);

        setBusy(true);
        router.post(route('client.catalog.import'), data, {
            forceFormData: true,
            preserveScroll: true,
            onSuccess: onClose,
            onFinish: () => setBusy(false),
        });
    };

    return (
        <Modal show onClose={onClose} maxWidth="md">
            <form onSubmit={submit}>
                <Modal.Header title={t('catalog.import')} onClose={onClose} />
                <Modal.Body className="space-y-4">
                    <p className="text-sm text-neutral-600 dark:text-neutral-400">{t('catalog.import_hint')}</p>

                    <div>
                        <span className="mb-1.5 block text-sm font-medium text-neutral-700 dark:text-neutral-300">
                            {t('catalog.import_file')}
                        </span>
                        <input
                            type="file"
                            accept=".csv,text/csv"
                            onChange={e => setFile(e.target.files?.[0] ?? null)}
                            className="w-full rounded-xl border border-warm-border bg-white px-3 py-2 text-[13px] text-warm-gray-900 file:mr-3 file:rounded-lg file:border-0 file:bg-neutral-100 file:px-3 file:py-1.5 file:text-[13px] file:font-medium file:text-neutral-700 dark:border-neutral-600 dark:bg-neutral-800 dark:text-neutral-100 dark:file:bg-neutral-700 dark:file:text-neutral-200"
                        />
                    </div>
                </Modal.Body>
                <Modal.Footer>
                    <Button variant="ghost" onClick={onClose}>{t('catalog.cancel')}</Button>
                    <Button type="submit" disabled={!file || busy}>{t('catalog.import')}</Button>
                </Modal.Footer>
            </form>
        </Modal>
    );
}

/**
 * Adding one item by hand.
 *
 * Nine fields, all on one screen, because a plumber adding "Deblocare scurgere"
 * should not be walked through a wizard. Price is typed the way it is spoken —
 * "240" or "240,50" — and converted to bani on the way out, so the server only
 * ever sees the integer it stores.
 */
function CreateModal({ categories, onClose }) {
    const { t } = useTranslation();
    const { data, setData, post, processing, errors, reset, transform } = useForm({
        type: 'product',
        name: '',
        code: '',
        category: '',
        unit: 'buc',
        price: '',
        stock: '',
        low_stock_threshold: '5',
        is_active: true,
    });

    // The form speaks lei and blank boxes. It sends `price` — the same key the
    // item page sends and the same key the controller validates — and the
    // lei-to-bani conversion happens once, on the server. Sending `price_cents`
    // from here is stripped by validation and every new item lands at 0.
    transform(({ price, ...rest }) => ({
        ...rest,
        name: rest.name.trim(),
        code: rest.code.trim() || null,
        category: rest.category.trim() || null,
        unit: rest.unit.trim() || 'buc',
        price: normaliseLei(price),
        stock: rest.type === 'service' ? null : toIntOrNull(rest.stock),
        low_stock_threshold: rest.type === 'service' ? null : toIntOrNull(rest.low_stock_threshold),
    }));

    const submit = (e) => {
        e.preventDefault();
        if (!data.name.trim()) return;

        post(route('client.catalog.store'), {
            preserveScroll: true,
            onSuccess: () => { reset(); onClose(); },
        });
    };

    return (
        <Modal show onClose={onClose} maxWidth="lg">
            <form onSubmit={submit}>
                <Modal.Header title={t('catalog.new_item')} onClose={onClose} />
                <Modal.Body className="space-y-4">
                    <Select
                        label={t('catalog.type')}
                        value={data.type}
                        onChange={e => setData('type', e.target.value)}
                        placeholder={null}
                        error={errors.type}
                        options={[
                            { value: 'product', label: t('catalog.type_product') },
                            { value: 'service', label: t('catalog.type_service') },
                            { value: 'bundle', label: t('catalog.type_bundle') },
                        ]}
                    />

                    <Input
                        label={t('catalog.name')}
                        value={data.name}
                        onChange={e => setData('name', e.target.value)}
                        error={errors.name}
                        autoFocus
                    />

                    <div className="grid gap-4 sm:grid-cols-2">
                        <Input
                            label={t('catalog.code')}
                            value={data.code}
                            onChange={e => setData('code', e.target.value)}
                            error={errors.code}
                        />

                        {/* Free text, but the categories already in use are offered:
                            two spellings of "Consumabile" split the list in half. */}
                        <div>
                            <Input
                                label={t('catalog.category')}
                                list="catalog-categories"
                                value={data.category}
                                onChange={e => setData('category', e.target.value)}
                                error={errors.category}
                            />
                            <datalist id="catalog-categories">
                                {categories.map(c => <option key={c} value={c} />)}
                            </datalist>
                        </div>
                    </div>

                    <div className="grid gap-4 sm:grid-cols-2">
                        <Input
                            label={t('catalog.price')}
                            inputMode="decimal"
                            placeholder="0"
                            value={data.price}
                            onChange={e => setData('price', e.target.value)}
                            error={errors.price}
                        />
                        <Input
                            label={t('catalog.unit')}
                            value={data.unit}
                            onChange={e => setData('unit', e.target.value)}
                            error={errors.unit}
                        />
                    </div>

                    {/* Stock is meaningless for a service — an hour of consultancy
                        does not run out — so the fields are not offered for one. */}
                    {data.type === 'product' && (
                        <div className="grid gap-4 sm:grid-cols-2">
                            <Input
                                label={t('catalog.stock')}
                                inputMode="numeric"
                                value={data.stock}
                                onChange={e => setData('stock', e.target.value)}
                                error={errors.stock}
                            />
                            <Input
                                label={t('catalog.low_stock_threshold')}
                                inputMode="numeric"
                                value={data.low_stock_threshold}
                                onChange={e => setData('low_stock_threshold', e.target.value)}
                                error={errors.low_stock_threshold}
                            />
                        </div>
                    )}

                    <label className="flex items-center gap-2 text-sm text-neutral-700 dark:text-neutral-300">
                        <input
                            type="checkbox"
                            checked={data.is_active}
                            onChange={e => setData('is_active', e.target.checked)}
                            className="h-4 w-4 rounded border-neutral-300 text-brand-600 focus:ring-brand-500/20 dark:border-neutral-600 dark:bg-neutral-800"
                        />
                        {t('catalog.is_active')}
                    </label>
                </Modal.Body>
                <Modal.Footer>
                    <Button variant="ghost" onClick={onClose}>{t('catalog.cancel')}</Button>
                    <Button type="submit" disabled={processing || !data.name.trim()}>{t('catalog.save')}</Button>
                </Modal.Footer>
            </form>
        </Modal>
    );
}

export default function CatalogIndex({
    items,
    counts = {},
    stats = {},
    categories = [],
    filters = {},
}) {
    const { t } = useTranslation();
    const { props } = usePage();
    const flash = props.flash ?? {};

    const [search, setSearch] = useState(filters.search ?? '');
    const [showImport, setShowImport] = useState(false);
    const [showCreate, setShowCreate] = useState(false);
    const [showAi, setShowAi] = useState(false);

    // Held steady between renders so the AI batch below is not rebuilt — and
    // handed to the modal as a new array — on every keystroke in the search box.
    const rows = useMemo(() => items?.data ?? [], [items]);

    /**
     * The batch the AI helper is offered.
     *
     * Taken from the rows on screen rather than from the whole workspace: the
     * person can see what they are about to spend a call on, and the ten they
     * get are the ten in front of them. Anything without a numeric id is left
     * out — the endpoint takes ids, and a uuid would come back as a validation
     * error the person cannot act on.
     */
    const aiBatch = useMemo(() => rows
        .filter(item => item.is_active !== false && looksIncomplete(item))
        .map(item => item.id)
        .filter(id => Number.isInteger(id))
        .slice(0, AI_BATCH),
    [rows]);

    /**
     * The URL is the state. Everything the list shows can be linked to, sent to
     * a colleague and reloaded, and the back button undoes a filter rather than
     * leaving the page.
     */
    const go = (patch) => {
        const next = { ...filters, ...patch };
        Object.keys(next).forEach(key => {
            if (next[key] === null || next[key] === undefined || next[key] === '') delete next[key];
        });

        router.get(route('client.catalog.index'), next, {
            preserveState: true,
            preserveScroll: true,
            replace: true,
        });
    };

    // 350ms after the typing stops, not on every keystroke and not on Enter.
    useEffect(() => {
        const term = search.trim();
        if (term === (filters.search ?? '')) {
            return undefined;
        }
        const timer = setTimeout(() => go({ search: term || undefined }), 350);

        return () => clearTimeout(timer);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [search]);

    return (
        <ClientLayout title={t('catalog.title')}>
            <Head title={t('catalog.title')} />

            <div className="space-y-4">
                <div className="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <h1 className="text-2xl font-bold text-neutral-900 dark:text-white">{t('catalog.title')}</h1>
                        <p className="mt-1 text-sm text-ink-muted dark:text-neutral-400">{t('catalog.subtitle')}</p>
                    </div>

                    <div className="flex flex-wrap gap-2">
                        <button type="button" onClick={() => setShowImport(true)}
                            className="inline-flex items-center gap-1.5 rounded-xl border border-neutral-300 px-3 py-2 text-sm text-neutral-700 transition hover:bg-neutral-50 dark:border-neutral-600 dark:text-neutral-300 dark:hover:bg-neutral-800">
                            <Upload className="h-4 w-4" /> {t('catalog.import')}
                        </button>
                        <button type="button" onClick={() => setShowCreate(true)}
                            className="inline-flex items-center gap-1.5 rounded-xl bg-brand-600 px-4 py-2 text-sm font-semibold text-white transition hover:bg-brand-700">
                            <Plus className="h-4 w-4" /> {t('catalog.new_item')}
                        </button>
                    </div>
                </div>

                {flash.success && (
                    <div className="rounded-xl bg-brand-50 px-4 py-3 text-sm text-brand-800 dark:bg-brand-900/25 dark:text-brand-200">{flash.success}</div>
                )}
                {flash.error && (
                    <div className="rounded-xl border border-coral-200 bg-coral-50 px-4 py-3 text-sm text-coral-800 dark:border-coral-900/40 dark:bg-coral-900/20 dark:text-coral-300">{flash.error}</div>
                )}

                {/* Amber, above everything the person came here to read: the
                    catalogue works without descriptions, it just makes worse
                    offers. Gone the moment the number reaches zero. */}
                {(stats.needs_help ?? 0) > 0 && (
                    <NudgeBanner
                        tone="accent"
                        title={t('catalog.needs_help_title', { count: stats.needs_help })}
                        description={t('catalog.needs_help_desc')}
                        action={{ label: t('catalog.fill_with_ai'), onClick: () => setShowAi(true) }}
                    />
                )}

                <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                    <CatalogStat
                        label={t('catalog.stat_items')}
                        value={stats.total ?? 0}
                        hint={t('catalog.stat_categories', { count: stats.categories ?? 0 })}
                    />
                    <CatalogStat label={t('catalog.stat_services')} value={counts.service ?? 0} tone="brand" />
                    <CatalogStat label={t('catalog.stat_low_stock')} value={stats.low_stock ?? 0} tone="accent" />
                    <CatalogStat label={t('catalog.stat_no_price')} value={stats.no_price ?? 0} tone="coral" />
                </div>

                <div className="flex flex-wrap items-center gap-2">
                    {TYPE_TABS.map(({ key, labelKey, countKey }) => {
                        const active = (filters.type ?? null) === key;
                        const count = counts[countKey] ?? 0;

                        return (
                            <button key={key ?? 'all'} type="button" onClick={() => go({ type: key ?? undefined })}
                                className={`inline-flex items-center gap-1.5 rounded-full px-3 py-1.5 text-sm font-medium transition ${
                                    active
                                        ? 'bg-brand-600 text-white'
                                        : 'border border-neutral-200 bg-white text-neutral-600 hover:bg-neutral-50 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-300'
                                }`}>
                                {t(labelKey)}
                                {count > 0 && <span className="tabular-nums opacity-70">{count}</span>}
                            </button>
                        );
                    })}
                </div>

                <div className="flex flex-wrap items-center gap-2">
                    <div className="relative min-w-[200px] flex-1">
                        <Search className="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-neutral-400" />
                        <input value={search} onChange={e => setSearch(e.target.value)}
                            placeholder={t('catalog.search_placeholder')}
                            className="w-full rounded-xl border-neutral-300 py-2 pl-10 text-sm dark:border-neutral-600 dark:bg-neutral-800" />
                    </div>

                    <div className="w-full sm:w-48">
                        <Select
                            name="category"
                            aria-label={t('catalog.category')}
                            value={filters.category ?? ''}
                            onChange={e => go({ category: e.target.value || undefined })}
                            placeholder={t('catalog.category_all')}
                            options={categories}
                        />
                    </div>

                    <div className="w-full sm:w-44">
                        <Select
                            name="stock"
                            aria-label={t('catalog.col_stock')}
                            value={filters.stock ?? ''}
                            onChange={e => go({ stock: e.target.value || undefined })}
                            placeholder={t('catalog.stock_all')}
                            options={[
                                { value: 'low', label: t('catalog.stock_low') },
                                { value: 'out', label: t('catalog.stock_out') },
                            ]}
                        />
                    </div>
                </div>

                <div className="overflow-hidden rounded-2xl border border-neutral-200 bg-white dark:border-neutral-700 dark:bg-neutral-900">
                    {rows.length === 0 ? (
                        <EmptyState
                            icon={<Package className="h-7 w-7" />}
                            title={t('catalog.empty_title')}
                            description={t('catalog.empty_desc')}
                            action={{ label: t('catalog.new_item'), onClick: () => setShowCreate(true) }}
                            secondaryAction={{ label: t('catalog.import'), onClick: () => setShowImport(true) }}
                        />
                    ) : (
                        <>
                            <div className="overflow-x-auto">
                                <table className="w-full">
                                    <thead>
                                        <tr className="border-b border-neutral-200 text-left text-[11px] font-semibold uppercase tracking-wider text-neutral-400 dark:border-neutral-700">
                                            <th className="px-4 py-3">{t('catalog.col_item')}</th>
                                            <th className="px-4 py-3">{t('catalog.col_category')}</th>
                                            <th className="px-4 py-3">{t('catalog.col_price')}</th>
                                            <th className="px-4 py-3">{t('catalog.col_unit')}</th>
                                            <th className="px-4 py-3">{t('catalog.col_stock')}</th>
                                            <th className="px-4 py-3">{t('catalog.col_in_offers')}</th>
                                            <th className="w-10 px-4 py-3" />
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {rows.map(item => <ItemRow key={item.uuid} item={item} t={t} />)}
                                    </tbody>
                                </table>
                            </div>

                            <Pagination data={items} />
                        </>
                    )}
                </div>
            </div>

            {showImport && <ImportModal onClose={() => setShowImport(false)} />}
            {showCreate && <CreateModal categories={categories} onClose={() => setShowCreate(false)} />}
            {/* Mounted only while open, so closing it ends the call rather than
                leaving a request in flight behind a hidden dialog. */}
            {showAi && <AiDescribeModal itemIds={aiBatch} onClose={() => setShowAi(false)} />}
        </ClientLayout>
    );
}
