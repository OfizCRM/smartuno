import { Link } from '@inertiajs/react';
import { useTranslation } from 'react-i18next';

const TABS = [
    { route: 'client.reports.inbox.index', labelKey: 'nav.reports_inbox' },
    { route: 'client.reports.automations.index', labelKey: 'nav.automations' },
    { route: 'client.reports.ai.index', labelKey: 'nav.ai_usage' },
    { route: 'client.reports.social.index', labelKey: 'nav.social' },
];

/**
 * Links the four report pages to each other.
 *
 * They used to be four rows in a REPORTS sidebar group. That group is gone — one
 * monthly glance did not earn four permanent rows — so without this you land on
 * one report from the dashboard and have no way to reach the other three.
 *
 * Not the Tabs primitive: these are four separate routed pages, and Tabs is
 * uncontrolled with no way to mark one active from the URL.
 */
export default function ReportNav({ className = '' }) {
    const { t } = useTranslation();

    const items = TABS.map((tab) => {
        try {
            return { ...tab, href: route(tab.route), active: route().current(tab.route) };
        } catch {
            return null;
        }
    }).filter(Boolean);

    if (items.length < 2) return null;

    return (
        // Scrolls inside itself rather than pushing the page sideways: four
        // Romanian labels do not fit a 375px screen.
        <nav className={['-mx-1 flex gap-1 overflow-x-auto pb-1', className].join(' ')}>
            {items.map((item) => (
                <Link
                    key={item.route}
                    href={item.href}
                    className={[
                        'shrink-0 whitespace-nowrap rounded-xl px-3 py-1.5 text-[13px] font-medium transition-colors duration-150',
                        item.active
                            ? 'bg-brand-500 text-white'
                            : 'text-ink-muted hover:bg-sidebar-hover hover:text-brand-700 dark:text-neutral-400 dark:hover:bg-white/5',
                    ].join(' ')}
                >
                    {t(item.labelKey)}
                </Link>
            ))}
        </nav>
    );
}
