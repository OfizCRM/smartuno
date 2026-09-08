import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import { CheckSquare, Download, Layers, Minus, Plus, Search, Square, Table2, Tag, Trash2, Upload, Users, X } from 'lucide-react';
import { useCallback, useState } from 'react';
import { useTranslation } from 'react-i18next';
import ClientLayout from '@/Layouts/ClientLayout';
import EmptyState from '@/Components/EmptyState';
import { ChannelBrandIcon } from '@/Components/BrandIcons';
import { formatInTz } from '@/Utils/datetime';
import { relativeTime } from '@/Utils/relativeTime';
import ImportCsvModal from './ImportCsvModal';

/**
 * The contact list.
 *
 * The "Channels" column used to render the three opt_in_* flags. Those say
 * whether you may message someone, not where they talk to you — and their
 * defaults make them nearly meaningless (opt_in_email defaults to true for
 * everyone, and the CSV import sets the WhatsApp and SMS flags from the mere
 * presence of a phone number). It now shows the channels a contact has actually
 * written on, computed server-side; consent moved to its own tab on the record.
 *
 * "Last seen" comes from the same aggregate, not from contacts.last_seen_at:
 * that column exists but nothing in the application ever writes it.
 */

/**
 * Where a contact stands, mirrored from Contact::STATUSES.
 *
 * 'inactive' is grey rather than red: someone who has gone quiet is not a
 * problem to fix, and a wall of red badges would say the business is failing.
 */
const STATUS_META = {
    lead: { labelKey: 'contacts_page.status_lead', className: 'bg-accent-100 text-accent-800 dark:bg-accent-900/30 dark:text-accent-200' },
    negotiating: { labelKey: 'contacts_page.status_negotiating', className: 'bg-secondary-100 text-secondary-800 dark:bg-secondary-900/40 dark:text-secondary-200' },
    client: { labelKey: 'contacts_page.status_client', className: 'bg-brand-50 text-brand-700 dark:bg-brand-900/30 dark:text-brand-300' },
    inactive: { labelKey: 'contacts_page.status_inactive', className: 'bg-neutral-100 text-neutral-500 dark:bg-neutral-800 dark:text-neutral-400' },
};

const STATUS_CHIPS = [
    { key: null, countKey: 'all', labelKey: 'contacts_page.chip_all' },
    { key: 'client', countKey: 'client', labelKey: 'contacts_page.chip_client' },
    { key: 'negotiating', countKey: 'negotiating', labelKey: 'contacts_page.chip_negotiating' },
    { key: 'lead', countKey: 'lead', labelKey: 'contacts_page.chip_lead' },
    { key: 'inactive', countKey: 'inactive', labelKey: 'contacts_page.chip_inactive' },
];

export function StatusBadge({ status }) {
    const { t } = useTranslation();
    const meta = STATUS_META[status];

    if (! meta) {
        return <span className="text-sm text-neutral-300">—</span>;
    }

    return (
        <span className={`inline-block whitespace-nowrap rounded-full px-2 py-0.5 text-[11px] font-medium ${meta.className}`}>
            {t(meta.labelKey)}
        </span>
    );
}

function ContactAvatar({ contact }) {
    const { t } = useTranslation();
    const name = `${contact.first_name ?? ''} ${contact.last_name ?? ''}`.trim();
    const initials = name ? name.split(' ').map(p => p[0]).slice(0, 2).join('').toUpperCase() : '?';

    if (contact.avatar_url) {
        return <img src={contact.avatar_url} alt={name || t('contacts_page.contact_alt')} className="h-9 w-9 shrink-0 rounded-full object-cover" />;
    }

    return (
        <div className="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-brand-100 text-xs font-semibold text-brand-700 dark:bg-brand-900/40 dark:text-brand-300">
            {initials}
        </div>
    );
}

function ContactRow({ contact, activity, selected, onToggle, onDelete, locale, userTz }) {
    const { t } = useTranslation();
    const name = `${contact.first_name ?? ''} ${contact.last_name ?? ''}`.trim();
    const channels = activity?.channels ?? [];
    const lastAt = activity?.last_at ?? null;

    const open = () => router.visit(route('client.contacts.show', contact.uuid));

    return (
        <tr
            onClick={open}
            className="cursor-pointer border-b border-neutral-100 transition-colors last:border-0 hover:bg-neutral-50 dark:border-neutral-800 dark:hover:bg-neutral-800/40"
        >
            <td className="w-10 px-3 py-3" onClick={e => e.stopPropagation()}>
                <button type="button" onClick={() => onToggle(contact.uuid)} aria-label={name || contact.uuid}
                    className="text-neutral-400 transition hover:text-brand-600">
                    {selected ? <CheckSquare className="h-4 w-4 text-brand-600" /> : <Square className="h-4 w-4" />}
                </button>
            </td>

            <td className="px-3 py-3">
                <div className="flex items-center gap-3">
                    <ContactAvatar contact={contact} />
                    <div className="min-w-0">
                        <p className="truncate text-sm font-medium text-neutral-900 dark:text-neutral-100">
                            {name || t('contacts_page.unknown_contact')}
                        </p>
                        {contact.company && (
                            <p className="truncate text-xs text-ink-muted dark:text-neutral-400">{contact.company}</p>
                        )}
                    </div>
                </div>
            </td>

            <td className="px-3 py-3">
                {contact.phone_e164 && <p className="text-sm tabular-nums text-neutral-700 dark:text-neutral-300">{contact.phone_e164}</p>}
                {contact.email && <p className="truncate text-xs text-ink-muted dark:text-neutral-400">{contact.email}</p>}
                {! contact.phone_e164 && ! contact.email && <span className="text-sm text-neutral-300">—</span>}
            </td>

            <td className="px-3 py-3">
                <div className="flex flex-wrap gap-1">
                    {contact.tags?.map(tag => (
                        <span key={tag.id} className="rounded-full px-2 py-0.5 text-[11px] font-medium text-white" style={{ backgroundColor: tag.color }}>
                            {tag.name}
                        </span>
                    ))}
                </div>
            </td>

            <td className="px-3 py-3">
                <div className="flex items-center gap-1.5">
                    {channels.length === 0
                        ? <span className="text-sm text-neutral-300">—</span>
                        : channels.map(ch => <ChannelBrandIcon key={ch} channel={ch} className="h-4 w-4" />)}
                </div>
            </td>

            <td className="whitespace-nowrap px-3 py-3 text-sm text-ink-muted dark:text-neutral-400">
                {lastAt ? (relativeTime(lastAt, locale) ?? formatInTz(lastAt, userTz)) : '—'}
            </td>

            <td className="px-3 py-3">
                <StatusBadge status={contact.status} />
            </td>

            <td className="w-10 px-3 py-3 text-right" onClick={e => e.stopPropagation()}>
                <button type="button" onClick={() => onDelete(contact.uuid)} title={t('contacts_page.delete_contact')}
                    className="rounded p-1 text-neutral-300 transition hover:bg-coral-50 hover:text-coral-600 dark:hover:bg-coral-900/20">
                    <Trash2 className="h-4 w-4" />
                </button>
            </td>
        </tr>
    );
}

function BulkManageModal({ mode, count, tags, segments, processing, onClose, onApply }) {
    const { t } = useTranslation();
    const [action, setAction] = useState('add');
    const [tagNames, setTagNames] = useState(new Set());
    const [segmentIds, setSegmentIds] = useState(new Set());
    const [newTag, setNewTag] = useState('');

    const isTags = mode === 'tags';
    const nothingPicked = isTags ? tagNames.size === 0 : segmentIds.size === 0;

    const toggle = (setter) => (value) => setter(prev => {
        const next = new Set(prev);
        next.has(value) ? next.delete(value) : next.add(value);
        return next;
    });
    const toggleTag = toggle(setTagNames);
    const toggleSegment = toggle(setSegmentIds);

    const addNewTag = () => {
        const name = newTag.trim().slice(0, 64);
        if (!name) return;
        setTagNames(prev => new Set([...prev, name]));
        setNewTag('');
    };

    // Show workspace tags plus any freshly typed names not saved yet.
    const tagOptions = [...new Set([...tags.map(tg => tg.name), ...tagNames])];

    const submit = () => onApply({
        action,
        ...(isTags ? { tag_names: [...tagNames] } : { segment_ids: [...segmentIds] }),
    });

    return (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4" onMouseDown={e => { if (e.target === e.currentTarget) onClose(); }}>
            <div className="w-full max-w-md rounded-xl bg-white dark:bg-neutral-900 p-6 shadow-xl space-y-4">
                <div className="flex items-start justify-between">
                    <h3 className="text-lg font-semibold text-neutral-900 dark:text-neutral-100 flex items-center gap-2">
                        {isTags ? <Tag className="h-5 w-5 text-brand-600" /> : <Layers className="h-5 w-5 text-brand-600" />}
                        {isTags ? t('contacts_page.bulk_tags_title', { count }) : t('contacts_page.bulk_segments_title', { count })}
                    </h3>
                    <button type="button" onClick={onClose} className="text-neutral-400 hover:text-neutral-600 dark:hover:text-neutral-200 transition">
                        <X className="h-5 w-5" />
                    </button>
                </div>

                {/* Add / Remove toggle */}
                <div className="grid grid-cols-2 gap-1 rounded-lg bg-neutral-100 dark:bg-neutral-800 p-1">
                    {[
                        ['add', t('contacts_page.bulk_action_add'), Plus],
                        ['remove', t('contacts_page.bulk_action_remove'), Minus],
                    ].map(([value, label, Icon]) => (
                        <button
                            key={value}
                            type="button"
                            onClick={() => setAction(value)}
                            className={`flex items-center justify-center gap-1.5 rounded-md px-3 py-1.5 text-sm font-medium transition
                                ${action === value
                                    ? 'bg-white dark:bg-neutral-900 text-brand-700 dark:text-brand-300 shadow-sm'
                                    : 'text-neutral-500 dark:text-neutral-400 hover:text-neutral-700 dark:hover:text-neutral-200'}`}
                        >
                            <Icon className="h-3.5 w-3.5" /> {label}
                        </button>
                    ))}
                </div>

                {isTags ? (
                    <div className="space-y-3">
                        {action === 'add' && (
                            <div className="flex gap-2">
                                <input
                                    type="text"
                                    value={newTag}
                                    onChange={e => setNewTag(e.target.value)}
                                    onKeyDown={e => { if (e.key === 'Enter') { e.preventDefault(); addNewTag(); } }}
                                    placeholder={t('contacts_page.bulk_new_tag_placeholder')}
                                    className="flex-1 rounded-lg border border-neutral-300 dark:border-neutral-600 bg-white dark:bg-neutral-800 px-3 py-1.5 text-sm text-neutral-900 dark:text-neutral-100 focus:outline-none focus:ring-2 focus:ring-brand-500"
                                />
                                <button type="button" onClick={addNewTag} className="rounded-lg border border-neutral-300 dark:border-neutral-600 px-3 py-1.5 text-sm text-neutral-700 dark:text-neutral-300 hover:bg-neutral-50 dark:hover:bg-neutral-800 transition">
                                    {t('contacts_page.bulk_action_add')}
                                </button>
                            </div>
                        )}
                        <div className="flex flex-wrap gap-2 max-h-40 overflow-y-auto">
                            {tagOptions.length === 0 && (
                                <p className="text-xs text-neutral-500 dark:text-neutral-400">{t('contacts_page.bulk_no_tags')}</p>
                            )}
                            {tagOptions.map(name => {
                                const checked = tagNames.has(name);
                                return (
                                    <button
                                        key={name}
                                        type="button"
                                        onClick={() => toggleTag(name)}
                                        className={`rounded-full border px-3 py-1 text-xs transition ${checked
                                            ? 'border-brand-500 bg-brand-50 dark:bg-brand-900/30 text-brand-700 dark:text-brand-300'
                                            : 'border-neutral-300 dark:border-neutral-600 text-neutral-600 dark:text-neutral-400 hover:border-brand-400'}`}
                                    >
                                        {name}
                                    </button>
                                );
                            })}
                        </div>
                    </div>
                ) : (
                    <div className="flex flex-wrap gap-2 max-h-40 overflow-y-auto">
                        {segments.length === 0 && (
                            <p className="text-xs text-neutral-500 dark:text-neutral-400">{t('contacts_page.bulk_no_segments')}</p>
                        )}
                        {segments.map(seg => {
                            const checked = segmentIds.has(seg.id);
                            return (
                                <button
                                    key={seg.id}
                                    type="button"
                                    onClick={() => toggleSegment(seg.id)}
                                    className={`rounded-full border px-3 py-1 text-xs transition ${checked
                                        ? 'border-brand-500 bg-brand-50 dark:bg-brand-900/30 text-brand-700 dark:text-brand-300'
                                        : 'border-neutral-300 dark:border-neutral-600 text-neutral-600 dark:text-neutral-400 hover:border-brand-400'}`}
                                >
                                    {seg.name}
                                </button>
                            );
                        })}
                    </div>
                )}

                <div className="flex gap-2 pt-1">
                    <button
                        type="button"
                        onClick={submit}
                        disabled={nothingPicked || processing}
                        className="flex-1 rounded-lg bg-brand-600 py-2 text-sm font-medium text-white hover:bg-brand-700 disabled:opacity-50 disabled:cursor-not-allowed transition"
                    >
                        {processing ? t('common.saving') : t('contacts_page.bulk_apply', { count })}
                    </button>
                    <button type="button" onClick={onClose} className="rounded-lg border border-neutral-300 dark:border-neutral-600 px-4 py-2 text-sm text-neutral-700 dark:text-neutral-300 hover:bg-neutral-50 dark:hover:bg-neutral-800 transition">
                        {t('common.cancel')}
                    </button>
                </div>
            </div>
        </div>
    );
}


export default function ContactsIndex({ contacts, filters = {}, tags = [], segments = [], activity = {}, statusCounts = {} }) {
    const { t, i18n } = useTranslation();
    const { props } = usePage();
    const flash = props.flash ?? {};
    const userTz = props.timezone || 'Europe/Bucharest';

    const [search, setSearch] = useState(filters.search ?? '');
    const [showAdd, setShowAdd] = useState(false);
    const [showImport, setShowImport] = useState(false);
    const [bulkModal, setBulkModal] = useState(null); // null | 'tags' | 'segments'
    const [bulkProcessing, setBulkProcessing] = useState(false);
    const [selected, setSelected] = useState(new Set());

    const { data, setData, post, processing, reset } = useForm({
        first_name: '', last_name: '', phone_e164: '', email: '', company: '',
        opt_in_whatsapp: true, opt_in_sms: true, opt_in_email: true,
        segment_ids: [],
    });

    const rows = contacts.data ?? [];
    const allUuids = rows.map(c => c.uuid);
    const allSelected = allUuids.length > 0 && allUuids.every(id => selected.has(id));
    const someSelected = selected.size > 0;

    const toggleAll = useCallback(() => {
        setSelected(prev => {
            const next = new Set(prev);
            allUuids.forEach(id => (allSelected ? next.delete(id) : next.add(id)));

            return next;
        });
    }, [allSelected, allUuids]);

    const toggleOne = useCallback((uuid) => {
        setSelected(prev => {
            const next = new Set(prev);
            next.has(uuid) ? next.delete(uuid) : next.add(uuid);

            return next;
        });
    }, []);

    const clearSelection = () => setSelected(new Set());

    const handleSearch = (e) => {
        e.preventDefault();
        router.get(route('client.contacts.index'), { search }, { preserveState: true, replace: true });
    };

    const handleDelete = (uuid) => {
        if (confirm(t('contacts_page.confirm_delete_one'))) {
            router.delete(route('client.contacts.destroy', uuid), { preserveScroll: true });
        }
    };

    const handleBulkDelete = () => {
        if (! confirm(t('contacts_page.confirm_delete_selected', { count: selected.size }))) return;
        router.delete(route('client.contacts.bulk-destroy'), {
            data: { uuids: [...selected] },
            preserveScroll: true,
            onSuccess: () => clearSelection(),
        });
    };

    const handleExport = (selectedOnly = false) => {
        const params = new URLSearchParams();
        if (selectedOnly && someSelected) {
            params.set('uuids', [...selected].join(','));
        } else if (filters.search) {
            params.set('search', filters.search);
        }
        window.location.href = route('client.contacts.export') + (params.toString() ? '?' + params.toString() : '');
    };

    const applyBulk = (payload) => {
        const routeName = bulkModal === 'tags' ? 'client.contacts.bulk-tags' : 'client.contacts.bulk-segments';
        setBulkProcessing(true);
        router.post(route(routeName), { uuids: [...selected], ...payload }, {
            preserveScroll: true,
            onSuccess: () => { setBulkModal(null); clearSelection(); },
            onFinish: () => setBulkProcessing(false),
        });
    };

    // Consent follows the identifier: ticking "may we WhatsApp them" for someone
    // with no phone number would be a promise nothing can keep.
    const setPhone = (value) => setData(prev => ({
        ...prev,
        phone_e164: value,
        opt_in_whatsapp: value.trim() ? prev.opt_in_whatsapp : false,
        opt_in_sms: value.trim() ? prev.opt_in_sms : false,
    }));

    const setEmail = (value) => setData(prev => ({
        ...prev,
        email: value,
        opt_in_email: value.trim() ? prev.opt_in_email : false,
    }));

    const submitAdd = (e) => {
        e.preventDefault();
        post(route('client.contacts.store'), { onSuccess: () => { reset(); setShowAdd(false); } });
    };

    const canSubmit = data.phone_e164.trim() !== '' || data.email.trim() !== '';

    return (
        <ClientLayout title={t('contacts_page.title')}>
            <Head title={t('contacts_page.title')} />

            <div className="space-y-4">
                <div className="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <h1 className="text-2xl font-bold text-neutral-900 dark:text-white">{t('contacts_page.title')}</h1>
                        <p className="mt-1 text-sm text-ink-muted dark:text-neutral-400">{t('contacts_page.subtitle')}</p>
                    </div>

                    <div className="flex flex-wrap gap-2">
                        <Link href={route('client.segments.index')}
                            className="inline-flex items-center gap-1.5 rounded-xl border border-neutral-300 px-3 py-2 text-sm text-neutral-700 transition hover:bg-neutral-50 dark:border-neutral-600 dark:text-neutral-300 dark:hover:bg-neutral-800">
                            <Layers className="h-4 w-4" /> {t('contacts_page.segments')}
                        </Link>
                        <Link href={route('client.contacts.bulk-import')}
                            className="inline-flex items-center gap-1.5 rounded-xl border border-neutral-300 px-3 py-2 text-sm text-neutral-700 transition hover:bg-neutral-50 dark:border-neutral-600 dark:text-neutral-300 dark:hover:bg-neutral-800">
                            <Table2 className="h-4 w-4" /> {t('contacts_page.bulk_import')}
                        </Link>
                        <button type="button" onClick={() => setShowImport(true)}
                            className="inline-flex items-center gap-1.5 rounded-xl border border-neutral-300 px-3 py-2 text-sm text-neutral-700 transition hover:bg-neutral-50 dark:border-neutral-600 dark:text-neutral-300 dark:hover:bg-neutral-800">
                            <Upload className="h-4 w-4" /> {t('contacts_page.import_list')}
                        </button>
                        <button type="button" onClick={() => handleExport(false)}
                            className="inline-flex items-center gap-1.5 rounded-xl border border-neutral-300 px-3 py-2 text-sm text-neutral-700 transition hover:bg-neutral-50 dark:border-neutral-600 dark:text-neutral-300 dark:hover:bg-neutral-800">
                            <Download className="h-4 w-4" /> {t('contacts_page.download_all')}
                        </button>
                        <button type="button" onClick={() => setShowAdd(v => ! v)}
                            className="inline-flex items-center gap-1.5 rounded-xl bg-brand-600 px-4 py-2 text-sm font-semibold text-white transition hover:bg-brand-700">
                            <Plus className="h-4 w-4" /> {t('contacts_page.new_contact')}
                        </button>
                    </div>
                </div>

                {flash.success && (
                    <div className="rounded-xl bg-brand-50 px-4 py-3 text-sm text-brand-800 dark:bg-brand-900/25 dark:text-brand-200">{flash.success}</div>
                )}

                {/* Counts are of the whole workspace, not of the current search: the
                    chips are how you narrow, so a number that moved while you typed
                    would describe a list you had already left. */}
                <div className="flex flex-wrap gap-2">
                    {STATUS_CHIPS.map(({ key, countKey, labelKey }) => {
                        const active = (filters.status ?? null) === key;
                        const count = statusCounts[countKey];

                        return (
                            <button key={key ?? 'all'} type="button"
                                onClick={() => router.get(route('client.contacts.index'), { ...filters, status: key ?? undefined }, { preserveState: true, replace: true })}
                                className={`inline-flex items-center gap-1.5 rounded-full px-3 py-1.5 text-sm font-medium transition ${
                                    active
                                        ? 'bg-brand-600 text-white'
                                        : 'border border-neutral-200 bg-white text-neutral-600 hover:bg-neutral-50 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-300'
                                }`}>
                                {t(labelKey)}
                                {count > 0 && <span className="tabular-nums opacity-70">{count}</span>}
                            </button>
                        );
                    })}
                </div>

                <form onSubmit={handleSearch} className="relative">
                    <Search className="pointer-events-none absolute left-4 top-1/2 h-4 w-4 -translate-y-1/2 text-neutral-400" />
                    <input
                        value={search}
                        onChange={e => setSearch(e.target.value)}
                        placeholder={t('contacts_page.search_with_company')}
                        className="w-full rounded-xl border border-neutral-200 bg-white py-3 pl-11 pr-4 text-sm placeholder-neutral-400 focus:border-brand-400 focus:outline-none focus:ring-2 focus:ring-brand-500/20 dark:border-neutral-700 dark:bg-neutral-900"
                    />
                </form>

                {/* Create — an inline panel rather than an overlay: adding a contact is
                    a small, frequent job and a dialog over the list is heavier than it
                    needs to be. */}
                {showAdd && (
                    <form onSubmit={submitAdd} className="rounded-2xl border border-neutral-200 bg-white p-5 dark:border-neutral-700 dark:bg-neutral-900">
                        <div className="flex items-start justify-between gap-3">
                            <div>
                                <h2 className="text-base font-semibold text-neutral-900 dark:text-neutral-100">{t('contacts_page.new_contact')}</h2>
                                <p className="mt-0.5 text-sm text-ink-muted dark:text-neutral-400">{t('contacts_page.new_contact_hint')}</p>
                            </div>
                            <button type="button" onClick={() => setShowAdd(false)} aria-label={t('common.cancel')}
                                className="rounded p-1 text-neutral-400 transition hover:text-neutral-600 dark:hover:text-neutral-200">
                                <X className="h-4 w-4" />
                            </button>
                        </div>

                        <div className="mt-4 grid gap-4 sm:grid-cols-2">
                            {[
                                ['first_name', t('contacts_page.first_name'), 'ex. Elena', v => setData('first_name', v)],
                                ['last_name', t('contacts_page.last_name'), 'ex. Pop', v => setData('last_name', v)],
                                ['phone_e164', t('contacts_page.col_phone'), '+40 7xx xxx xxx', setPhone],
                                ['email', t('common.email'), 'nume@exemplu.ro', setEmail],
                                ['company', t('contacts_page.company_optional'), 'ex. Cofetăria Ana', v => setData('company', v)],
                            ].map(([key, label, placeholder, onChange]) => (
                                <div key={key} className={key === 'company' ? 'sm:col-span-2' : undefined}>
                                    <label className="mb-1 block text-sm font-medium text-neutral-700 dark:text-neutral-300">{label}</label>
                                    <input
                                        value={data[key]}
                                        placeholder={placeholder}
                                        onChange={e => onChange(e.target.value)}
                                        className="w-full rounded-xl border border-neutral-300 bg-white px-3 py-2 text-sm focus:border-brand-400 focus:outline-none focus:ring-2 focus:ring-brand-500/20 dark:border-neutral-600 dark:bg-neutral-800"
                                    />
                                </div>
                            ))}
                        </div>

                        {segments.length > 0 && (
                            <div className="mt-4">
                                <label className="mb-1.5 block text-sm font-medium text-neutral-700 dark:text-neutral-300">{t('contacts_page.segments')}</label>
                                <div className="flex flex-wrap gap-2">
                                    {segments.map(seg => {
                                        const on = data.segment_ids.includes(seg.id);

                                        return (
                                            <button key={seg.id} type="button"
                                                onClick={() => setData('segment_ids', on
                                                    ? data.segment_ids.filter(id => id !== seg.id)
                                                    : [...data.segment_ids, seg.id])}
                                                className={`rounded-full border px-3 py-1 text-xs transition ${
                                                    on
                                                        ? 'border-brand-500 bg-brand-50 text-brand-700 dark:bg-brand-900/30 dark:text-brand-300'
                                                        : 'border-neutral-300 text-neutral-600 hover:border-brand-400 dark:border-neutral-600 dark:text-neutral-400'
                                                }`}>
                                                {seg.name}
                                            </button>
                                        );
                                    })}
                                </div>
                            </div>
                        )}

                        <div className="mt-5 flex items-center gap-3">
                            <button type="submit" disabled={processing || ! canSubmit}
                                title={canSubmit ? undefined : t('contacts_page.alert_phone_or_email')}
                                className="rounded-xl bg-brand-600 px-4 py-2 text-sm font-semibold text-white transition hover:bg-brand-700 disabled:opacity-50">
                                {processing ? t('common.saving') : t('contacts_page.save_contact')}
                            </button>
                            <button type="button" onClick={() => { reset(); setShowAdd(false); }}
                                className="rounded-xl border border-neutral-300 px-4 py-2 text-sm font-medium text-neutral-600 transition hover:bg-neutral-50 dark:border-neutral-600 dark:text-neutral-300 dark:hover:bg-neutral-800">
                                {t('common.cancel')}
                            </button>
                            {! canSubmit && (
                                <span className="text-xs text-ink-muted">{t('contacts_page.alert_phone_or_email')}</span>
                            )}
                        </div>
                    </form>
                )}

                {someSelected && (
                    <div className="flex flex-wrap items-center gap-2 rounded-xl border border-brand-200 bg-brand-50 px-4 py-2.5 dark:border-brand-900/50 dark:bg-brand-900/20">
                        <span className="text-sm font-medium text-brand-800 dark:text-brand-200">
                            {t('contacts_page.n_selected', { count: selected.size })}
                        </span>
                        <div className="ml-auto flex flex-wrap gap-2">
                            <button type="button" onClick={() => setBulkModal('tags')}
                                className="inline-flex items-center gap-1.5 rounded-lg border border-neutral-300 bg-white px-2.5 py-1.5 text-xs text-neutral-700 transition hover:bg-neutral-50 dark:border-neutral-600 dark:bg-neutral-800 dark:text-neutral-300">
                                <Tag className="h-3.5 w-3.5" /> {t('contacts_page.bulk_tags_btn')}
                            </button>
                            <button type="button" onClick={() => setBulkModal('segments')}
                                className="inline-flex items-center gap-1.5 rounded-lg border border-neutral-300 bg-white px-2.5 py-1.5 text-xs text-neutral-700 transition hover:bg-neutral-50 dark:border-neutral-600 dark:bg-neutral-800 dark:text-neutral-300">
                                <Layers className="h-3.5 w-3.5" /> {t('contacts_page.bulk_segments_btn')}
                            </button>
                            <button type="button" onClick={() => handleExport(true)}
                                className="inline-flex items-center gap-1.5 rounded-lg border border-neutral-300 bg-white px-2.5 py-1.5 text-xs text-neutral-700 transition hover:bg-neutral-50 dark:border-neutral-600 dark:bg-neutral-800 dark:text-neutral-300">
                                <Download className="h-3.5 w-3.5" /> {t('contacts_page.export_selected')}
                            </button>
                            <button type="button" onClick={handleBulkDelete}
                                className="inline-flex items-center gap-1.5 rounded-lg border border-coral-200 bg-white px-2.5 py-1.5 text-xs text-coral-600 transition hover:bg-coral-50 dark:border-coral-900/50 dark:bg-neutral-800">
                                <Trash2 className="h-3.5 w-3.5" /> {t('contacts_page.delete_selected')}
                            </button>
                            <button type="button" onClick={clearSelection}
                                className="rounded-lg px-2 py-1.5 text-xs text-neutral-500 transition hover:text-neutral-700 dark:hover:text-neutral-300">
                                {t('common.cancel')}
                            </button>
                        </div>
                    </div>
                )}

                <div className="overflow-hidden rounded-2xl border border-neutral-200 bg-white dark:border-neutral-700 dark:bg-neutral-900">
                    {rows.length === 0 ? (
                        <EmptyState
                            icon={<Users className="h-7 w-7" />}
                            title={t('contacts_page.empty_title')}
                            description={t('contacts_page.empty_description')}
                            action={{ label: t('contacts_page.new_contact'), onClick: () => setShowAdd(true) }}
                        />
                    ) : (
                        <>
                            <div className="overflow-x-auto">
                                <table className="w-full">
                                    <thead>
                                        <tr className="border-b border-neutral-200 text-left text-[11px] font-semibold uppercase tracking-wider text-neutral-400 dark:border-neutral-700">
                                            <th className="w-10 px-3 py-3">
                                                <button type="button" onClick={toggleAll} aria-label={t('common.name')}
                                                    className="text-neutral-400 transition hover:text-brand-600">
                                                    {allSelected ? <CheckSquare className="h-4 w-4 text-brand-600" /> : <Square className="h-4 w-4" />}
                                                </button>
                                            </th>
                                            <th className="px-3 py-3">{t('common.name')}</th>
                                            <th className="px-3 py-3">{t('contacts_page.col_contact')}</th>
                                            <th className="px-3 py-3">{t('contacts_page.col_tags')}</th>
                                            <th className="px-3 py-3">{t('contacts_page.col_channels')}</th>
                                            <th className="px-3 py-3">{t('contacts_page.col_last_seen')}</th>
                                            <th className="px-3 py-3">{t('contacts_page.col_status')}</th>
                                            <th className="w-10 px-3 py-3" />
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {rows.map(contact => (
                                            <ContactRow
                                                key={contact.uuid}
                                                contact={contact}
                                                activity={activity[contact.id]}
                                                selected={selected.has(contact.uuid)}
                                                onToggle={toggleOne}
                                                onDelete={handleDelete}
                                                locale={i18n.language}
                                                userTz={userTz}
                                            />
                                        ))}
                                    </tbody>
                                </table>
                            </div>

                            <div className="flex flex-wrap items-center justify-between gap-3 border-t border-neutral-100 px-4 py-3 dark:border-neutral-800">
                                <p className="text-xs text-ink-muted dark:text-neutral-400">
                                    {t('contacts_page.row_hint', { count: contacts.total ?? rows.length })}
                                </p>

                                {/* Inertia links, and no dangerouslySetInnerHTML: the previous
                                    version rendered Laravel's raw label HTML and navigated with a
                                    plain anchor, which reloaded the page and dropped the selection. */}
                                {contacts.last_page > 1 && (
                                    <div className="flex items-center gap-1">
                                        {contacts.links?.map((link, i) => {
                                            const label = link.label.replace(/&laquo;|&raquo;/g, '').trim() || (i === 0 ? '‹' : '›');

                                            return link.url ? (
                                                <Link key={i} href={link.url} preserveScroll
                                                    className={`rounded-lg px-2.5 py-1 text-xs transition ${
                                                        link.active
                                                            ? 'bg-brand-600 font-semibold text-white'
                                                            : 'text-neutral-600 hover:bg-neutral-100 dark:text-neutral-400 dark:hover:bg-neutral-800'
                                                    }`}>
                                                    {label}
                                                </Link>
                                            ) : (
                                                <span key={i} className="px-2.5 py-1 text-xs text-neutral-300">{label}</span>
                                            );
                                        })}
                                    </div>
                                )}
                            </div>
                        </>
                    )}
                </div>

            </div>

            {showImport && <ImportCsvModal tags={tags} segments={segments} onClose={() => setShowImport(false)} />}

            {bulkModal && (
                <BulkManageModal
                    mode={bulkModal}
                    count={selected.size}
                    tags={tags}
                    segments={segments}
                    processing={bulkProcessing}
                    onClose={() => setBulkModal(null)}
                    onApply={applyBulk}
                />
            )}
        </ClientLayout>
    );
}
