import { Link } from '@inertiajs/react';
import { useTranslation } from 'react-i18next';
import { ChevronLeft } from 'lucide-react';

/**
 * "Back to Settings" for any page reached from the settings hub.
 *
 * The hub is a hub-and-spoke: rows open full pages at their own routes rather
 * than tabs. That only works if every spoke offers a way back — without one you
 * have to re-open Settings from the sidebar to reach the next row, which is
 * exactly the friction the hub was meant to remove.
 *
 * Add this to any page you link from Hub.jsx, and nowhere else.
 */
export default function SettingsBackLink({ className = '' }) {
    const { t } = useTranslation();

    let href;
    try {
        href = route('client.settings.index');
    } catch {
        return null;
    }

    return (
        <Link
            href={href}
            className={[
                'inline-flex items-center gap-1 text-sm text-ink-muted transition-colors duration-150 hover:text-brand-600 dark:text-neutral-400 dark:hover:text-brand-300',
                className,
            ].join(' ')}
        >
            <ChevronLeft className="h-4 w-4" />
            {t('settings_hub.title')}
        </Link>
    );
}
