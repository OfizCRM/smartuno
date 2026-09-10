import axios from 'axios';
import { Package, Search, X } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import { useTranslation } from 'react-i18next';
import EmptyState from '@/Components/EmptyState';

/**
 * Pick a line from the catalogue.
 *
 * The inbox already has this search as its "share a product" popover, but that
 * one posts the choice to a conversation the moment you click a row. An offer
 * needs the row itself — a name, a unit and a price to copy onto a line — so
 * this returns the selection and lets the caller decide what it is for.
 *
 * What is kept from the inbox's copy, because it was learnt there: a 250ms
 * pause so a five-letter search is one request and not five; an `active` flag
 * so a reply that lands after the box is gone is dropped instead of setting
 * state on nothing; and a route resolved inside a try/catch, because a module
 * that is not installed must leave a dead picker rather than a blank screen.
 *
 * Props:
 *   onPick      – called with the chosen row: { id, name, sku, price, currency,
 *                 inventory_quantity, image_url, source }. `price` is the
 *                 decimal string the endpoint returns, not bani — converting it
 *                 is the caller's business, and the server's authority.
 *   endpoint    – a route name (resolved through Ziggy) or a plain URL.
 *   placeholder – already-translated placeholder text.
 *   type        – narrow the search to one catalogue type, sent as ?type=. The
 *                 offer editor asks for 'bundle' with it; left out, the endpoint
 *                 decides what it returns and nothing is added to the query.
 */
export default function ItemPicker({ onPick, endpoint = 'client.catalog.search', placeholder, type = null }) {
    const { t } = useTranslation();
    const ref = useRef(null);
    const [query, setQuery] = useState('');
    const [items, setItems] = useState([]);
    const [loading, setLoading] = useState(false);
    const [open, setOpen] = useState(false);

    // Opening the list, and every keystroke in it, is what puts it into its
    // loading state — not the effect below. A request is 250ms away at that
    // point, and a list that said "nothing found" for those 250ms would be
    // answering a question it has not asked yet.
    const openList = () => { setOpen(true); setLoading(true); };

    // Clicking away puts the list back down. The search box stays where it is —
    // it is part of the form, not a menu that appears and disappears.
    useEffect(() => {
        const handler = (e) => {
            if (ref.current && !ref.current.contains(e.target)) setOpen(false);
        };
        document.addEventListener('mousedown', handler);

        return () => document.removeEventListener('mousedown', handler);
    }, []);

    useEffect(() => {
        if (!open) return undefined;

        let active = true;
        const timer = setTimeout(() => {
            let url;
            try {
                url = /^(https?:)?\//.test(endpoint) ? endpoint : route(endpoint);
            } catch {
                if (active) { setItems([]); setLoading(false); }

                return;
            }

            axios.get(url, { params: type ? { q: query, type } : { q: query } })
                .then(r => {
                    if (!active) return;
                    setItems(Array.isArray(r.data) ? r.data : []);
                })
                .catch(() => { if (active) setItems([]); })
                .finally(() => { if (active) setLoading(false); });
        }, 250);

        return () => { active = false; clearTimeout(timer); };
    }, [query, endpoint, open, type]);

    const choose = (item) => {
        onPick?.(item);
        setQuery('');
        setItems([]);
        setOpen(false);
    };

    return (
        <div ref={ref} className="relative">
            <Search className="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-neutral-400" aria-hidden />
            <input
                value={query}
                onChange={e => { setQuery(e.target.value); openList(); }}
                onFocus={openList}
                placeholder={placeholder ?? t('offers.search_item')}
                className="w-full rounded-xl border-neutral-300 py-2 pl-10 pr-9 text-sm dark:border-neutral-600 dark:bg-neutral-800"
            />
            {query !== '' && (
                <button
                    type="button"
                    onClick={() => { setQuery(''); setItems([]); openList(); }}
                    aria-label={t('common.clear')}
                    className="absolute right-2 top-1/2 -translate-y-1/2 rounded p-1 text-neutral-400 transition hover:text-neutral-600 dark:hover:text-neutral-300"
                >
                    <X className="h-4 w-4" />
                </button>
            )}

            {open && (
                <div className="absolute left-0 right-0 top-full z-20 mt-1 max-h-72 overflow-y-auto rounded-xl border border-neutral-200 bg-white shadow-xl dark:border-neutral-700 dark:bg-neutral-900">
                    {loading ? (
                        <p className="py-6 text-center text-sm text-neutral-400">{t('common.loading')}</p>
                    ) : items.length === 0 ? (
                        <div className="py-4">
                            <EmptyState icon={<Package className="h-7 w-7" />} title={t('offers.no_items')} />
                        </div>
                    ) : items.map(item => (
                        <button
                            key={`${item.source ?? 'catalog'}:${item.id}`}
                            type="button"
                            onClick={() => choose(item)}
                            className="flex w-full items-center gap-3 border-b border-neutral-100 px-3 py-2 text-left transition last:border-0 hover:bg-brand-50 dark:border-neutral-800 dark:hover:bg-brand-900/20"
                        >
                            {item.image_url
                                ? <img src={item.image_url} alt="" className="h-10 w-10 shrink-0 rounded-lg object-cover" />
                                : <div className="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-neutral-200 dark:bg-neutral-700"><Package className="h-4 w-4 text-neutral-400" aria-hidden /></div>}
                            <div className="min-w-0 flex-1">
                                <p className="truncate text-sm font-medium text-neutral-800 dark:text-neutral-100">{item.name}</p>
                                <p className="truncate text-xs text-neutral-500 dark:text-neutral-400">
                                    {item.sku ? `${item.sku} · ` : ''}{item.price}{item.currency ? ` ${item.currency}` : ''}
                                </p>
                            </div>
                        </button>
                    ))}
                </div>
            )}
        </div>
    );
}
