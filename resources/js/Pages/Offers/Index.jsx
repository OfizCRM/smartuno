import { Head, Link, router, usePage } from '@inertiajs/react';
import { ChevronRight, FileText, Plus, Search, Settings2, Sparkles } from 'lucide-react';
import { useEffect, useMemo, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { CHANNEL_LABELS } from '@/Components/BrandIcons';
import EmptyState from '@/Components/EmptyState';
import { FilterTabs, Pagination, Select } from '@/Components/ui';
import ClientLayout from '@/Layouts/ClientLayout';
import { formatLei } from '@/Utils/money';

/**
 * The offers list — what has been quoted, to whom, and whether it is still good.
 *
 * This screen replaces the folder of Word files a firm keeps its quotes in, so
 * it answers the three questions that folder cannot: how much is out there
 * waiting for an answer, which offers were accepted, and which ones have gone
 * past their date. Everything else is deliberately absent.
 *
 * As on the catalogue, the counts and the stats describe the whole workspace,
 * not the filtered page. The tabs are how you narrow; a number that shrank as
 * you typed would describe a list you had already left.
 */

/**
 * The one tab that is not a status: the drafts the agent prepared and nobody
 * has decided on yet — source = 'ai' AND status = 'draft'.
 *
 * It travels as its own filter key rather than as a `source` filter beside
 * `status`, because it is one bucket and not an axis: an AI offer that has been
 * sent is no longer a ciornă, so the two clauses are never chosen separately.
 * The server's contract, all of it:
 *
 *   filters.view       'ai_drafts' or absent. Anything else is dropped, the way
 *                      `status` and `channel` already are.
 *   counts.ai_drafts   how many the workspace holds, unaffected by the other
 *                      filters — same rule as every other tab count.
 *   stats.ai_drafts    the same number, and stats.ai_drafts_cents their total
 *                      value, for the banner.
 *
 * If the server does not answer with them, the banner stays hidden and the tab
 * is an inert pill. That is the intended failure: a wrong list would be worse.
 */
const AI_VIEW = 'ai_drafts';

/**
 * The tabs above the list. The first five keys match Offer::STATUSES; null is
 * FilterTabs' "all". The AI bucket is last, after the status axis, so the strip
 * does not change what it means halfway along.
 */
const STATUS_TABS = [
    { key: null, labelKey: 'offers.tab_all', countKey: 'all' },
    { key: 'draft', labelKey: 'offers.tab_draft', countKey: 'draft' },
    { key: 'sent', labelKey: 'offers.tab_sent', countKey: 'sent' },
    { key: 'accepted', labelKey: 'offers.tab_accepted', countKey: 'accepted' },
    { key: 'refused', labelKey: 'offers.tab_refused', countKey: 'refused' },
    { key: AI_VIEW, labelKey: 'offers.tab_ai', countKey: 'ai_drafts' },
];

/**
 * The status badge.
 *
 * Green only for accepted: it is the single outcome worth celebrating, and
 * colouring a draft would tell someone that writing an offer is the same thing
 * as winning the work. Amber is "waiting on them", coral is a no.
 */
const STATUS_STYLES = {
    draft: 'bg-neutral-100 text-neutral-600 dark:bg-neutral-800 dark:text-neutral-300',
    sent: 'bg-accent-50 text-accent-800 dark:bg-accent-900/30 dark:text-accent-200',
    accepted: 'bg-brand-50 text-brand-700 dark:bg-brand-900/30 dark:text-brand-300',
    refused: 'bg-coral-50 text-coral-700 dark:bg-coral-950/40 dark:text-coral-300',
    expired: 'bg-neutral-100 text-neutral-500 dark:bg-neutral-800 dark:text-neutral-400',
};

const STATUS_LABEL_KEYS = {
    draft: 'offers.status_draft',
    sent: 'offers.status_sent',
    accepted: 'offers.status_accepted',
    refused: 'offers.status_refused',
    expired: 'offers.status_expired',
};

/** The statuses that are still waiting on the customer, and so can go stale. */
const PENDING = new Set(['sent', 'expired']);

/** How many months the period filter offers. A year of history is as far back as anyone looks. */
const PERIOD_MONTHS = 12;

const pad2 = (n) => String(n).padStart(2, '0');

/**
 * A date column, or a timestamp, as a local date.
 *
 * `valid_until` is a DATE and arrives as "2026-09-25"; `new Date()` would read
 * that as UTC midnight and show the day before to anyone west of Greenwich. So
 * date-only strings are built from their parts and stay local.
 */
function toLocalDate(iso) {
    if (!iso) return null;
    const parts = /^(\d{4})-(\d{2})-(\d{2})$/.exec(String(iso));
    const date = parts ? new Date(+parts[1], +parts[2] - 1, +parts[3]) : new Date(iso);

    return Number.isNaN(date.getTime()) ? null : date;
}

function formatDate(iso, locale) {
    const date = toLocalDate(iso);

    return date ? date.toLocaleDateString(locale, { day: 'numeric', month: 'short', year: 'numeric' }) : null;
}

/** Midnight today, so "expired" flips at the start of the day rather than at the hour the offer was written. */
function startOfToday() {
    const now = new Date();

    return new Date(now.getFullYear(), now.getMonth(), now.getDate());
}

/**
 * One headline number.
 *
 * `meta` is the second fact the tile carries — the acceptance rate, the number
 * of offers behind a sum — and it sits beside the label rather than under the
 * number, because that is where this app already puts a count next to a name.
 * The tint appears only when there is something to look at: four coloured tiles
 * on an empty list say the business is on fire on day one.
 */
function OfferStat({ label, value, meta, tone = 'neutral', positive = false }) {
    const tones = {
        neutral: 'text-neutral-900 dark:text-neutral-100',
        brand: 'text-brand-700 dark:text-brand-300',
        accent: 'text-accent-700 dark:text-accent-300',
        coral: 'text-coral-700 dark:text-coral-300',
    };
    const colour = positive ? (tones[tone] ?? tones.neutral) : tones.neutral;

    return (
        <div className="rounded-xl border border-neutral-200 bg-white p-4 dark:border-neutral-700 dark:bg-neutral-900">
            <p className="flex items-center gap-1.5 text-xs font-medium text-neutral-500 dark:text-neutral-400">
                <span className="truncate">{label}</span>
                {meta && <span className="shrink-0 tabular-nums text-neutral-400 dark:text-neutral-500">{meta}</span>}
            </p>
            <p className={`mt-1 text-2xl font-bold tabular-nums ${colour}`}>{value}</p>
        </div>
    );
}

/**
 * The validity cell — the one column on this screen that has to say three
 * different things.
 *
 * A draft has no date worth showing: nobody has seen it, so nothing is running
 * out. A sent offer shows the day it stops being good. And once that day has
 * passed it turns coral, because an offer nobody answered is the thing this
 * list exists to surface.
 *
 * An accepted or refused offer keeps the plain date: the deal is decided, and
 * shouting "expired" at a signed job would be wrong.
 */
function ValidUntilCell({ offer, t, locale, today }) {
    if (offer.status === 'draft') {
        return <span className="text-sm text-neutral-400 dark:text-neutral-500">{t('offers.not_sent_yet')}</span>;
    }

    const date = toLocalDate(offer.valid_until);
    if (!date) {
        return <span className="text-sm text-neutral-300 dark:text-neutral-600">—</span>;
    }

    const formatted = formatDate(offer.valid_until, locale);

    if (date < today && PENDING.has(offer.status)) {
        return (
            <span className="text-sm font-medium text-coral-700 dark:text-coral-300">
                {t('offers.expired_on', { date: formatted })}
            </span>
        );
    }

    return (
        <span className="text-sm text-neutral-700 dark:text-neutral-300">
            {t('offers.valid_until_date', { date: formatted })}
        </span>
    );
}

/**
 * The banner: work the agent has left for a person.
 *
 * The one green thing on this screen that is not an accepted offer, and the
 * only one that speaks about the agent at all. It carries the value as well as
 * the count because the first question a firm asks about a pile of quotes is
 * not how many there are but how much is in them — and it says plainly that
 * nothing goes out until a person sends it, which is the whole promise of the
 * stage and the reason a firm is willing to switch the drafting on.
 *
 * Absent when there is nothing waiting. A banner that renders "0 oferte
 * așteaptă" every day is a banner people stop reading.
 */
function AiDraftsBanner({ count, valueCents, onOpen, t }) {
    if (!(count > 0)) return null;

    return (
        <div className="flex flex-wrap items-start gap-3 rounded-xl border border-brand-200 bg-brand-50 px-4 py-3 dark:border-brand-800/60 dark:bg-brand-900/25">
            <Sparkles className="mt-0.5 h-5 w-5 shrink-0 text-brand-600 dark:text-brand-300" aria-hidden />

            <div className="min-w-[14rem] flex-1">
                <p className="text-sm font-semibold text-brand-900 dark:text-brand-100">
                    {t('offers.ai_waiting_title', { count })}
                </p>
                <p className="mt-0.5 text-sm text-brand-800 dark:text-brand-200">
                    {t('offers.ai_waiting_desc', { value: formatLei(valueCents) })}
                </p>
            </div>

            <button
                type="button"
                onClick={onOpen}
                className="shrink-0 rounded-xl bg-brand-600 px-3 py-2 text-sm font-semibold text-white transition hover:bg-brand-700"
            >
                {t('offers.check_first')}
            </button>
        </div>
    );
}

/** One offer. The whole row is the link; nothing inside it is separately clickable. */
function OfferRow({ offer, t, locale, today }) {
    const open = () => router.visit(route('client.offers.show', offer.uuid));

    // A contact is optional until the offer is sent, and a firm that quotes to
    // a company has no person's name to show. Both are covered by falling back.
    const who = offer.contact?.name || offer.contact?.company || null;

    return (
        <tr
            onClick={open}
            className="cursor-pointer border-b border-neutral-100 transition-colors last:border-0 hover:bg-neutral-50 dark:border-neutral-800 dark:hover:bg-neutral-800/40"
        >
            <td className="px-4 py-3">
                <span className="inline-flex items-center gap-1.5">
                    {/* In the "all" tab nothing else on the row says where an offer came
                        from — the "prepared by" column is empty for one the agent wrote. */}
                    {offer.source === 'ai' && (
                        <Sparkles
                            role="img"
                            aria-label={t('offers.tab_ai')}
                            className="h-3.5 w-3.5 shrink-0 text-brand-600 dark:text-brand-300"
                        />
                    )}
                    <span className="text-sm font-semibold tabular-nums text-neutral-900 dark:text-neutral-100">
                        {offer.number}
                    </span>
                </span>
            </td>

            <td className="px-4 py-3">
                {who ? (
                    <p className="truncate text-sm font-medium text-neutral-900 dark:text-neutral-100">{who}</p>
                ) : (
                    <p className="truncate text-sm text-neutral-400 dark:text-neutral-500">{t('offers.no_contact_yet')}</p>
                )}
                <p className="mt-0.5 text-xs text-ink-muted dark:text-neutral-400">
                    {t('offers.items_count', { count: offer.items_count ?? 0 })}
                </p>
            </td>

            <td className="px-4 py-3">
                <span className="text-sm font-semibold tabular-nums text-neutral-900 dark:text-neutral-100">
                    {formatLei(offer.total_cents)}
                </span>
            </td>

            <td className="px-4 py-3">
                <span
                    className={`inline-flex items-center rounded-full px-2 py-0.5 text-[11px] font-semibold ${
                        STATUS_STYLES[offer.status] ?? STATUS_STYLES.draft
                    }`}
                >
                    {t(STATUS_LABEL_KEYS[offer.status] ?? STATUS_LABEL_KEYS.draft)}
                </span>
            </td>

            <td className="px-4 py-3">
                {offer.creator?.name
                    ? <span className="text-sm text-neutral-700 dark:text-neutral-300">{offer.creator.name}</span>
                    : <span className="text-sm text-neutral-300 dark:text-neutral-600">—</span>}
            </td>

            <td className="px-4 py-3">
                <ValidUntilCell offer={offer} t={t} locale={locale} today={today} />
            </td>

            <td className="w-10 px-4 py-3 text-right">
                <ChevronRight className="ml-auto h-4 w-4 text-neutral-300 dark:text-neutral-600" aria-hidden />
            </td>
        </tr>
    );
}

export default function OffersIndex({
    offers,
    counts = {},
    stats = {},
    filters = {},
}) {
    const { t, i18n } = useTranslation();
    const { props } = usePage();
    const flash = props.flash ?? {};

    const [search, setSearch] = useState(filters.search ?? '');
    const [creating, setCreating] = useState(false);

    const rows = offers?.data ?? [];
    const locale = i18n.language || undefined;

    // Computed once per render pass, not per row: thirty rows asking the clock
    // thirty times would be thirty chances to straddle midnight.
    const today = useMemo(() => startOfToday(), []);

    /**
     * The URL is the state. Every list this screen can show can be linked to,
     * sent to a colleague and reloaded, and the back button undoes a filter
     * rather than leaving the page.
     */
    const go = (patch) => {
        const next = { ...filters, ...patch };
        Object.keys(next).forEach(key => {
            if (next[key] === null || next[key] === undefined || next[key] === '') delete next[key];
        });

        router.get(route('client.offers.index'), next, {
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

    /**
     * A blank offer, then straight into it.
     *
     * The button posts rather than opening a "new offer" form: there is nothing
     * to ask for up front — the client, the lines and the dates are all chosen
     * on the offer itself — and a form that collects nothing is a step that
     * exists only to be clicked through. The server answers with a redirect to
     * the offer it just numbered.
     */
    const create = () => {
        if (creating) return;

        setCreating(true);
        router.post(route('client.offers.store'), {}, {
            preserveScroll: true,
            onFinish: () => setCreating(false),
        });
    };

    /**
     * The period filter.
     *
     * Whole months, named by Intl in the reader's own language, because that is
     * how a firm talks about its quotes — "the ones from August" — and because
     * a pair of calendar boxes is two decisions where one will do. Each choice
     * sets both ends of the range the server filters on.
     */
    const periods = useMemo(() => {
        const now = new Date();

        return Array.from({ length: PERIOD_MONTHS }, (unused, i) => {
            const first = new Date(now.getFullYear(), now.getMonth() - i, 1);
            const last = new Date(first.getFullYear(), first.getMonth() + 1, 0);

            return {
                value: `${first.getFullYear()}-${pad2(first.getMonth() + 1)}`,
                label: first.toLocaleDateString(locale, { month: 'long', year: 'numeric' }),
                from: `${first.getFullYear()}-${pad2(first.getMonth() + 1)}-01`,
                to: `${last.getFullYear()}-${pad2(last.getMonth() + 1)}-${pad2(last.getDate())}`,
            };
        });
    }, [locale]);

    const pickPeriod = (value) => {
        const period = periods.find(p => p.value === value);

        go({ from: period?.from, to: period?.to });
    };

    const channels = ['whatsapp', 'messenger', 'instagram', 'email'].map(key => ({
        value: key,
        label: CHANNEL_LABELS[key] ?? key,
    }));

    const acceptedRate = Number(stats.accepted_rate ?? 0);
    const inProgressCount = Number(stats.in_progress_count ?? 0);

    /**
     * The tab strip carries two kinds of key: a status, and the AI bucket.
     *
     * `view` and `status` are set together on every change — one of them to a
     * value and the other to undefined — so switching tabs never leaves the
     * previous tab's clause behind in the URL. go() drops the undefined one.
     */
    const activeTab = filters.view === AI_VIEW ? AI_VIEW : (filters.status ?? null);

    const pickTab = (key) => go({
        view: key === AI_VIEW ? AI_VIEW : undefined,
        status: key === AI_VIEW ? undefined : (key ?? undefined),
    });

    return (
        <ClientLayout title={t('offers.title')}>
            <Head title={t('offers.title')} />

            <div className="space-y-4">
                <div className="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <h1 className="text-2xl font-bold text-neutral-900 dark:text-white">{t('offers.title')}</h1>
                        <p className="mt-1 text-sm text-ink-muted dark:text-neutral-400">{t('offers.subtitle')}</p>
                    </div>

                    <div className="flex flex-wrap gap-2">
                        <Link href={route('client.offers.settings')}
                            className="inline-flex items-center gap-1.5 rounded-xl border border-neutral-300 px-3 py-2 text-sm text-neutral-700 transition hover:bg-neutral-50 dark:border-neutral-600 dark:text-neutral-300 dark:hover:bg-neutral-800">
                            <Settings2 className="h-4 w-4" aria-hidden /> {t('offers.settings')}
                        </Link>
                        <button type="button" onClick={create} disabled={creating}
                            className="inline-flex items-center gap-1.5 rounded-xl bg-brand-600 px-4 py-2 text-sm font-semibold text-white transition hover:bg-brand-700 disabled:opacity-60">
                            <Plus className="h-4 w-4" aria-hidden /> {t('offers.new')}
                        </button>
                    </div>
                </div>

                {flash.success && (
                    <div className="rounded-xl bg-brand-50 px-4 py-3 text-sm text-brand-800 dark:bg-brand-900/25 dark:text-brand-200">{flash.success}</div>
                )}
                {flash.error && (
                    <div className="rounded-xl border border-coral-200 bg-coral-50 px-4 py-3 text-sm text-coral-800 dark:border-coral-900/40 dark:bg-coral-900/20 dark:text-coral-300">{flash.error}</div>
                )}

                {/* Above the tiles, not among them: this is the one thing on the
                    screen somebody has to act on today. */}
                <AiDraftsBanner
                    count={Number(stats.ai_drafts ?? 0)}
                    valueCents={Number(stats.ai_drafts_cents ?? 0)}
                    onOpen={() => pickTab(AI_VIEW)}
                    t={t}
                />

                <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                    <OfferStat
                        label={t('offers.stat_drafts')}
                        value={stats.drafts ?? 0}
                        tone="accent"
                        positive={(stats.drafts ?? 0) > 0}
                    />
                    <OfferStat
                        label={t('offers.stat_sent_month')}
                        value={stats.sent_this_month ?? 0}
                    />
                    <OfferStat
                        label={t('offers.stat_accepted')}
                        value={stats.accepted ?? 0}
                        meta={acceptedRate > 0 ? `${acceptedRate}%` : null}
                        tone="brand"
                        positive={(stats.accepted ?? 0) > 0}
                    />
                    <OfferStat
                        label={t('offers.stat_in_progress')}
                        value={formatLei(stats.in_progress_cents ?? 0)}
                        meta={inProgressCount > 0 ? inProgressCount : null}
                        positive={(stats.in_progress_cents ?? 0) > 0}
                    />
                </div>

                <FilterTabs
                    tabs={STATUS_TABS.map(({ key, labelKey, countKey }) => ({
                        key,
                        label: t(labelKey),
                        count: counts[countKey] ?? 0,
                    }))}
                    active={activeTab}
                    onChange={pickTab}
                />

                <div className="flex flex-wrap items-center gap-2">
                    <div className="relative min-w-[200px] flex-1">
                        <Search className="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-neutral-400" aria-hidden />
                        <input value={search} onChange={e => setSearch(e.target.value)}
                            placeholder={t('offers.search_placeholder')}
                            className="w-full rounded-xl border-neutral-300 py-2 pl-10 text-sm dark:border-neutral-600 dark:bg-neutral-800" />
                    </div>

                    <div className="w-full sm:w-44">
                        <Select
                            name="channel"
                            aria-label={t('offers.channel_all')}
                            value={filters.channel ?? ''}
                            onChange={e => go({ channel: e.target.value || undefined })}
                            placeholder={t('offers.channel_all')}
                            options={channels}
                        />
                    </div>

                    <div className="w-full sm:w-48">
                        <Select
                            name="period"
                            aria-label={t('offers.period_all')}
                            value={(filters.from ?? '').slice(0, 7)}
                            onChange={e => pickPeriod(e.target.value)}
                            placeholder={t('offers.period_all')}
                            options={periods.map(({ value, label }) => ({ value, label }))}
                        />
                    </div>
                </div>

                <div className="overflow-hidden rounded-2xl border border-neutral-200 bg-white dark:border-neutral-700 dark:bg-neutral-900">
                    {rows.length === 0 ? (
                        <EmptyState
                            icon={<FileText className="h-7 w-7" />}
                            title={t('offers.empty_title')}
                            description={t('offers.empty_desc')}
                            action={{ label: t('offers.new'), onClick: create }}
                        />
                    ) : (
                        <>
                            <div className="overflow-x-auto">
                                <table className="w-full">
                                    <thead>
                                        <tr className="border-b border-neutral-200 text-left text-[11px] font-semibold uppercase tracking-wider text-neutral-400 dark:border-neutral-700">
                                            <th className="px-4 py-3">{t('offers.col_number')}</th>
                                            <th className="px-4 py-3">{t('offers.col_client')}</th>
                                            <th className="px-4 py-3">{t('offers.col_value')}</th>
                                            <th className="px-4 py-3">{t('offers.col_status')}</th>
                                            <th className="px-4 py-3">{t('offers.col_created_by')}</th>
                                            <th className="px-4 py-3">{t('offers.col_valid_until')}</th>
                                            <th className="w-10 px-4 py-3" />
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {rows.map(offer => (
                                            <OfferRow key={offer.uuid} offer={offer} t={t} locale={locale} today={today} />
                                        ))}
                                    </tbody>
                                </table>
                            </div>

                            <Pagination data={offers} />
                        </>
                    )}
                </div>
            </div>
        </ClientLayout>
    );
}
