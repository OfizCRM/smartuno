import ClientLayout from '@/Layouts/ClientLayout';
import { Head, Link, router, usePage } from '@inertiajs/react';
import { Bell, CheckCheck, Settings } from 'lucide-react';
import { useTranslation } from 'react-i18next';
import EmptyState from '@/Components/EmptyState';
import NotificationItem from '@/Components/NotificationItem';
import { dayBucket } from '@/Utils/relativeTime';

/**
 * The full notification feed.
 *
 * The preferences table that used to live on this page was a broken duplicate
 * of Settings/Notifications: it wrote event keys ('subscription.created') that
 * no notification ever reads, on channels the controller rejects, so its Save
 * button answered 422. That page works, so this one links to it.
 */

const BUCKETS = [
    { key: 'today', labelKey: 'notif.group_today' },
    { key: 'yesterday', labelKey: 'notif.group_yesterday' },
    { key: 'earlier', labelKey: 'notif.group_earlier' },
];

export default function NotificationsIndex({ notifications = [] }) {
    const { t } = useTranslation();
    const { flash, timezone } = usePage().props;
    const userTz = timezone || 'Europe/Bucharest';

    const csrf = () => document.querySelector('meta[name="csrf-token"]')?.content;

    const markRead = (id) => {
        fetch(route('client.notifications.read', id), {
            method: 'POST',
            headers: { 'X-CSRF-TOKEN': csrf() },
        }).then(() => router.reload({ only: ['notifications', 'unreadNotificationsCount'] }));
    };

    const remove = (id) => {
        fetch(route('client.notifications.destroy', id), {
            method: 'DELETE',
            headers: { 'X-CSRF-TOKEN': csrf() },
        }).then(() => router.reload({ only: ['notifications', 'unreadNotificationsCount'] }));
    };

    const markAllRead = () => router.post(route('client.notifications.read-all'), {}, { preserveScroll: true });

    const open = (url) => router.visit(url);

    const unreadCount = notifications.filter(n => ! n.read_at).length;

    const grouped = BUCKETS
        .map(bucket => ({
            ...bucket,
            items: notifications.filter(n => dayBucket(n.created_at) === bucket.key),
        }))
        .filter(group => group.items.length > 0);

    return (
        <ClientLayout title={t('notifications.title')}>
            <Head title={t('notifications.title')} />

            <div className="mx-auto max-w-3xl space-y-5">
                <div className="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <h1 className="text-2xl font-bold text-neutral-900 dark:text-white">{t('notifications.title')}</h1>
                        <p className="mt-1 text-sm text-ink-muted dark:text-neutral-400">
                            {unreadCount > 0 ? t('notif.unread', { count: unreadCount }) : t('notif.all_read')}
                        </p>
                    </div>

                    <div className="flex items-center gap-2">
                        {unreadCount > 0 && (
                            <button
                                type="button"
                                onClick={markAllRead}
                                className="inline-flex items-center gap-1.5 rounded-soft px-3 py-2 text-sm font-medium text-brand-600 transition-colors hover:bg-brand-50 dark:text-brand-400 dark:hover:bg-brand-900/20"
                            >
                                <CheckCheck className="h-4 w-4" /> {t('notifications_page.mark_all_read')}
                            </button>
                        )}
                        <Link
                            href={route('client.settings.notifications')}
                            className="inline-flex items-center gap-1.5 rounded-soft px-3 py-2 text-sm font-medium text-neutral-600 transition-colors hover:bg-neutral-100 hover:text-neutral-900 dark:text-neutral-400 dark:hover:bg-neutral-800 dark:hover:text-white"
                        >
                            <Settings className="h-4 w-4" /> {t('notif.manage_preferences')}
                        </Link>
                    </div>
                </div>

                {flash?.success && (
                    <div className="rounded-xl bg-brand-50 px-4 py-3 text-sm text-brand-800 dark:bg-brand-900/25 dark:text-brand-200">
                        {flash.success}
                    </div>
                )}

                {notifications.length === 0 ? (
                    <div className="rounded-xl border border-neutral-200 bg-white dark:border-neutral-800 dark:bg-neutral-900/40">
                        <EmptyState
                            icon={<Bell className="h-7 w-7" />}
                            title={t('notif.empty_title')}
                            description={t('notif.empty_desc')}
                        />
                    </div>
                ) : (
                    grouped.map(group => (
                        <section key={group.key} className="space-y-2">
                            <h2 className="px-1 text-xs font-semibold uppercase tracking-wider text-neutral-400 dark:text-neutral-500">
                                {t(group.labelKey)}
                            </h2>
                            <div className="space-y-2">
                                {group.items.map(n => (
                                    <NotificationItem
                                        key={n.id}
                                        notification={n}
                                        timezone={userTz}
                                        onOpen={open}
                                        onMarkRead={markRead}
                                        onDelete={remove}
                                    />
                                ))}
                            </div>
                        </section>
                    ))
                )}
            </div>
        </ClientLayout>
    );
}
