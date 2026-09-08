import { router, usePage } from '@inertiajs/react';
import { useState, useEffect, useRef } from 'react';
import { useTranslation } from 'react-i18next';
import { Toaster, toast } from 'sonner';
import Sidebar from '@/Components/Sidebar';
import UpgradeModal from '@/Components/UpgradeModal';
import SubscriptionBanner from '@/Components/SubscriptionBanner';
import useClientNav from '@/Layouts/useClientNav';
import SidebarWorkspaceSwitcher from '@/Components/SidebarWorkspaceSwitcher';
import SidebarUserCard from '@/Components/SidebarUserCard';
import { describeNotification } from '@/Utils/notificationDescriptor';

export default function InboxLayout({ children }) {
    const { t, i18n } = useTranslation();
    const [sidebarOpen, setSidebarOpen] = useState(false);
    const { auth, impersonation, current_workspace_usage, unreadNotificationsCount, branding, demo_mode } = usePage().props;
    const logoUrl = branding?.logo_url;
    const [unreadCount, setUnreadCount] = useState(unreadNotificationsCount ?? 0);
    const clientNavGroups = useClientNav();

    // The Echo subscription below must not re-run when the language changes —
    // tearing down and re-opening a websocket to relabel a toast would drop
    // messages mid-switch. A ref keeps the translator current without becoming
    // an effect dependency.
    const i18nRef = useRef({ t, language: i18n.language });
    useEffect(() => {
        i18nRef.current = { t, language: i18n.language };
    }, [t, i18n.language]);

    useEffect(() => {
        setUnreadCount(unreadNotificationsCount ?? 0);
    }, [unreadNotificationsCount]);

    useEffect(() => {
        if (!window.Echo || !auth?.user?.id) return;
        window.Echo.private(`App.Models.User.${auth.user.id}`)
            .notification((notification) => {
                setUnreadCount(prev => prev + 1);
                // Same descriptor ClientLayout and the bell dropdown use. This copy
                // used to carry its own English strings, so the identical event read
                // one way on the dashboard and another in the inbox.
                const { t: tr, language } = i18nRef.current;
                const { title, body } = describeNotification(notification, tr, language);
                toast(title, {
                    description: body || undefined,
                    action: notification.url
                        ? { label: tr('common.view'), onClick: () => router.visit(notification.url) }
                        : undefined,
                });
            });
        return () => { window.Echo.leave(`App.Models.User.${auth.user.id}`); };
    }, [auth?.user?.id]);

    const returnToAdmin = () => {
        router.post(impersonation?.returnUrl ?? route('admin.impersonation.stop'));
    };

    return (
        <div className="h-screen overflow-hidden bg-surface dark:bg-neutral-950 flex flex-col">
            {impersonation?.active && (
                <div className="flex items-center justify-between gap-4 bg-amber-500/90 text-white px-4 py-2 text-sm font-medium shrink-0">
                    <span>{t('impersonation.impersonating', { name: impersonation.clientName })}</span>
                    <button type="button" onClick={returnToAdmin} className="rounded-soft bg-white/20 px-3 py-1.5 font-medium hover:bg-white/30 transition">
                        {t('impersonation.return_to_admin')}
                    </button>
                </div>
            )}

            {/* The inbox is the one client surface that does not use ClientLayout,
                and the one a blocked customer is most likely to be sitting in when
                they discover it. Same position as the impersonation bar: a shrink-0
                child of the outer column, above the sidebar/content row. */}
            <SubscriptionBanner />

            <div className="flex flex-1 overflow-hidden">
                <Sidebar
                    open={sidebarOpen}
                    onClose={() => setSidebarOpen(false)}
                    title={t('client.panel') || 'Client Panel'}
                    logo={logoUrl ? <img src={logoUrl} alt="Logo" className="h-8 max-w-[160px] object-contain" /> : null}
                    showCreateButton={false}
                    navGroups={clientNavGroups}
                    // The inbox renders no Topbar, so without these two the workspace
                    // name, the account menu and the way out are all unreachable from
                    // the screen people sit in all day.
                    header={<SidebarWorkspaceSwitcher />}
                    footer={<SidebarUserCard unreadCount={unreadCount} />}
                />

                <div className="lg:pl-64 rtl:lg:pl-0 rtl:lg:pr-64 flex-1 overflow-hidden flex flex-col">
                    {children}
                </div>
            </div>

            {/* Demo notice — pinned to the bottom of the viewport */}
            {demo_mode && (
                <div className="flex items-center justify-center gap-2 bg-amber-500/90 text-amber-950 px-4 py-2 text-sm font-medium shrink-0">
                    <span>{t('demo.banner') || 'Demo mode: changes are disabled.'}</span>
                </div>
            )}

            {/* Mobile menu button */}
            <button
                type="button"
                onClick={() => setSidebarOpen(true)}
                className="fixed bottom-4 right-4 z-30 flex h-12 w-12 items-center justify-center rounded-soft-lg bg-white dark:bg-neutral-900 border border-gray-200 dark:border-neutral-800 shadow-lg text-neutral-600 hover:bg-neutral-50 dark:text-neutral-400 dark:hover:bg-neutral-800 lg:hidden rtl:right-auto rtl:left-4"
                aria-label={t('open_menu')}
            >
                <svg className="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M4 6h16M4 12h16M4 18h16" />
                </svg>
            </button>

            <UpgradeModal />
            <Toaster richColors position="top-right" />
        </div>
    );
}
