import { Link } from '@inertiajs/react';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { ChevronDown, Plus, X } from 'lucide-react';
import { useBranding } from '@/hooks/useBranding';

/**
 * One navigation row. Extracted because the grouped list and the flat list each
 * carried their own copy of this markup and had already drifted apart.
 *
 * An item marked `external` renders a real anchor: routing an off-site URL
 * through an Inertia <Link> asks the SPA to fetch it as a page and fails.
 */
function NavRow({ item, onClose, showActiveDot = false }) {
    const isActive =
        typeof item.active === 'function'
            ? item.active()
            : item.active ?? (item.route ? route().current(item.route) : false);

    const className = [
        'group flex items-center gap-2.5 rounded-xl px-3 py-2 text-[13px] font-medium transition-all duration-150',
        isActive
            ? 'bg-sidebar-active text-sidebar-active-fg shadow-card dark:bg-white/10 dark:text-white dark:shadow-none'
            : 'text-sidebar-item hover:bg-sidebar-hover dark:text-white/80 dark:hover:bg-white/10 dark:hover:text-white',
    ].join(' ');

    const inner = (
        <>
            {item.icon && (
                <span className={[
                    'shrink-0 transition-colors duration-150',
                    isActive
                        ? 'text-sidebar-active-icon dark:text-white'
                        : 'text-sidebar-heading group-hover:text-sidebar-active dark:text-white/65 dark:group-hover:text-white',
                ].join(' ')}>
                    {item.icon}
                </span>
            )}
            <span className="truncate">{item.label}</span>
            {item.badge > 0 && (
                <span className="ml-auto shrink-0 rounded-full bg-black/10 px-1.5 py-0.5 text-[11px] font-semibold tabular-nums dark:bg-white/15">
                    {item.badge > 99 ? '99+' : item.badge}
                </span>
            )}
            {showActiveDot && isActive && ! (item.badge > 0) && (
                <span className="ml-auto h-1.5 w-1.5 shrink-0 rounded-full bg-sidebar-active-icon dark:bg-white/70" />
            )}
        </>
    );

    const href = item.href ?? (item.route ? route(item.route) : '#');

    if (item.external) {
        return (
            <a href={href} target="_blank" rel="noopener noreferrer" onClick={onClose} className={className}>
                {inner}
            </a>
        );
    }

    return (
        <Link href={href} onClick={onClose} className={className}>
            {inner}
        </Link>
    );
}

/**
 * A group of rows. With no `label` the rows render pinned, with no heading and no
 * collapse — a heading costs roughly the height of the row it introduces, which
 * is not worth paying for the two or three screens a user opens every day.
 *
 * `groupKey` and not the label drives the DOM id: labels are translated and were
 * not unique (two groups were both called "Account"), so deriving the id from the
 * label produced duplicate ids and left the second group's `aria-controls`
 * pointing at the first group's panel.
 */
function NavGroup({ groupKey, label, items, onClose }) {
    const [open, setOpen] = useState(true);
    const panelId = `nav-group-${groupKey}`;

    if (!label) {
        return (
            <div className="mb-0.5 space-y-0.5">
                {items.map((item, i) => (
                    <NavRow key={item.key ?? item.href ?? i} item={item} onClose={onClose} showActiveDot />
                ))}
            </div>
        );
    }

    return (
        <div className="mb-0.5">
            <button
                type="button"
                onClick={() => setOpen((o) => !o)}
                aria-expanded={open}
                aria-controls={panelId}
                className="flex w-full items-center justify-between px-3 py-1.5 mt-3 text-[10px] font-bold uppercase tracking-widest text-sidebar-heading hover:text-sidebar-item dark:text-white/60 dark:hover:text-white transition-colors duration-150 select-none"
            >
                <span>{label}</span>
                <ChevronDown
                    className={[
                        'h-3 w-3 transition-transform duration-200',
                        open ? 'rotate-0' : '-rotate-90',
                    ].join(' ')}
                />
            </button>

            {open && (
                <div id={panelId} className="mt-0.5 space-y-0.5">
                    {items.map((item, i) => (
                        <NavRow key={item.key ?? item.href ?? i} item={item} onClose={onClose} showActiveDot />
                    ))}
                </div>
            )}
        </div>
    );
}

export default function Sidebar({
    navItems = [],
    navGroups = [],
    open = false,
    onClose,
    footer,
    header,
    title,
    logo,
    showCreateButton = true,
}) {
    const { t } = useTranslation();
    const { appName, logoUrl } = useBranding();

    const content = (
        <aside className="flex h-full w-64 flex-col bg-sidebar dark:bg-neutral-900 border-r border-sidebar-border dark:border-white/8">
            {/* Brand header */}
            <div className="flex h-14 shrink-0 items-center gap-2.5 px-4 border-b border-warm-border dark:border-white/8">
                {logoUrl ? (
                    <img src={logoUrl} alt={appName} className="h-7 max-w-[140px] object-contain" />
                ) : logo ? (
                    logo
                ) : (
                    <span className="text-lg font-semibold text-sidebar-item dark:text-white truncate max-w-[200px]">{appName}</span>
                )}
            </div>

            {/* Optional slot under the brand, for a workspace switcher. Opt-in so the
                layouts that already carry one in the Topbar do not show two. It gets
                no wrapper: the content decides whether it draws anything at all, and
                an empty bordered strip would be worse than no strip. */}
            {header}

            {showCreateButton && (
                <div className="shrink-0 p-3 pb-2 border-b border-warm-border dark:border-white/8">
                    <button
                        type="button"
                        className="flex w-full items-center justify-center gap-2 rounded-xl px-3 py-2 text-[13px] font-semibold text-white bg-brand-500 hover:bg-brand-600 transition duration-150"
                    >
                        <Plus className="h-4 w-4" />
                        {t('common.create')}
                    </button>
                </div>
            )}

            <nav className="flex-1 overflow-y-auto px-2 py-2 scrollbar-thin scrollbar-track-transparent">
                {navGroups.length > 0 &&
                    navGroups.map((group, gi) => (
                        <NavGroup
                            // Index-prefixed: group labels are not guaranteed unique
                            // (e.g. two "Account" groups), and a duplicate React key
                            // makes React omit/duplicate siblings, corrupting the
                            // sidebar across SPA navigations.
                            key={`${gi}-${group.key ?? group.label ?? ''}`}
                            groupKey={group.key ?? String(gi)}
                            label={group.label}
                            items={group.items ?? []}
                            onClose={onClose}
                        />
                    ))}

                {navGroups.length === 0 &&
                    navItems.map((item, i) => (
                        item.type === 'divider'
                            ? <hr key={`div-${i}`} className="my-2 border-warm-border dark:border-white/10" />
                            : <NavRow key={item.key ?? item.route ?? item.href ?? i} item={item} onClose={onClose} />
                    ))}
            </nav>

            {footer && (
                <div className="shrink-0 border-t border-warm-border dark:border-white/8 p-3">
                    {footer}
                </div>
            )}
        </aside>
    );

    return (
        <>
            {/* Desktop: always visible */}
            <div className="hidden lg:fixed lg:inset-y-0 lg:z-20 lg:flex lg:w-64 lg:flex-col lg:left-0 rtl:lg:left-auto rtl:lg:right-0">
                {content}
            </div>

            {/* Mobile: overlay + drawer */}
            {open && (
                <div className="fixed inset-0 z-40 lg:hidden">
                    <div className="fixed inset-0 bg-black/60 backdrop-blur-sm" onClick={onClose} aria-hidden="true" />
                    <div className="fixed inset-y-0 left-0 w-64 shadow-2xl rtl:left-auto rtl:right-0">
                        <button
                            type="button"
                            onClick={onClose}
                            className="absolute top-3 right-3 z-10 flex h-7 w-7 items-center justify-center rounded-full bg-warm-gray-100 text-warm-gray-700 hover:bg-warm-gray-200 dark:bg-white/10 dark:text-white/70 dark:hover:bg-white/20 transition"
                            aria-label={t('ui.close_menu')}
                        >
                            <X className="h-4 w-4" />
                        </button>
                        {content}
                    </div>
                </div>
            )}
        </>
    );
}
