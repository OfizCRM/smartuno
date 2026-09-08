import { RefreshCw, Search, X } from 'lucide-react';
import { useEffect, useState } from 'react';
import { useTranslation } from 'react-i18next';
import EmptyState from '@/Components/EmptyState';
import ConversationCard from '@/Components/Inbox/ConversationCard';
import { FOLDERS, LIST_CHIPS } from '@/Components/Inbox/FilterSidebar';
import { Skeleton } from '@/Components/ui';
import { CHANNEL_LABELS } from '@/Components/BrandIcons';
import { Inbox } from 'lucide-react';

/**
 * The middle column: heading, search, chips, rows.
 *
 * Shared so the search lives in one place. It was written twice, and only one of
 * the two copies was ever moved to the server — the landing page kept filtering
 * the rows it happened to have, in the browser, by name.
 *
 * `onNavigate` is the one thing the two screens do differently: the landing page
 * reloads itself, the conversation page reloads while keeping the open thread.
 */
function ConversationSkeleton() {
    return (
        <div className="flex items-start gap-2.5 border-b border-neutral-100 px-3 py-3 dark:border-neutral-800">
            <Skeleton variant="circle" className="h-9 w-9 shrink-0" />
            <div className="flex-1 space-y-2">
                <Skeleton className="h-3.5 w-28" />
                <Skeleton className="h-3 w-44" />
            </div>
        </div>
    );
}

export default function ConversationListPanel({
    conversations,
    filters = {},
    counts = {},
    channelAccounts = [],
    activeUuid = null,
    flashingIds,
    loading = false,
    userTz,
    onNavigate,
    onRefresh,
    headerExtra = null,
}) {
    const { t } = useTranslation();
    const [search, setSearch] = useState(filters.search ?? '');

    // Debounced, and only when the term actually differs from what the server
    // already has — otherwise landing on a searched URL would re-request it.
    useEffect(() => {
        const term = search.trim();
        if (term === (filters.search ?? '')) {
            return undefined;
        }
        const timer = setTimeout(() => onNavigate({ search: term || undefined }), 350);

        return () => clearTimeout(timer);
    }, [search, filters.search, onNavigate]);

    const rows = conversations?.data ?? [];
    const folderLabel = FOLDERS.find(f => (f.key ?? null) === (filters.folder ?? null))?.labelKey ?? 'inbox.folder_all';
    const account = channelAccounts.find(a => String(a.id) === String(filters.account_id));

    return (
        <div className="flex w-80 shrink-0 flex-col border-r border-neutral-200 bg-white dark:border-neutral-700 dark:bg-neutral-900">
            <div className="space-y-2 border-b border-neutral-100 px-3 py-3 dark:border-neutral-800">
                <div className="flex items-center justify-between gap-2">
                    <span className="flex flex-wrap items-center gap-1 text-sm font-semibold text-neutral-800 dark:text-neutral-200">
                        {t(folderLabel)}
                        {filters.channel && (
                            <span className="text-xs font-normal text-neutral-400">· {CHANNEL_LABELS[filters.channel] ?? filters.channel}</span>
                        )}
                        {account && (
                            <span className="text-xs font-normal text-neutral-400">· {account.display_name || account.phone_number_id}</span>
                        )}
                    </span>
                    <div className="flex items-center gap-1">
                        <span className="text-xs tabular-nums text-neutral-400">{conversations?.total ?? 0}</span>
                        {headerExtra}
                        <button type="button" onClick={onRefresh} aria-label={t('common.refresh')}
                            className="rounded p-1 text-neutral-400 transition hover:bg-neutral-100 dark:hover:bg-neutral-800">
                            <RefreshCw className={`h-3.5 w-3.5 ${loading ? 'animate-spin' : ''}`} />
                        </button>
                    </div>
                </div>

                <div className="relative">
                    <Search className="pointer-events-none absolute left-3 top-1/2 h-3.5 w-3.5 -translate-y-1/2 text-neutral-400" />
                    <input
                        value={search}
                        onChange={e => setSearch(e.target.value)}
                        placeholder={t('inbox.search_conversations')}
                        className="w-full rounded-lg border-0 bg-neutral-100 py-2 pl-9 pr-8 text-xs placeholder-neutral-400 focus:outline-none focus:ring-2 focus:ring-brand-500 dark:bg-neutral-800"
                    />
                    {search && (
                        <button type="button" onClick={() => setSearch('')} aria-label={t('common.clear')}
                            className="absolute right-2 top-1/2 -translate-y-1/2 rounded p-0.5 text-neutral-400 hover:text-neutral-600 dark:hover:text-neutral-200">
                            <X className="h-3.5 w-3.5" />
                        </button>
                    )}
                </div>

                {/* Only the views that mean "still needs work" — resolved and snoozed
                    live in the rail, where you go looking for them. */}
                <div className="flex flex-wrap gap-1.5">
                    {LIST_CHIPS.map(({ key, countKey, labelKey }) => {
                        const active = (filters.folder ?? null) === key;
                        const count = counts.views?.[countKey];

                        return (
                            <button key={key ?? 'open'} type="button" onClick={() => onNavigate({ folder: key })}
                                className={`inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 text-xs font-medium transition ${
                                    active
                                        ? 'bg-brand-600 text-white'
                                        : 'border border-neutral-200 bg-white text-neutral-600 hover:bg-neutral-50 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-300'
                                }`}>
                                {t(labelKey)}
                                {count > 0 && <span className="tabular-nums opacity-70">{count}</span>}
                            </button>
                        );
                    })}
                </div>
            </div>

            <div className="flex-1 overflow-y-auto">
                {loading && rows.length === 0
                    ? Array.from({ length: 6 }).map((_, i) => <ConversationSkeleton key={i} />)
                    : rows.length === 0
                        ? (
                            <div className="px-4 py-10">
                                <EmptyState
                                    icon={<Inbox className="h-7 w-7" />}
                                    title={t('inbox.no_conversations')}
                                    description={t('inbox.no_conversations_match')}
                                />
                            </div>
                        )
                        : rows.map(conv => (
                            <ConversationCard
                                key={conv.id}
                                conv={conv}
                                isActive={conv.uuid === activeUuid}
                                isFlashing={flashingIds?.has(conv.id) ?? false}
                                userTz={userTz}
                            />
                        ))}
            </div>
        </div>
    );
}
