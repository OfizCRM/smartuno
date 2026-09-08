import { router } from '@inertiajs/react';
import { RefreshCw, Search, Trash2, X } from 'lucide-react';
import { useEffect, useMemo, useState } from 'react';
import { useTranslation } from 'react-i18next';
import EmptyState from '@/Components/EmptyState';
import ConversationCard from '@/Components/Inbox/ConversationCard';
import { FOLDERS, LIST_CHIPS } from '@/Components/Inbox/FilterSidebar';
import { Button, Modal, Skeleton } from '@/Components/ui';
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
    const [selected, setSelected] = useState(() => new Set());
    const [confirming, setConfirming] = useState(false);
    const [deleting, setDeleting] = useState(false);

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

    const rows = useMemo(() => conversations?.data ?? [], [conversations]);

    // Derived, not synchronised: a filter change or a new page leaves ticks
    // pointing at rows that are no longer on screen, and deleting one of those
    // would be a delete the user cannot see. Filtering at render keeps the two
    // in step without an effect that writes state back.
    const visible = useMemo(() => {
        const uuids = new Set(rows.map(r => r.uuid));

        return new Set([...selected].filter(uuid => uuids.has(uuid)));
    }, [rows, selected]);

    const toggleSelect = (uuid) => setSelected(prev => {
        const next = new Set(prev);
        next.has(uuid) ? next.delete(uuid) : next.add(uuid);

        return next;
    });

    const deleteSelected = () => {
        const uuids = [...visible];
        // The open thread is one of them: the server would send us back to a
        // conversation that no longer resolves, so leave for the list instead.
        const losingOpenThread = activeUuid && visible.has(activeUuid);

        setDeleting(true);
        router.delete(route('client.inbox.destroy-many'), {
            data: { uuids, to_index: losingOpenThread },
            preserveScroll: true,
            onSuccess: () => {
                setSelected(new Set());
                setConfirming(false);
            },
            onFinish: () => setDeleting(false),
        });
    };
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

            {visible.size > 0 && (
                <div className="flex items-center justify-between gap-2 border-b border-brand-100 bg-brand-50 px-3 py-2 dark:border-brand-900/40 dark:bg-brand-900/20">
                    <span className="text-xs font-medium text-brand-800 dark:text-brand-200">
                        {t('inbox.selected_count', { count: visible.size })}
                    </span>
                    <div className="flex items-center gap-1">
                        <button type="button" onClick={() => setSelected(new Set())}
                            className="rounded px-2 py-1 text-xs text-neutral-500 transition hover:bg-white dark:hover:bg-neutral-800">
                            {t('common.cancel')}
                        </button>
                        <button type="button" onClick={() => setConfirming(true)}
                            className="inline-flex items-center gap-1 rounded px-2 py-1 text-xs font-semibold text-coral-700 transition hover:bg-white dark:text-coral-400 dark:hover:bg-neutral-800">
                            <Trash2 className="h-3.5 w-3.5" />
                            {t('common.delete')}
                        </button>
                    </div>
                </div>
            )}

            <Modal show={confirming} onClose={() => setConfirming(false)} maxWidth="md">
                <div className="p-5">
                    <h3 className="text-base font-semibold text-neutral-900 dark:text-neutral-100">
                        {t('inbox.delete_confirm_title', { count: visible.size })}
                    </h3>
                    <p className="mt-2 text-sm text-neutral-600 dark:text-neutral-400">
                        {t('inbox.delete_confirm_body')}
                    </p>
                    <div className="mt-5 flex justify-end gap-2">
                        <Button variant="ghost" onClick={() => setConfirming(false)}>{t('common.cancel')}</Button>
                        <Button variant="danger" onClick={deleteSelected} disabled={deleting}>{t('common.delete')}</Button>
                    </div>
                </div>
            </Modal>

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
                                selected={visible.has(conv.uuid)}
                                selecting={visible.size > 0}
                                onToggleSelect={toggleSelect}
                            />
                        ))}
            </div>
        </div>
    );
}
