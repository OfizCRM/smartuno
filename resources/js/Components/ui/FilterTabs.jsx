import { useTranslation } from 'react-i18next';

/**
 * The segmented filter strip that sits above a list.
 *
 * Three screens had grown their own copy of this — the document library, the
 * contact record and the inbox thread — and the offers list would have been the
 * fourth. The look here is the document library's, which is the newest of the
 * three and the one the offers mockups were drawn against: pills inside a
 * single rounded frame, the active one filled in brand green, the count trailing
 * its label in dimmed tabular figures.
 *
 * A count of zero is not rendered. On a filter strip a "0" reads as a broken
 * counter rather than as an empty bucket, and the tab itself already says the
 * bucket exists.
 *
 * Props:
 *   tabs     – [{ key, label, count }]. `label` arrives translated; this
 *              component does not know what the buckets are called.
 *              `key` may be null, which is the conventional "all" tab.
 *   active   – the key currently selected (null for the "all" tab).
 *   onChange – called with the key of the tab that was clicked.
 */
export default function FilterTabs({ tabs = [], active = null, onChange }) {
    const { t } = useTranslation();
    const current = active ?? null;

    if (tabs.length === 0) return null;

    return (
        <div
            role="group"
            aria-label={t('common.tabs')}
            className="flex gap-1 rounded-xl border border-neutral-200 p-1 dark:border-neutral-700"
        >
            {tabs.map(({ key = null, label, count }) => {
                const isActive = (key ?? null) === current;

                return (
                    <button
                        key={key ?? 'all'}
                        type="button"
                        aria-pressed={isActive}
                        onClick={() => onChange?.(key)}
                        className={`rounded-lg px-3 py-1.5 text-xs font-medium transition ${
                            isActive
                                ? 'bg-brand-600 text-white'
                                : 'text-neutral-600 hover:bg-neutral-100 dark:text-neutral-300 dark:hover:bg-neutral-800'
                        }`}
                    >
                        {label}
                        {count > 0 && <span className="ml-1 tabular-nums opacity-70">{count}</span>}
                    </button>
                );
            })}
        </div>
    );
}
