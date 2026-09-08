import { Head, router, usePage } from '@inertiajs/react';
import { MessageSquare, Plus } from 'lucide-react';
import { useCallback, useEffect, useState } from 'react';
import { useTranslation } from 'react-i18next';
import InboxLayout from '@/Layouts/InboxLayout';
import NewConversationModal from '@/Components/Inbox/NewConversationModal';
import ConversationListPanel from '@/Components/Inbox/ConversationListPanel';
import FilterSidebar from '@/Components/Inbox/FilterSidebar';

/**
 * The inbox with nothing open yet: pick a conversation.
 *
 * It is a separate page from Inbox/Show on purpose. Making /app/inbox open the
 * first conversation instead would mark it read — InboxController::show sets
 * unread_count to 0 — so merely visiting the inbox would quietly clear someone
 * else's unread badge.
 *
 * Everything visible here now comes from the shared components, so this screen
 * and the conversation page cannot drift apart again. They already had: only one
 * of the two ever gained the counts, the chips and the server-side search.
 */
export default function InboxIndex({
    conversations: initialConversations,
    filters = {},
    labels = [],
    channelAccounts = [],
    counts = {},
}) {
    const { t } = useTranslation();
    const { props } = usePage();
    const authUser = props.auth?.user;
    const workspaceId = props.currentWorkspace?.id ?? authUser?.workspace_id;
    const userTz = props.timezone || 'Europe/Bucharest';

    const [conversations, setConversations] = useState(initialConversations);
    const [flashingIds, setFlashingIds] = useState(new Set());
    const [loading, setLoading] = useState(false);
    const [showNewModal, setShowNewModal] = useState(false);

    useEffect(() => {
        setConversations(initialConversations);
        setLoading(false);
    }, [initialConversations]);

    // Live arrivals: bump the row, float it to the top and light it up briefly.
    useEffect(() => {
        if (! window.Echo || ! workspaceId) return undefined;

        window.Echo.private(`workspace.${workspaceId}`)
            .listen('.MessageReceived', (e) => {
                setConversations(prev => {
                    const convId = e.conversation_id;
                    const exists = prev.data.find(c => c.id === convId);
                    if (! exists) {
                        // A conversation that is not on this page yet — a new contact,
                        // or one filtered out. Only the server knows where it belongs.
                        router.reload({ preserveScroll: true, preserveState: true });

                        return prev;
                    }

                    setFlashingIds(ids => new Set([...ids, convId]));
                    setTimeout(() => setFlashingIds(ids => {
                        const next = new Set(ids);
                        next.delete(convId);

                        return next;
                    }), 2000);

                    return {
                        ...prev,
                        data: [
                            { ...exists, unread_count: (exists.unread_count ?? 0) + 1, last_message_at: e.created_at, last_message: { body: e.body } },
                            ...prev.data.filter(c => c.id !== convId),
                        ],
                    };
                });
            });

        return () => { window.Echo.leave(`workspace.${workspaceId}`); };
    }, [workspaceId]);

    // Memoised: the panel's debounced search depends on it, and a new identity
    // every render would rebuild the timer and defeat the debounce.
    const navigate = useCallback((params) => {
        setLoading(true);
        router.get(route('client.inbox.index'), { ...filters, ...params }, { preserveState: true, replace: true });
    }, [filters]);

    return (
        <InboxLayout>
            <Head title={t('inbox.title')} />
            {showNewModal && <NewConversationModal onClose={() => setShowNewModal(false)} />}

            <div className="flex flex-1 overflow-hidden">
                <aside className="flex w-56 shrink-0 flex-col overflow-hidden border-r border-neutral-200 bg-white dark:border-neutral-700 dark:bg-neutral-900">
                    <div className="border-b border-neutral-100 p-3 dark:border-neutral-800">
                        <button
                            type="button"
                            onClick={() => setShowNewModal(true)}
                            className="flex w-full items-center justify-center gap-2 rounded-xl bg-brand-600 px-3 py-2 text-[13px] font-semibold text-white transition hover:bg-brand-700"
                        >
                            <Plus className="h-4 w-4" />
                            {t('inbox.new_conversation')}
                        </button>
                    </div>
                    <FilterSidebar
                        filters={filters}
                        labels={labels}
                        channelAccounts={channelAccounts}
                        counts={counts}
                        onFolder={(key) => navigate({ folder: key, channel: undefined, label: undefined, account_id: undefined })}
                        onChannel={(ch) => navigate({ channel: filters.channel === ch ? undefined : ch, account_id: undefined })}
                        onAccount={(id) => navigate({ account_id: String(filters.account_id) === String(id) ? undefined : id, channel: undefined })}
                        onLabel={(id) => navigate({ label: String(filters.label) === String(id) ? undefined : id })}
                    />
                </aside>

                <ConversationListPanel
                    conversations={conversations}
                    filters={filters}
                    counts={counts}
                    channelAccounts={channelAccounts}
                    flashingIds={flashingIds}
                    loading={loading}
                    userTz={userTz}
                    onNavigate={navigate}
                    onRefresh={() => { setLoading(true); router.reload(); }}
                />

                <div className="flex flex-1 items-center justify-center bg-neutral-50 dark:bg-neutral-950">
                    <div className="text-center">
                        <div className="mx-auto mb-4 flex h-16 w-16 items-center justify-center rounded-2xl bg-neutral-100 dark:bg-neutral-800">
                            <MessageSquare className="h-8 w-8 text-neutral-300 dark:text-neutral-600" />
                        </div>
                        <p className="text-base font-semibold text-neutral-500 dark:text-neutral-400">{t('inbox.select_conversation')}</p>
                        <p className="mt-1 text-sm text-neutral-400 dark:text-neutral-500">{t('inbox.select_conversation_desc')}</p>
                    </div>
                </div>
            </div>
        </InboxLayout>
    );
}
