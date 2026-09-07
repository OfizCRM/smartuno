/**
 * A textarea shaped like the ui/Input primitive, which has no textarea sibling.
 *
 * Local to the company form on purpose: promoting it to @/Components/ui would
 * mean committing to it everywhere, and the three long-text fields on this page
 * are the only place in the client area that needs one today.
 */
export default function Textarea({ label, hint, error, id, name, rows = 3, ...props }) {
    const fieldId = id || name;

    return (
        <div className="w-full">
            {label && (
                <label htmlFor={fieldId} className="mb-1 block text-sm font-medium text-neutral-700 dark:text-neutral-300">
                    {label}
                </label>
            )}
            <textarea
                id={fieldId}
                name={name}
                rows={rows}
                className="w-full rounded-lg border border-neutral-300 bg-white px-3 py-2 text-sm focus:border-brand-500 focus:ring-brand-500/20 dark:border-neutral-600 dark:bg-neutral-800"
                {...props}
            />
            {error && <p className="mt-1 text-xs text-coral-600">{error}</p>}
            {hint && !error && <p className="mt-1 text-xs text-ink-muted dark:text-neutral-400">{hint}</p>}
        </div>
    );
}
