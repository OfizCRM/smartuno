import { Link, router, usePage } from '@inertiajs/react';
import { useTranslation } from 'react-i18next';
import { Bell, Moon, MoreVertical, Sun } from 'lucide-react';
import { Dropdown } from '@/Components/ui';
import { useTheme } from '@/context/ThemeContext';

/**
 * The signed-in person, at the foot of the rail.
 *
 * The account menu lives in the Topbar, which the inbox does not render — so
 * inside the inbox there is currently no way to reach your profile or to log
 * out without leaving the screen first. This fills the Sidebar's existing
 * `footer` slot rather than adding a new one.
 *
 * safeRoute mirrors the guard the layouts already use: a route that has not
 * been registered must not take the whole shell down.
 */
function safeRoute(name, fallback = '#') {
    try {
        return route(name);
    } catch {
        return fallback;
    }
}

export default function SidebarUserCard({ unreadCount }) {
    const { t } = useTranslation();
    const { auth, unreadNotificationsCount = 0 } = usePage().props;
    // The shared prop only refreshes on an Inertia visit. InboxLayout already
    // holds a live count fed by the notification socket, so prefer it when given.
    const unread = unreadCount ?? unreadNotificationsCount;
    const { theme, setTheme } = useTheme();
    const user = auth?.user;

    // Same two writes the header toggle does: the row alone would persist a choice
    // ThemeProvider then ignores, because it treats localStorage as authoritative.
    const toggleTheme = () => {
        const next = theme === 'dark' ? 'light' : 'dark';
        setTheme(next);
        router.post(route('theme.update'), { theme: next }, { preserveScroll: true });
    };

    if (! user) {
        return null;
    }

    const initial = (user.name ?? user.email ?? '?')[0]?.toUpperCase();

    return (
        <div className="flex items-center gap-1.5">
            {/* An initial, not a photo: User::avatarUrl() is a plain method with no
                $appends entry, so no avatar URL reaches the shared auth prop. Reading
                user.avatar here would print a storage path, not an image. */}
            <span className="mr-1 flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-brand-100 text-xs font-bold text-brand-700 dark:bg-brand-900/40 dark:text-brand-300">
                {initial}
            </span>

            <div className="mr-0.5 min-w-0 flex-1">
                <p className="truncate text-[13px] font-semibold text-sidebar-item dark:text-white">{user.name}</p>
                <p className="truncate text-[11px] text-sidebar-heading dark:text-white/55">{user.email}</p>
            </div>

            {/* The inbox renders no Topbar, so this rail is the only place these two
                can live. Without them it is the one screen in the product where a
                notification cannot be seen and the theme cannot be changed. */}
            <Link
                href={route('client.notifications.index')}
                title={t('ui.notifications')}
                aria-label={t('ui.notifications')}
                className="relative shrink-0 rounded-lg p-1.5 text-sidebar-heading transition-colors hover:bg-sidebar-hover dark:text-white/60 dark:hover:bg-white/10"
            >
                <Bell className="h-4 w-4" />
                {unread > 0 && (
                    <span className="absolute -right-0.5 -top-0.5 flex h-3.5 min-w-3.5 items-center justify-center rounded-full bg-coral-500 px-1 text-[9px] font-bold text-white">
                        {unread > 9 ? '9+' : unread}
                    </span>
                )}
            </Link>

            <button
                type="button"
                onClick={toggleTheme}
                title={t('topbar.switch_theme')}
                aria-label={t('topbar.switch_theme')}
                className="shrink-0 rounded-lg p-1.5 text-sidebar-heading transition-colors hover:bg-sidebar-hover dark:text-white/60 dark:hover:bg-white/10"
            >
                {theme === 'dark' ? <Sun className="h-4 w-4" /> : <Moon className="h-4 w-4" />}
            </button>

            <Dropdown>
                <Dropdown.Trigger>
                    <button
                        type="button"
                        aria-label={t('nav.profile')}
                        className="shrink-0 rounded-lg p-1.5 text-sidebar-heading transition-colors hover:bg-sidebar-hover dark:text-white/60 dark:hover:bg-white/10"
                    >
                        <MoreVertical className="h-4 w-4" />
                    </button>
                </Dropdown.Trigger>
                {/* Upward because this card sits on the bottom edge of the viewport,
                    and right-aligned because the trigger is the last thing in a 256px
                    rail — a left-aligned menu would hang out over the conversation
                    list. */}
                <Dropdown.Content align="right" width="48" direction="up">
                    <Link href={safeRoute('client.profile.edit')} className="block px-4 py-2.5 text-sm text-neutral-600 hover:bg-neutral-50 dark:text-neutral-400 dark:hover:bg-neutral-800">
                        {t('nav.profile')}
                    </Link>
                    <Link href={safeRoute('client.profile.2fa')} className="block px-4 py-2.5 text-sm text-neutral-600 hover:bg-neutral-50 dark:text-neutral-400 dark:hover:bg-neutral-800">
                        {t('nav.twoFactor')}
                    </Link>
                    <Link href={safeRoute('client.profile.sessions')} className="block px-4 py-2.5 text-sm text-neutral-600 hover:bg-neutral-50 dark:text-neutral-400 dark:hover:bg-neutral-800">
                        {t('nav.sessions')}
                    </Link>
                    <Dropdown.Divider />
                    <Link href={route('logout')} method="post" as="button" className="block w-full px-4 py-2.5 text-left text-sm text-coral-600 hover:bg-coral-50 dark:hover:bg-coral-900/20">
                        {t('nav.logout')}
                    </Link>
                </Dropdown.Content>
            </Dropdown>
        </div>
    );
}
