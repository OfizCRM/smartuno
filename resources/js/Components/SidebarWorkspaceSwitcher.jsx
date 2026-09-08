import { Link, router, usePage } from '@inertiajs/react';
import { useTranslation } from 'react-i18next';
import { ChevronsUpDown } from 'lucide-react';
import { Dropdown } from '@/Components/ui';

/**
 * Workspace switcher sized for the sidebar rail.
 *
 * Topbar carries one too, but the inbox never renders a Topbar — which is why
 * a person working in the inbox cannot tell which workspace they are in, let
 * alone change it. Rather than bolt a Topbar onto the inbox shell, the control
 * moves to where the design puts it: the top of the rail.
 *
 * Both use the same shared props and the same POST, so switching behaves
 * identically wherever it is rendered.
 */
export default function SidebarWorkspaceSwitcher() {
    const { t } = useTranslation();
    const { currentWorkspace, workspaces = [] } = usePage().props;

    const switchTo = (id) => {
        if (id === currentWorkspace?.id) return;
        router.post(route('client.workspaces.switch'), { workspace_id: id }, { preserveScroll: true });
    };

    // A single workspace is the normal case for this product's customers — the
    // installer names it after the owner, so the row would print the same name
    // the user card at the foot of the rail already shows. A switcher with
    // nothing to switch between is a control that costs space and teaches
    // nothing, so it renders nothing at all.
    if (workspaces.length < 2) {
        return null;
    }

    return (
        <div className="border-b border-warm-border p-2 dark:border-white/8">
        <Dropdown>
            <Dropdown.Trigger>
                <button
                    type="button"
                    aria-label={t('topbar.workspace')}
                    className="flex w-full items-center gap-2 rounded-xl px-3 py-2 text-[13px] transition-colors hover:bg-sidebar-hover dark:hover:bg-white/10"
                >
                    <span className="flex h-6 w-6 shrink-0 items-center justify-center rounded-lg bg-brand-500 text-[11px] font-bold text-white">
                        {(currentWorkspace?.name ?? '?')[0]?.toUpperCase()}
                    </span>
                    <span className="min-w-0 flex-1 truncate text-left font-semibold text-sidebar-item dark:text-white">
                        {currentWorkspace?.name ?? t('topbar.no_workspace')}
                    </span>
                    <ChevronsUpDown className="h-3.5 w-3.5 shrink-0 text-sidebar-heading dark:text-white/60" />
                </button>
            </Dropdown.Trigger>
            <Dropdown.Content align="left" width="56">
                {workspaces.map((w) => (
                    <Dropdown.Item
                        key={w.id}
                        as="button"
                        onClick={() => switchTo(w.id)}
                        className={currentWorkspace?.id === w.id ? 'bg-brand-50 font-medium text-brand-700 dark:bg-brand-900/30 dark:text-brand-300' : ''}
                    >
                        {w.name}
                    </Dropdown.Item>
                ))}
                <Dropdown.Divider />
                <Link
                    href={route('client.workspaces.index')}
                    className="block px-4 py-2.5 text-sm text-neutral-600 hover:bg-neutral-50 dark:text-neutral-400 dark:hover:bg-neutral-800"
                >
                    {t('topbar.manage_workspaces')}
                </Link>
            </Dropdown.Content>
        </Dropdown>
        </div>
    );
}
