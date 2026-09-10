import { Head, Link, router, usePage } from '@inertiajs/react';
import {
    BookmarkPlus, Bot, Download, Eye, FilePlus, FolderPlus, Folder as FolderIcon,
    History, Inbox, Pencil, Search, SquarePen, Trash2, Upload,
} from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import { useTranslation } from 'react-i18next';
import EmptyState from '@/Components/EmptyState';
import AttachmentIcon from '@/Components/Inbox/AttachmentIcon';
import AttachmentPreview, { previewKindFor } from '@/Components/Inbox/AttachmentPreview';
import { Button, ContactPicker, Modal } from '@/Components/ui';
import ClientLayout from '@/Layouts/ClientLayout';
import { relativeTime } from '@/Utils/relativeTime';

/**
 * The document library.
 *
 * Files, folders and the client each one is about — three things the mockup
 * shows together but which are deliberately separate here: a folder says where a
 * document sits, the contact says who it concerns, and the same contract is in
 * "Contracts" and belongs to Clinica Nord without either being a folder for the
 * other.
 *
 * The icons, the preview dialog and the byte formatting are the ones the inbox
 * already uses for email attachments. Same files, same rules, one place.
 */

/** Human file size. Shared with the inbox's attachment rows in spirit and format. */
function formatBytes(bytes) {
    const n = Number(bytes) || 0;
    if (n < 1024) return `${n} B`;
    if (n < 1024 * 1024) return `${Math.round(n / 1024)} KB`;

    return `${(n / (1024 * 1024)).toFixed(1)} MB`;
}

function extensionOf(path) {
    return String(path ?? '').split('.').pop()?.toLowerCase() ?? '';
}

/** The filter tabs above the list. Keys match Document::KINDS on the server. */
const KINDS = [
    { key: null, labelKey: 'documents.kind_all', countKey: 'all' },
    { key: 'pdf', labelKey: 'documents.kind_pdf', countKey: 'pdf' },
    { key: 'word', labelKey: 'documents.kind_word', countKey: 'word' },
    { key: 'excel', labelKey: 'documents.kind_excel', countKey: 'excel' },
    { key: 'image', labelKey: 'documents.kind_image', countKey: 'image' },
];

/**
 * How full the workspace is.
 *
 * Both halves are named rather than added into one number: the documents are
 * what you control from this screen, the attachments arrive with the mail, and
 * a bar that blamed the library for both would send you looking in the wrong
 * place.
 */
function StorageBar({ storage }) {
    const { t } = useTranslation();
    const { used, limit } = storage;

    if (!limit) {
        return (
            <div className="rounded-xl border border-neutral-200 bg-white p-4 dark:border-neutral-700 dark:bg-neutral-900">
                <p className="text-xs font-semibold uppercase tracking-wide text-neutral-500">{t('documents.storage')}</p>
                <p className="mt-2 text-sm text-neutral-700 dark:text-neutral-300">
                    {t('documents.storage_unlimited', { used: formatBytes(used.total) })}
                </p>
            </div>
        );
    }

    const pct = Math.min(100, Math.round((used.total / limit) * 100));
    const tone = pct >= 90 ? 'bg-coral-500' : pct >= 75 ? 'bg-accent-500' : 'bg-brand-600';

    return (
        <div className="rounded-xl border border-neutral-200 bg-white p-4 dark:border-neutral-700 dark:bg-neutral-900">
            <p className="text-xs font-semibold uppercase tracking-wide text-neutral-500">{t('documents.storage')}</p>
            <div className="mt-2 h-1.5 w-full overflow-hidden rounded-full bg-neutral-200 dark:bg-neutral-700">
                <div className={`h-full rounded-full transition-all ${tone}`} style={{ width: `${pct}%` }} />
            </div>
            <p className="mt-2 text-sm tabular-nums text-neutral-700 dark:text-neutral-300">
                {t('documents.storage_used', { used: formatBytes(used.total), total: formatBytes(limit) })}
            </p>
            <p className="mt-0.5 text-[11px] tabular-nums text-neutral-400">
                {t('documents.storage_split', {
                    documents: formatBytes(used.documents),
                    attachments: formatBytes(used.attachments),
                })}
            </p>
            {pct >= 90 && (
                <p className="mt-2 text-xs font-medium text-coral-700 dark:text-coral-400">{t('documents.storage_nearly_full')}</p>
            )}
        </div>
    );
}

/** What ONLYOFFICE can open, when it is configured. */
const OFFICE_EDITABLE = ['doc', 'docx', 'odt', 'rtf', 'txt', 'xls', 'xlsx', 'ods', 'csv', 'ppt', 'pptx', 'odp'];

/**
 * One tone per action on a row.
 *
 * Five identical grey icons make you read the tooltips to find the one you
 * want. Same idiom as AttachmentIcon: the colour is there at rest so the shapes
 * are told apart at a glance, and the tint only arrives under the pointer, so a
 * list of thirty documents stays a list rather than a wall of buttons. Delete is
 * the one that is red for a reason, not for variety.
 */
const ACTION = 'rounded-lg p-1.5 transition';
const ACTION_TONE = {
    preview: 'text-violet-600 hover:bg-violet-100 dark:text-violet-400 dark:hover:bg-violet-500/15',
    download: 'text-blue-600 hover:bg-blue-100 dark:text-blue-400 dark:hover:bg-blue-500/15',
    office: 'text-brand-600 hover:bg-brand-100 dark:text-brand-400 dark:hover:bg-brand-500/15',
    edit: 'text-accent-600 hover:bg-accent-100 dark:text-accent-400 dark:hover:bg-accent-500/15',
    delete: 'text-coral-600 hover:bg-coral-100 dark:text-coral-400 dark:hover:bg-coral-500/15',
};

/** One row. The actions are the ones from the design, in that order. */
function DocumentRow({ document: doc, canUseOffice, onPreview, onEdit, onDelete }) {
    const { t } = useTranslation();
    const url = route('client.documents.file', doc.uuid);
    // What the browser can show as it is: a PDF, an image, a text file. Word
    // and Excel are not converted for a preview — they open in the editor.
    const canPreview = previewKindFor(doc.path);
    const canOpenInOffice = canUseOffice && OFFICE_EDITABLE.includes(extensionOf(doc.path));
    const client = doc.contact
        ? (doc.contact.company || `${doc.contact.first_name ?? ''} ${doc.contact.last_name ?? ''}`.trim())
        : null;

    return (
        <div className="flex items-center gap-3 border-b border-neutral-100 px-4 py-3 transition hover:bg-neutral-50 dark:border-neutral-800 dark:hover:bg-neutral-800/40">
            <AttachmentIcon file={doc} />

            <div className="min-w-0 flex-1">
                <p className="truncate text-sm font-medium text-neutral-900 dark:text-neutral-100">{doc.name}</p>
                <p className="truncate text-xs text-neutral-400">
                    {[
                        relativeTime(doc.created_at),
                        doc.creator?.name,
                        formatBytes(doc.size_bytes),
                        doc.folder?.name,
                    ].filter(Boolean).join(' · ')}
                </p>
            </div>

            {doc.versions?.length > 0 && (
                <span className="hidden shrink-0 items-center gap-1 text-[11px] text-neutral-400 sm:flex"
                    title={t('documents.versions_kept', { count: doc.versions.length })}>
                    <History className="h-3.5 w-3.5" />
                    {doc.versions.length + 1}
                </span>
            )}

            {doc.kb_document_id && (
                <span className="hidden shrink-0 items-center gap-1 rounded-full bg-brand-50 px-2 py-0.5 text-[11px] font-medium text-brand-700 sm:flex dark:bg-brand-900/20 dark:text-brand-300"
                    title={t('documents.used_by_bot')}>
                    <Bot className="h-3 w-3" />
                </span>
            )}

            {doc.expires_at && (
                <span className={`hidden shrink-0 rounded-full px-2 py-0.5 text-[11px] font-medium sm:block ${
                    new Date(doc.expires_at) < new Date()
                        ? 'bg-coral-50 text-coral-700 dark:bg-coral-900/20 dark:text-coral-400'
                        : 'bg-accent-50 text-accent-800 dark:bg-accent-900/20 dark:text-accent-400'
                }`}>
                    {t('documents.expires_on', { date: String(doc.expires_at).slice(0, 10).split('-').reverse().join('.') })}
                </span>
            )}

            {client && (
                <span className="hidden shrink-0 truncate text-xs text-neutral-500 sm:block sm:max-w-[10rem]">{client}</span>
            )}

            <div className="flex shrink-0 items-center gap-1">
                {canPreview && (
                    <button type="button" onClick={() => onPreview({ file: doc, url })}
                        title={t('documents.open')} aria-label={t('documents.open')}
                        className={`${ACTION} ${ACTION_TONE.preview}`}>
                        <Eye className="h-4 w-4" />
                    </button>
                )}
                <a href={url} title={t('documents.download')} aria-label={t('documents.download')}
                    className={`${ACTION} ${ACTION_TONE.download}`}>
                    <Download className="h-4 w-4" />
                </a>
                {canOpenInOffice && (
                    <Link href={route('client.documents.office', doc.uuid)}
                        title={t('documents.open_in_editor')} aria-label={t('documents.open_in_editor')}
                        className={`${ACTION} ${ACTION_TONE.office}`}>
                        <SquarePen className="h-4 w-4" />
                    </Link>
                )}
                <button type="button" onClick={() => onEdit(doc)}
                    title={t('documents.edit')} aria-label={t('documents.edit')}
                    className={`${ACTION} ${ACTION_TONE.edit}`}>
                    <Pencil className="h-4 w-4" />
                </button>
                <button type="button" onClick={() => onDelete(doc)}
                    title={t('common.delete')} aria-label={t('common.delete')}
                    className={`${ACTION} ${ACTION_TONE.delete}`}>
                    <Trash2 className="h-4 w-4" />
                </button>
            </div>
        </div>
    );
}

/** Rename, move, and say which client it is about. */
function EditModal({ document: doc, folders, knowledgeBases, onClose }) {
    const { t } = useTranslation();
    const [name, setName] = useState(doc.name);
    const [folderId, setFolderId] = useState(doc.folder_id ?? '');
    const [contact, setContact] = useState(doc.contact ?? null);
    const [expiresAt, setExpiresAt] = useState(doc.expires_at ? String(doc.expires_at).slice(0, 10) : '');
    const [remindDays, setRemindDays] = useState(doc.remind_days ?? [30, 7]);

    const save = () => {
        router.patch(route('client.documents.update', doc.uuid), {
            name,
            folder_id: folderId === '' ? null : Number(folderId),
            contact_id: contact?.id ?? null,
            expires_at: expiresAt || null,
            remind_days: expiresAt ? remindDays : [],
        }, { preserveScroll: true, onSuccess: onClose });
    };

    return (
        <Modal show onClose={onClose} maxWidth="md">
            <div className="space-y-4 p-5">
                <h3 className="text-base font-semibold text-neutral-900 dark:text-neutral-100">{t('documents.edit_title')}</h3>

                <label className="block">
                    <span className="text-xs font-medium text-neutral-500">{t('documents.name')}</span>
                    <input value={name} onChange={e => setName(e.target.value)}
                        className="mt-1 w-full rounded-lg border-neutral-300 text-sm dark:border-neutral-600 dark:bg-neutral-800" />
                </label>

                <label className="block">
                    <span className="text-xs font-medium text-neutral-500">{t('documents.folder')}</span>
                    <select value={folderId} onChange={e => setFolderId(e.target.value)}
                        className="mt-1 w-full rounded-lg border-neutral-300 text-sm dark:border-neutral-600 dark:bg-neutral-800">
                        <option value="">{t('documents.no_folder')}</option>
                        {folders.map(f => <option key={f.id} value={f.id}>{f.name}</option>)}
                    </select>
                </label>

                <div>
                    <span className="text-xs font-medium text-neutral-500">{t('documents.client')}</span>
                    <ContactPicker contact={contact} onPick={setContact} />
                    <button type="button"
                        onClick={() => {
                            const name = window.prompt(t('documents.template_name_prompt'), doc.name);
                            if (!name?.trim()) return;
                            router.post(route('client.documents.templates.store', doc.uuid), { name: name.trim() }, {
                                preserveScroll: true, onSuccess: onClose,
                            });
                        }}
                        className="mt-3 inline-flex items-center gap-1.5 text-xs font-medium text-brand-700 hover:underline dark:text-brand-400">
                        <BookmarkPlus className="h-3.5 w-3.5" />
                        {t('documents.save_as_template')}
                    </button>
                </div>

                {/* Versions. What makes "download it, edit it in Word, upload it
                    again" safe rather than destructive. */}
                <div className="border-t border-neutral-100 pt-4 dark:border-neutral-800">
                    <div className="flex items-center justify-between gap-2">
                        <span className="text-xs font-medium text-neutral-500">{t('documents.versions')}</span>
                        <label className="cursor-pointer text-xs font-medium text-brand-700 hover:underline dark:text-brand-400">
                            {t('documents.new_version')}
                            <input type="file" className="hidden" onChange={e => {
                                const file = e.target.files?.[0];
                                e.target.value = '';
                                if (!file) return;
                                const body = new FormData();
                                body.append('file', file);
                                router.post(route('client.documents.versions.store', doc.uuid), body, {
                                    preserveScroll: true, forceFormData: true, onSuccess: onClose,
                                });
                            }} />
                        </label>
                    </div>
                    {doc.versions?.length > 0 ? (
                        <ul className="mt-2 space-y-1">
                            {doc.versions.map(v => (
                                <li key={v.id} className="flex items-center gap-2 text-xs text-neutral-500">
                                    <span className="tabular-nums text-neutral-400">v{v.version}</span>
                                    <span className="min-w-0 flex-1 truncate">{v.name}</span>
                                    <a href={route('client.documents.versions.show', [doc.uuid, v.id])}
                                        className="shrink-0 rounded p-1 text-neutral-400 transition hover:text-brand-600">
                                        <Download className="h-3.5 w-3.5" />
                                    </a>
                                </li>
                            ))}
                        </ul>
                    ) : (
                        <p className="mt-1 text-[11px] text-neutral-400">{t('documents.no_versions')}</p>
                    )}
                </div>

                {/* The chatbot. Only offered when the tenant has built a
                    knowledge base for it to go into. */}
                {knowledgeBases.length > 0 && (
                    <div className="border-t border-neutral-100 pt-4 dark:border-neutral-800">
                        <span className="text-xs font-medium text-neutral-500">{t('documents.use_in_bot')}</span>
                        {doc.kb_document_id ? (
                            <div className="mt-1 flex items-center gap-2 rounded-lg border border-brand-200 bg-brand-50 px-3 py-2 dark:border-brand-900/40 dark:bg-brand-900/20">
                                <Bot className="h-4 w-4 shrink-0 text-brand-600" />
                                <span className="min-w-0 flex-1 text-xs text-brand-800 dark:text-brand-300">{t('documents.used_by_bot')}</span>
                                <button type="button"
                                    onClick={() => router.delete(route('client.documents.kb.destroy', doc.uuid), { preserveScroll: true, onSuccess: onClose })}
                                    className="shrink-0 rounded px-2 py-0.5 text-xs font-medium text-coral-700 hover:underline dark:text-coral-400">
                                    {t('documents.stop_using_in_bot')}
                                </button>
                            </div>
                        ) : (
                            <select defaultValue="" onChange={e => {
                                if (!e.target.value) return;
                                router.post(route('client.documents.kb.store', doc.uuid), { kb_id: Number(e.target.value) }, {
                                    preserveScroll: true, onSuccess: onClose,
                                });
                            }} className="mt-1 w-full rounded-lg border-neutral-300 text-sm dark:border-neutral-600 dark:bg-neutral-800">
                                <option value="">{t('documents.pick_knowledge_base')}</option>
                                {knowledgeBases.map(kb => <option key={kb.id} value={kb.id}>{kb.name}</option>)}
                            </select>
                        )}
                    </div>
                )}

                {/* Expiry, and how early to be told. The contract that renews
                    itself tacitly is the one nobody remembers. */}
                <div className="border-t border-neutral-100 pt-4 dark:border-neutral-800">
                    <label className="block">
                        <span className="text-xs font-medium text-neutral-500">{t('documents.expires_at')}</span>
                        <input type="date" value={expiresAt} onChange={e => setExpiresAt(e.target.value)}
                            className="mt-1 w-full rounded-lg border-neutral-300 text-sm dark:border-neutral-600 dark:bg-neutral-800" />
                    </label>

                    {expiresAt && (
                        <div className="mt-3">
                            <span className="text-xs font-medium text-neutral-500">{t('documents.remind_me')}</span>
                            <div className="mt-1.5 flex flex-wrap gap-1.5">
                                {[30, 14, 7, 1].map(days => {
                                    const on = remindDays.includes(days);

                                    return (
                                        <button key={days} type="button"
                                            onClick={() => setRemindDays(prev => on ? prev.filter(d => d !== days) : [...prev, days])}
                                            aria-pressed={on}
                                            className={`rounded-full border px-3 py-1 text-xs font-medium transition ${
                                                on
                                                    ? 'border-brand-600 bg-brand-600 text-white'
                                                    : 'border-neutral-300 text-neutral-600 hover:bg-neutral-50 dark:border-neutral-600 dark:text-neutral-300 dark:hover:bg-neutral-800'
                                            }`}>
                                            {t('documents.days_before', { count: days })}
                                        </button>
                                    );
                                })}
                            </div>
                        </div>
                    )}
                </div>

                <div className="flex justify-end gap-2 pt-1">
                    <Button variant="ghost" onClick={onClose}>{t('common.cancel')}</Button>
                    <Button onClick={save}>{t('common.save')}</Button>
                </div>
            </div>
        </Modal>
    );
}

export default function DocumentsIndex({ documents, folders, counts, storage, filters, maxFileBytes, knowledgeBases = [], canUseOffice = false, templates = [] }) {
    const { t } = useTranslation();
    const { props } = usePage();
    const [search, setSearch] = useState(filters.search ?? '');
    const [preview, setPreview] = useState(null);
    const [editing, setEditing] = useState(null);
    const [deleting, setDeleting] = useState(null);
    const [creating, setCreating] = useState(null);
    const [newName, setNewName] = useState('');
    const [newTemplate, setNewTemplate] = useState('');
    const [newContact, setNewContact] = useState(null);
    const [newFolder, setNewFolder] = useState('');
    const [showFolderInput, setShowFolderInput] = useState(false);
    const [dragging, setDragging] = useState(false);
    const [uploading, setUploading] = useState(false);
    const fileRef = useRef(null);

    const go = (patch) => router.get(route('client.documents.index'), {
        ...filters, ...patch,
    }, { preserveState: true, preserveScroll: true, replace: true });

    useEffect(() => {
        const term = search.trim();
        if (term === (filters.search ?? '')) {
            return undefined;
        }
        const timer = setTimeout(() => go({ search: term || undefined }), 350);

        return () => clearTimeout(timer);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [search]);

    const upload = (files) => {
        const picked = Array.from(files ?? []);
        if (picked.length === 0) return;

        const data = new FormData();
        picked.forEach(f => data.append('files[]', f));
        if (filters.folder_id) data.append('folder_id', filters.folder_id);

        setUploading(true);
        router.post(route('client.documents.store'), data, {
            preserveScroll: true,
            forceFormData: true,
            onFinish: () => setUploading(false),
        });
    };

    const rows = documents?.data ?? [];
    const flash = props.flash ?? {};

    return (
        <ClientLayout title={t('documents.title')}>
            <Head title={t('documents.title')} />

            <div className="space-y-4">
                <div className="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <h1 className="text-2xl font-bold text-neutral-900 dark:text-white">{t('documents.title')}</h1>
                        <p className="mt-1 text-sm text-ink-muted dark:text-neutral-400">{t('documents.subtitle')}</p>
                    </div>
                    <div className="flex flex-wrap gap-2">
                        <button type="button" onClick={() => fileRef.current?.click()} disabled={uploading}
                            className="inline-flex items-center gap-1.5 rounded-xl border border-neutral-300 px-3 py-2 text-sm text-neutral-700 transition hover:bg-neutral-50 disabled:opacity-60 dark:border-neutral-600 dark:text-neutral-300 dark:hover:bg-neutral-800">
                            <Upload className="h-4 w-4" /> {t('documents.upload')}
                        </button>
                        {/* Only offered when a page can actually be produced —
                            a button that fails is worse than one that is absent. */}
                        {canUseOffice && (
                            <button type="button" onClick={() => setCreating('word')}
                                className="inline-flex items-center gap-1.5 rounded-xl bg-brand-600 px-4 py-2 text-sm font-semibold text-white transition hover:bg-brand-700">
                                <FilePlus className="h-4 w-4" /> {t('documents.new_document')}
                            </button>
                        )}
                    </div>
                    <input ref={fileRef} type="file" multiple className="hidden"
                        onChange={e => { upload(e.target.files); e.target.value = ''; }} />
                </div>

                {flash.error && (
                    <div className="rounded-xl border border-coral-200 bg-coral-50 px-4 py-3 text-sm text-coral-800 dark:border-coral-900/40 dark:bg-coral-900/20 dark:text-coral-300">
                        {flash.error}
                    </div>
                )}

                <div className="grid gap-4 lg:grid-cols-[230px_1fr]">
                    {/* ── folders + space ── */}
                    <div className="space-y-3">
                        <div className="overflow-hidden rounded-xl border border-neutral-200 bg-white dark:border-neutral-700 dark:bg-neutral-900">
                            <button type="button" onClick={() => go({ folder_id: undefined })}
                                className={`flex w-full items-center gap-2 px-3 py-2.5 text-left text-sm transition ${
                                    !filters.folder_id ? 'bg-brand-600 font-medium text-white' : 'hover:bg-neutral-50 dark:hover:bg-neutral-800'
                                }`}>
                                <FolderIcon className="h-4 w-4 shrink-0" />
                                <span className="min-w-0 flex-1 truncate">{t('documents.all_documents')}</span>
                                <span className="shrink-0 text-xs tabular-nums opacity-70">{counts.all}</span>
                            </button>
                            {folders.map(f => (
                                <button key={f.id} type="button" onClick={() => go({ folder_id: f.id })}
                                    className={`flex w-full items-center gap-2 border-t border-neutral-100 px-3 py-2.5 text-left text-sm transition dark:border-neutral-800 ${
                                        Number(filters.folder_id) === f.id ? 'bg-brand-50 font-medium text-brand-800 dark:bg-brand-900/20 dark:text-brand-300' : 'hover:bg-neutral-50 dark:hover:bg-neutral-800'
                                    }`}>
                                    <FolderIcon className="h-4 w-4 shrink-0 text-neutral-400" />
                                    <span className="min-w-0 flex-1 truncate">{f.name}</span>
                                    <span className="shrink-0 text-xs tabular-nums text-neutral-400">{f.count || ''}</span>
                                </button>
                            ))}

                            {showFolderInput ? (
                                <form
                                    className="border-t border-neutral-100 p-2 dark:border-neutral-800"
                                    onSubmit={e => {
                                        e.preventDefault();
                                        if (!newFolder.trim()) return;
                                        router.post(route('client.documents.folders.store'), { name: newFolder.trim() }, {
                                            preserveScroll: true,
                                            onSuccess: () => { setNewFolder(''); setShowFolderInput(false); },
                                        });
                                    }}
                                >
                                    <input autoFocus value={newFolder} onChange={e => setNewFolder(e.target.value)}
                                        placeholder={t('documents.folder_name')}
                                        className="w-full rounded-lg border-neutral-300 text-sm dark:border-neutral-600 dark:bg-neutral-800" />
                                </form>
                            ) : (
                                <button type="button" onClick={() => setShowFolderInput(true)}
                                    className="flex w-full items-center gap-2 border-t border-neutral-100 px-3 py-2.5 text-left text-sm text-neutral-500 transition hover:bg-neutral-50 dark:border-neutral-800 dark:hover:bg-neutral-800">
                                    <FolderPlus className="h-4 w-4 shrink-0" />
                                    {t('documents.new_folder')}
                                </button>
                            )}
                        </div>

                        <StorageBar storage={storage} />
                    </div>

                    {/* ── search, filters, list ── */}
                    <div className="space-y-3">
                        <div className="flex flex-wrap items-center gap-2">
                            <div className="relative min-w-[200px] flex-1">
                                <Search className="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-neutral-400" />
                                <input value={search} onChange={e => setSearch(e.target.value)}
                                    placeholder={t('documents.search_placeholder')}
                                    className="w-full rounded-xl border-neutral-300 py-2 pl-10 text-sm dark:border-neutral-600 dark:bg-neutral-800" />
                            </div>
                            <div className="flex gap-1 rounded-xl border border-neutral-200 p-1 dark:border-neutral-700">
                                {KINDS.map(({ key, labelKey, countKey }) => (
                                    <button key={key ?? 'all'} type="button" onClick={() => go({ kind: key ?? undefined })}
                                        className={`rounded-lg px-3 py-1.5 text-xs font-medium transition ${
                                            (filters.kind ?? null) === key
                                                ? 'bg-brand-600 text-white'
                                                : 'text-neutral-600 hover:bg-neutral-100 dark:text-neutral-300 dark:hover:bg-neutral-800'
                                        }`}>
                                        {t(labelKey)}
                                        {counts[countKey] > 0 && <span className="ml-1 tabular-nums opacity-70">{counts[countKey]}</span>}
                                    </button>
                                ))}
                            </div>
                        </div>

                        <div className="overflow-hidden rounded-xl border border-neutral-200 bg-white dark:border-neutral-700 dark:bg-neutral-900">
                            <div className="flex items-center justify-between border-b border-neutral-100 px-4 py-3 dark:border-neutral-800">
                                <h2 className="text-sm font-semibold text-neutral-900 dark:text-neutral-100">{t('documents.all_documents')}</h2>
                                <span className="text-xs text-neutral-400">
                                    {t('documents.count', { count: documents?.total ?? 0 })}
                                </span>
                            </div>

                            {rows.length === 0 ? (
                                <div className="px-4 py-12">
                                    <EmptyState
                                        icon={<Inbox className="h-7 w-7" />}
                                        title={t('documents.empty_title')}
                                        description={t('documents.empty_desc')}
                                    />
                                </div>
                            ) : rows.map(doc => (
                                <DocumentRow
                                    key={doc.uuid}
                                    document={doc}
                                    canUseOffice={canUseOffice}
                                    onPreview={setPreview}
                                    onEdit={setEditing}
                                    onDelete={setDeleting}
                                />
                            ))}
                        </div>

                        {/* A button, not a div: dropping a file is not something
                            everyone thinks to try, and on a laptop trackpad it is
                            awkward even when you do. Clicking opens the same picker
                            the toolbar button uses, and being a real button means
                            the keyboard reaches it too. */}
                        <button
                            type="button"
                            onClick={() => fileRef.current?.click()}
                            disabled={uploading}
                            onDragOver={e => { e.preventDefault(); setDragging(true); }}
                            onDragLeave={() => setDragging(false)}
                            onDrop={e => { e.preventDefault(); setDragging(false); upload(e.dataTransfer.files); }}
                            className={`w-full rounded-xl border-2 border-dashed px-4 py-6 text-center text-sm transition focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-500 disabled:cursor-default ${
                                dragging
                                    ? 'border-brand-500 bg-brand-50 text-brand-700 dark:bg-brand-900/20'
                                    : 'border-neutral-200 text-neutral-500 hover:border-brand-400 hover:bg-brand-50/50 hover:text-brand-700 disabled:hover:border-neutral-200 disabled:hover:bg-transparent disabled:hover:text-neutral-500 dark:border-neutral-700 dark:text-neutral-400 dark:hover:border-brand-600 dark:hover:bg-brand-900/10'
                            }`}
                        >
                            {uploading
                                ? t('documents.uploading')
                                : t('documents.drop_here', { size: formatBytes(maxFileBytes) })}
                        </button>
                    </div>
                </div>
            </div>

            {preview && <AttachmentPreview file={preview.file} url={preview.url} onClose={() => setPreview(null)} />}
            {editing && <EditModal document={editing} folders={folders} knowledgeBases={knowledgeBases} onClose={() => setEditing(null)} />}

            <Modal show={!!creating} onClose={() => setCreating(null)} maxWidth="md">
                <form className="space-y-4 p-5" onSubmit={e => {
                    e.preventDefault();
                    if (!newName.trim()) return;
                    router.post(route('client.documents.office.create'), {
                        name: newName.trim(),
                        kind: newTemplate ? null : creating,
                        template_id: newTemplate ? Number(newTemplate) : null,
                        contact_id: newContact?.id ?? null,
                        folder_id: filters.folder_id || null,
                    });
                }}>
                    <h3 className="text-base font-semibold text-neutral-900 dark:text-neutral-100">{t('documents.new_document')}</h3>

                    {/* Two kinds, because those are the two ONLYOFFICE writes back
                        and the two an office actually starts from scratch. */}
                    <div className={`grid grid-cols-2 gap-2 ${newTemplate ? 'hidden' : ''}`}>
                        {[
                            { kind: 'word', labelKey: 'documents.kind_word', ext: '.docx' },
                            { kind: 'cell', labelKey: 'documents.kind_excel', ext: '.xlsx' },
                        ].map(({ kind, labelKey, ext }) => (
                            <button key={kind} type="button" onClick={() => setCreating(kind)}
                                className={`flex items-center gap-2.5 rounded-xl border p-3 text-left transition ${
                                    creating === kind
                                        ? 'border-brand-600 bg-brand-50 dark:bg-brand-900/20'
                                        : 'border-neutral-200 hover:bg-neutral-50 dark:border-neutral-700 dark:hover:bg-neutral-800'
                                }`}>
                                <AttachmentIcon file={{ name: `x${ext}` }} />
                                <span className="min-w-0">
                                    <span className="block text-sm font-medium">{t(labelKey)}</span>
                                    <span className="block text-[11px] text-neutral-400">{ext}</span>
                                </span>
                            </button>
                        ))}
                    </div>

                    <label className="block">
                        <span className="text-xs font-medium text-neutral-500">{t('documents.name')}</span>
                        <input autoFocus value={newName} onChange={e => setNewName(e.target.value)}
                            placeholder={t('documents.new_document_placeholder')}
                            className="mt-1 w-full rounded-lg border-neutral-300 text-sm dark:border-neutral-600 dark:bg-neutral-800" />
                    </label>

                    {templates.length > 0 && (
                        <label className="block">
                            <span className="text-xs font-medium text-neutral-500">{t('documents.from_template')}</span>
                            <select value={newTemplate} onChange={e => setNewTemplate(e.target.value)}
                                className="mt-1 w-full rounded-lg border-neutral-300 text-sm dark:border-neutral-600 dark:bg-neutral-800">
                                <option value="">{t('documents.blank_document')}</option>
                                {templates.map(tpl => <option key={tpl.id} value={tpl.id}>{tpl.name}</option>)}
                            </select>
                        </label>
                    )}

                    {/* Only worth asking once a template is chosen: on a blank
                        page there are no fields for the details to go into. */}
                    {newTemplate && (
                        <div>
                            <span className="text-xs font-medium text-neutral-500">{t('documents.client')}</span>
                            <ContactPicker contact={newContact} onPick={setNewContact} />
                            <p className="mt-1.5 text-[11px] text-neutral-400">{t('documents.template_client_hint')}</p>
                        </div>
                    )}

                    <div className="flex justify-end gap-2">
                        <Button variant="ghost" onClick={() => setCreating(null)}>{t('common.cancel')}</Button>
                        <Button type="submit" disabled={!newName.trim()}>{t('documents.create_and_open')}</Button>
                    </div>
                </form>
            </Modal>

            <Modal show={!!deleting} onClose={() => setDeleting(null)} maxWidth="md">
                <div className="p-5">
                    <h3 className="text-base font-semibold text-neutral-900 dark:text-neutral-100">{t('documents.delete_title')}</h3>
                    <p className="mt-2 text-sm text-neutral-600 dark:text-neutral-400">{t('documents.delete_body')}</p>
                    <div className="mt-5 flex justify-end gap-2">
                        <Button variant="ghost" onClick={() => setDeleting(null)}>{t('common.cancel')}</Button>
                        <Button variant="danger" onClick={() => router.delete(route('client.documents.destroy', deleting.uuid), {
                            preserveScroll: true,
                            onSuccess: () => setDeleting(null),
                        })}>{t('common.delete')}</Button>
                    </div>
                </div>
            </Modal>
        </ClientLayout>
    );
}
