import { useTranslation } from 'react-i18next';
import {
    LayoutDashboard, Users, Settings, Radio, Inbox, Zap, Share2,
    LifeBuoy, KanbanSquare,
} from 'lucide-react';

const iconClass = 'h-4 w-4';

function safeRoute(name, ...args) {
    try { return route(name, ...args); } catch { return '#'; }
}

/**
 * Single source of truth for the client-panel sidebar navigation.
 *
 * Both ClientLayout and InboxLayout consume this so the sidebar is identical on
 * every client page. Previously each layout kept its own copy and they drifted —
 * the inbox sidebar was missing whole groups and items. Keep all nav changes here.
 *
 * The sidebar holds only what a small firm opens most working days. Everything
 * configured once — channels, SMS, SMTP, templates, team, billing, developer
 * tooling — lives in the Settings hub at client.settings.index, and everything
 * read occasionally (reports) is reached from the dashboard. The test for adding
 * a row here: would a three-person firm open it more than once a month?
 *
 * A group with no `label` renders as pinned rows without a heading — worth it for
 * the daily screens, since a heading costs about as much vertical space as the
 * row it introduces, and on a phone that is the whole budget.
 */
export default function useClientNav() {
    const { t } = useTranslation();

    const groups = [
        // Daily work. No heading: these four are self-evident and the space is
        // better spent on the rows themselves.
        {
            key: 'daily',
            label: null,
            items: [
                { label: t('nav.dashboard'),     href: safeRoute('client.dashboard'),            icon: <LayoutDashboard className={iconClass} />, activePattern: 'client.dashboard' },
                { label: t('nav.inbox'),         href: safeRoute('client.inbox.index'),          icon: <Inbox className={iconClass} />,          activePattern: 'client.inbox.*' },
                { label: t('nav.contacts'),      href: safeRoute('client.contacts.index'),       icon: <Users className={iconClass} />,          activePattern: 'client.contacts.*' },
                { label: t('nav.lead_pipeline'), href: safeRoute('client.leads.pipeline.index'), icon: <KanbanSquare className={iconClass} />,   activePattern: 'client.leads.*' },
            ],
        },
        {
            key: 'promote',
            label: t('nav.group_promote'),
            items: [
                { label: t('nav.campaigns'),    href: safeRoute('client.campaigns.index'),   icon: <Radio className={iconClass} />,  activePattern: 'client.campaigns.*' },
                { label: t('nav.social_media'), href: safeRoute('client.social.posts.index'), icon: <Share2 className={iconClass} />, activePattern: 'client.social.*' },
                { label: t('nav.automations'),  href: safeRoute('client.automations.index'), icon: <Zap className={iconClass} />,    activePattern: 'client.automations.*' },
            ],
        },
        {
            key: 'account',
            label: t('nav.group_account'),
            items: [
                { label: t('nav.settings'), href: safeRoute('client.settings.index'), icon: <Settings className={iconClass} />, activePattern: 'client.settings.*' },
                { label: t('nav.support'),  href: safeRoute('client.support.index'),  icon: <LifeBuoy className={iconClass} />, activePattern: 'client.support.*' },
            ],
        },
    ];

    // Derive the key and the active test here rather than in each layout: both
    // ClientLayout and InboxLayout used to carry a byte-identical copy of this
    // mapping, and a change to one silently skipped the other.
    return groups.map(group => ({
        ...group,
        items: group.items.map(item => ({
            ...item,
            key: item.activePattern || item.label,
            active: () => (item.activePattern ? route().current(item.activePattern) : false),
        })),
    }));
}
