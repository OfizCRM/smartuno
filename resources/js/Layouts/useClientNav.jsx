import { usePage } from '@inertiajs/react';
import { useTranslation } from 'react-i18next';
import {
    LayoutDashboard, Users, Settings, Radio, Inbox, Zap, Share2,
    LifeBuoy, FolderOpen, Package, FileText,
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
    // Both shared on every client request; 0 on admin routes and before a
    // workspace is resolved, which the badge renders as nothing.
    const { inboxOpenCount = 0, offerAiDraftsCount = 0 } = usePage().props;

    const groups = [
        // Daily work. No heading: these six are self-evident and the space is
        // better spent on the rows themselves.
        // Offers sits next to the inbox on purpose: it is an action queue you
        // work through, not a screen you configure once. Its badge counts only
        // the drafts the agent prepared and nobody has decided on — a person's
        // own unfinished draft is not waiting on anyone, so it is not a number
        // the rail should be nagging them with.
        {
            key: 'daily',
            label: null,
            items: [
                { label: t('nav.dashboard'),     href: safeRoute('client.dashboard'),            icon: <LayoutDashboard className={iconClass} />, activePattern: 'client.dashboard' },
                { label: t('nav.inbox'),         href: safeRoute('client.inbox.index'),          icon: <Inbox className={iconClass} />,          activePattern: 'client.inbox.*', badge: inboxOpenCount },
                { label: t('nav.offers'),        href: safeRoute('client.offers.index'),         icon: <FileText className={iconClass} />,       activePattern: 'client.offers.*', badge: offerAiDraftsCount },
                { label: t('nav.contacts'),      href: safeRoute('client.contacts.index'),       icon: <Users className={iconClass} />,          activePattern: 'client.contacts.*' },
                { label: t('nav.products'),      href: safeRoute('client.catalog.index'),        icon: <Package className={iconClass} />,        activePattern: 'client.catalog.*' },
                { label: t('nav.documents'),     href: safeRoute('client.documents.index'),      icon: <FolderOpen className={iconClass} />,     activePattern: 'client.documents.*' },
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
