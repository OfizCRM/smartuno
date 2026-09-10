import axios from 'axios';
import { X } from 'lucide-react';
import { useEffect, useState } from 'react';
import { useTranslation } from 'react-i18next';

/**
 * Find a client.
 *
 * Lifted out of the document library, which had it as a page-local component
 * used by two of its own dialogs. The offer builder is the third consumer and
 * needs the same thing — the inbox already exposes the search, and a second one
 * would be the same query under a different name.
 *
 * The behaviour is the library's, unchanged: two characters before anything is
 * requested, a 300ms pause after the last keystroke, and a chosen contact
 * collapses the box into a single removable row. What each screen calls the
 * strings is a prop, so the library keeps saying "client" and the offer builder
 * says "contact" without either owning the other's wording.
 *
 * Props:
 *   contact        – the chosen row, or null.
 *   onPick         – called with the chosen row, and with null when cleared.
 *   placeholderKey – translation key for the empty search box.
 *   clearLabelKey  – translation key for the remove button's label.
 *   emptyKey       – translation key shown when a search matches nothing.
 *                    Omitted by default: the library shows nothing at all.
 */
export default function ContactPicker({
    contact,
    onPick,
    placeholderKey = 'documents.search_client',
    clearLabelKey = 'common.clear',
    emptyKey = null,
}) {
    const { t } = useTranslation();
    const [query, setQuery] = useState('');
    const [matches, setMatches] = useState([]);

    useEffect(() => {
        const timer = setTimeout(() => {
            const term = query.trim();
            if (term.length < 2) {
                setMatches([]);

                return;
            }
            axios.get(route('client.inbox.contacts.search'), { params: { q: term } })
                .then(r => setMatches(r.data ?? []))
                .catch(() => setMatches([]));
        }, 300);

        return () => clearTimeout(timer);
    }, [query]);

    const label = (c) => c.company || `${c.first_name ?? ''} ${c.last_name ?? ''}`.trim() || c.email || c.phone_e164;

    if (contact) {
        return (
            <div className="mt-1 flex items-center gap-2 rounded-lg border border-neutral-200 px-3 py-2 dark:border-neutral-600">
                <span className="min-w-0 flex-1 truncate text-sm">{label(contact)}</span>
                <button type="button" onClick={() => onPick(null)} aria-label={t(clearLabelKey)}
                    className="rounded p-1 text-neutral-400 hover:text-coral-600">
                    <X className="h-3.5 w-3.5" />
                </button>
            </div>
        );
    }

    const searched = query.trim().length >= 2;

    return (
        <>
            <input value={query} onChange={e => setQuery(e.target.value)}
                placeholder={t(placeholderKey)}
                className="mt-1 w-full rounded-lg border-neutral-300 text-sm dark:border-neutral-600 dark:bg-neutral-800" />
            {matches.length > 0 && (
                <div className="mt-1 max-h-40 overflow-y-auto rounded-lg border border-neutral-200 dark:border-neutral-600">
                    {matches.map(c => (
                        <button key={c.id} type="button"
                            onClick={() => { onPick(c); setQuery(''); setMatches([]); }}
                            className="block w-full truncate px-3 py-2 text-left text-sm hover:bg-neutral-50 dark:hover:bg-neutral-800">
                            {label(c)}
                        </button>
                    ))}
                </div>
            )}
            {emptyKey && searched && matches.length === 0 && (
                <p className="mt-1 px-1 text-xs text-neutral-500 dark:text-neutral-400">{t(emptyKey)}</p>
            )}
        </>
    );
}
