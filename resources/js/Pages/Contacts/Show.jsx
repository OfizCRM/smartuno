import { Head, Link, router, useForm } from '@inertiajs/react';
import { ArrowLeft, Camera, Mail, MessageSquare, Phone, Trash2, X } from 'lucide-react';
import { useRef, useState } from 'react';
import { useTranslation } from 'react-i18next';
import ClientLayout from '@/Layouts/ClientLayout';
import { ChannelBrandIcon } from '@/Components/BrandIcons';
import { StatusBadge } from '@/Pages/Contacts/Index';
import { formatInTz } from '@/Utils/datetime';
import { relativeTime } from '@/Utils/relativeTime';
import { activityText } from '@/Utils/conversationActivity';

/**
 * One contact's record.
 *
 * The design this follows also had cards for quotes and appointments and a
 * button to book one. Neither exists in this codebase — there is no quotes table
 * and no appointments table, and the automation node that "books" one writes
 * straight to Google Calendar without storing anything — so those are not here.
 * Everything on this page is backed by a real row.
 */

function StatCard({ value, label }) {
    return (
        <div className="rounded-xl border border-neutral-200 bg-white px-4 py-3 dark:border-neutral-700 dark:bg-neutral-900">
            <p className="text-lg font-semibold text-neutral-900 dark:text-neutral-100">{value}</p>
            <p className="mt-0.5 text-xs text-ink-muted dark:text-neutral-400">{label}</p>
        </div>
    );
}

function Field({ label, value, onChange, type = 'text', placeholder = '' }) {
    return (
        <div>
            <label className="mb-1 block text-sm font-medium text-neutral-700 dark:text-neutral-300">{label}</label>
            <input
                type={type}
                value={value}
                placeholder={placeholder}
                onChange={e => onChange(e.target.value)}
                className="w-full rounded-xl border border-neutral-300 bg-white px-3 py-2 text-sm focus:border-brand-400 focus:outline-none focus:ring-2 focus:ring-brand-500/20 dark:border-neutral-600 dark:bg-neutral-800"
            />
        </div>
    );
}

function AvatarUploader({ contact, name }) {
    const { t } = useTranslation();
    const fileInput = useRef();
    const [preview, setPreview] = useState(contact.avatar_url ?? null);

    const initials = name ? name.split(' ').map(p => p[0]).slice(0, 2).join('').toUpperCase() : '?';

    const upload = (file) => {
        if (! file || ! file.type.startsWith('image/')) return;
        setPreview(URL.createObjectURL(file));
        const body = new FormData();
        body.append('avatar', file);
        router.post(route('client.contacts.avatar.upload', contact.uuid), body, {
            forceFormData: true,
            preserveScroll: true,
        });
    };

    return (
        <button
            type="button"
            onClick={() => fileInput.current?.click()}
            title={t('contacts_page.avatar_hint')}
            className="group relative shrink-0"
        >
            {preview
                ? <img src={preview} alt="" className="h-16 w-16 rounded-full object-cover" />
                : (
                    <span className="flex h-16 w-16 items-center justify-center rounded-full bg-brand-100 text-xl font-bold text-brand-700 dark:bg-brand-900/30 dark:text-brand-300">
                        {initials}
                    </span>
                )}
            <span className="absolute inset-0 flex items-center justify-center rounded-full bg-black/40 opacity-0 transition group-hover:opacity-100">
                <Camera className="h-5 w-5 text-white" />
            </span>
            <input ref={fileInput} type="file" accept="image/*" className="hidden" onChange={e => upload(e.target.files[0])} />
        </button>
    );
}

export default function ContactShow({ contact, staticSegments = [], allTags = [], summary = {}, activity = [] }) {
    const { t, i18n } = useTranslation();
    const [tab, setTab] = useState('details');
    const [tagDraft, setTagDraft] = useState('');
    const userTz = 'Europe/Bucharest';

    const name = `${contact.first_name ?? ''} ${contact.last_name ?? ''}`.trim() || t('contacts_page.unknown_contact');

    const form = useForm({
        first_name: contact.first_name ?? '',
        last_name: contact.last_name ?? '',
        email: contact.email ?? '',
        country: contact.country ?? '',
        language: contact.language ?? '',
        opt_in_whatsapp: contact.opt_in_whatsapp,
        opt_in_sms: contact.opt_in_sms,
        opt_in_email: contact.opt_in_email,
        company: contact.company ?? '',
        job_title: contact.job_title ?? '',
        tax_id: contact.tax_id ?? '',
        address: contact.address ?? '',
        city: contact.city ?? '',
        // <input type="date"> speaks YYYY-MM-DD; the cast hands back an ISO string.
        birthday: contact.birthday ? String(contact.birthday).slice(0, 10) : '',
        status: contact.status ?? 'lead',
        segment_ids: (contact.segments ?? []).filter(s => s.type === 'static').map(s => s.id),
        tag_names: (contact.tags ?? []).map(x => x.name),
    });

    const save = (e) => {
        e.preventDefault();
        form.put(route('client.contacts.update', contact.uuid), { preserveScroll: true });
    };

    const addTag = () => {
        const value = tagDraft.trim();
        if (value && ! form.data.tag_names.includes(value)) {
            form.setData('tag_names', [...form.data.tag_names, value]);
        }
        setTagDraft('');
    };

    const remove = () => {
        if (confirm(t('contacts_page.confirm_delete_one'))) {
            router.delete(route('client.contacts.destroy', contact.uuid));
        }
    };

    // Only rendered when an ecommerce store has been connected and synced; the
    // enricher is what writes it, so most workspaces will not have it.
    const spent = summary.lifetime_value
        ? `${Number(summary.lifetime_value).toLocaleString(i18n.language)} ${summary.lifetime_currency ?? ''}`.trim()
        : null;

    const lastTalk = summary.last_at
        ? (relativeTime(summary.last_at, i18n.language) ?? formatInTz(summary.last_at, userTz))
        : t('contacts_page.never_talked');

    const newest = contact.conversations?.[0];

    return (
        <ClientLayout title={name}>
            <Head title={`${name} · ${t('contacts_page.contact_alt')}`} />

            <div className="mx-auto max-w-5xl space-y-4">
                <Link href={route('client.contacts.index')} className="inline-flex items-center gap-1.5 text-sm text-ink-muted transition hover:text-neutral-800 dark:hover:text-neutral-200">
                    <ArrowLeft className="h-4 w-4" /> {t('contacts_page.title')}
                </Link>

                {/* Header */}
                <div className="rounded-2xl border border-neutral-200 bg-white p-5 dark:border-neutral-700 dark:bg-neutral-900">
                    <div className="flex flex-wrap items-start gap-4">
                        <AvatarUploader contact={contact} name={name} />

                        <div className="min-w-0 flex-1">
                            <div className="flex flex-wrap items-center gap-2">
                                <h1 className="text-xl font-bold text-neutral-900 dark:text-white">{name}</h1>
                                <StatusBadge status={contact.status} />
                            </div>
                            {(contact.job_title || contact.company) && (
                                <p className="mt-0.5 text-sm text-ink-muted dark:text-neutral-400">
                                    {[contact.job_title, contact.company].filter(Boolean).join(' · ')}
                                </p>
                            )}
                            <div className="mt-1.5 flex flex-wrap items-center gap-x-4 gap-y-1 text-sm text-ink-muted dark:text-neutral-400">
                                {contact.phone_e164 && (
                                    <span className="inline-flex items-center gap-1.5"><Phone className="h-3.5 w-3.5" />{contact.phone_e164}</span>
                                )}
                                {contact.email && (
                                    <span className="inline-flex items-center gap-1.5"><Mail className="h-3.5 w-3.5" />{contact.email}</span>
                                )}
                            </div>
                        </div>

                        {/* Only offered when there is a thread to open: starting one from
                            scratch needs a channel account picked, which the inbox does. */}
                        {newest && (
                            <Link
                                href={route('client.inbox.show', newest.uuid)}
                                className="inline-flex items-center gap-1.5 rounded-xl bg-brand-600 px-4 py-2 text-sm font-semibold text-white transition hover:bg-brand-700"
                            >
                                <MessageSquare className="h-4 w-4" /> {t('contacts_page.write_to')}
                            </Link>
                        )}
                    </div>

                    <div className="mt-4 grid gap-3 sm:grid-cols-3">
                        {spent && <StatCard value={spent} label={t('contacts_page.stat_spent')} />}
                        <StatCard value={summary.conversations ?? 0} label={t('contacts_page.stat_conversations')} />
                        <StatCard value={lastTalk} label={t('contacts_page.stat_last_talk')} />
                    </div>
                </div>

                {/* Tabs */}
                <div className="flex gap-1 border-b border-neutral-200 dark:border-neutral-700">
                    {[
                        ['details', t('contacts_page.tab_details')],
                        ['activity', t('contacts_page.tab_activity')],
                        ['consents', t('contacts_page.tab_consents')],
                    ].map(([key, label]) => (
                        <button key={key} type="button" onClick={() => setTab(key)}
                            className={`border-b-2 px-4 py-2.5 text-sm font-medium transition ${
                                tab === key
                                    ? 'border-brand-600 text-brand-700 dark:text-brand-300'
                                    : 'border-transparent text-neutral-500 hover:text-neutral-700 dark:hover:text-neutral-300'
                            }`}>
                            {label}
                        </button>
                    ))}
                </div>

                {tab === 'details' && (
                    <form onSubmit={save} className="space-y-4">
                        <div className="rounded-2xl border border-neutral-200 bg-white p-5 dark:border-neutral-700 dark:bg-neutral-900">
                            <h2 className="text-base font-semibold text-neutral-900 dark:text-neutral-100">{t('contacts_page.details_title')}</h2>
                            <p className="mt-0.5 text-sm text-ink-muted dark:text-neutral-400">{t('contacts_page.details_hint')}</p>

                            <div className="mt-4 grid gap-4 sm:grid-cols-2">
                                <Field label={t('contacts_page.first_name')} value={form.data.first_name} onChange={v => form.setData('first_name', v)} />
                                <Field label={t('contacts_page.last_name')} value={form.data.last_name} onChange={v => form.setData('last_name', v)} />
                                {/* Read-only: the phone is the identity a channel matches on,
                                    and editing it here would orphan the conversations. */}
                                <div>
                                    <label className="mb-1 block text-sm font-medium text-neutral-700 dark:text-neutral-300">{t('contacts_page.col_phone')}</label>
                                    <p className="rounded-xl bg-neutral-50 px-3 py-2 text-sm tabular-nums text-neutral-500 dark:bg-neutral-800 dark:text-neutral-400">
                                        {contact.phone_e164 || '—'}
                                    </p>
                                </div>
                                <Field label={t('common.email')} type="email" value={form.data.email} onChange={v => form.setData('email', v)} />
                                <Field label={t('contacts_page.country_label')} value={form.data.country} onChange={v => form.setData('country', v)} />
                                <Field label={t('contacts_page.language_label')} value={form.data.language} onChange={v => form.setData('language', v)} />

                                <div>
                                    <label className="mb-1 block text-sm font-medium text-neutral-700 dark:text-neutral-300">{t('contacts_page.field_status')}</label>
                                    <select
                                        value={form.data.status}
                                        onChange={e => form.setData('status', e.target.value)}
                                        className="w-full rounded-xl border border-neutral-300 bg-white px-3 py-2 text-sm focus:border-brand-400 focus:outline-none focus:ring-2 focus:ring-brand-500/20 dark:border-neutral-600 dark:bg-neutral-800"
                                    >
                                        {['lead', 'negotiating', 'client', 'inactive'].map(value => (
                                            <option key={value} value={value}>{t(`contacts_page.status_${value}`)}</option>
                                        ))}
                                    </select>
                                </div>
                            </div>
                        </div>

                        <div className="rounded-2xl border border-neutral-200 bg-white p-5 dark:border-neutral-700 dark:bg-neutral-900">
                            <h2 className="text-base font-semibold text-neutral-900 dark:text-neutral-100">{t('contacts_page.business_title')}</h2>
                            <p className="mt-0.5 text-sm text-ink-muted dark:text-neutral-400">{t('contacts_page.business_hint')}</p>

                            <div className="mt-4 grid gap-4 sm:grid-cols-2">
                                <Field label={t('contacts_page.field_company')} value={form.data.company} onChange={v => form.setData('company', v)} />
                                <Field label={t('contacts_page.field_job_title')} value={form.data.job_title} onChange={v => form.setData('job_title', v)} />
                                <Field label={t('contacts_page.field_tax_id')} value={form.data.tax_id} onChange={v => form.setData('tax_id', v)} placeholder="RO12345678" />
                                <Field label={t('contacts_page.field_city')} value={form.data.city} onChange={v => form.setData('city', v)} />
                                <div className="sm:col-span-2">
                                    <Field label={t('contacts_page.field_address')} value={form.data.address} onChange={v => form.setData('address', v)} />
                                </div>
                                <Field label={t('contacts_page.field_birthday')} type="date" value={form.data.birthday} onChange={v => form.setData('birthday', v)} />
                            </div>
                        </div>

                        <div className="rounded-2xl border border-neutral-200 bg-white p-5 dark:border-neutral-700 dark:bg-neutral-900">
                            <h2 className="text-base font-semibold text-neutral-900 dark:text-neutral-100">{t('contacts_page.col_tags')}</h2>
                            <p className="mt-0.5 text-sm text-ink-muted dark:text-neutral-400">{t('contacts_page.tags_hint')}</p>

                            <div className="mt-3 flex flex-wrap items-center gap-2">
                                {form.data.tag_names.map(tag => {
                                    const known = allTags.find(x => x.name === tag);

                                    return (
                                        <span key={tag} className="inline-flex items-center gap-1 rounded-full px-2.5 py-1 text-xs font-medium text-white"
                                            style={{ backgroundColor: known?.color ?? '#6366f1' }}>
                                            {tag}
                                            <button type="button" aria-label={t('common.remove')}
                                                onClick={() => form.setData('tag_names', form.data.tag_names.filter(x => x !== tag))}>
                                                <X className="h-3 w-3" />
                                            </button>
                                        </span>
                                    );
                                })}
                                <input
                                    value={tagDraft}
                                    onChange={e => setTagDraft(e.target.value)}
                                    onKeyDown={e => { if (e.key === 'Enter') { e.preventDefault(); addTag(); } }}
                                    onBlur={addTag}
                                    list="contact-tag-options"
                                    placeholder={t('contacts_page.add_tag')}
                                    className="w-40 rounded-full border border-dashed border-neutral-300 px-3 py-1 text-xs focus:border-brand-400 focus:outline-none dark:border-neutral-600 dark:bg-neutral-800"
                                />
                                <datalist id="contact-tag-options">
                                    {allTags.map(x => <option key={x.id} value={x.name} />)}
                                </datalist>
                            </div>

                            {staticSegments.length > 0 && (
                                <>
                                    <p className="mt-5 text-sm font-medium text-neutral-700 dark:text-neutral-300">{t('contacts_page.segments')}</p>
                                    <div className="mt-2 flex flex-wrap gap-2">
                                        {staticSegments.map(seg => {
                                            const on = form.data.segment_ids.includes(seg.id);

                                            return (
                                                <button key={seg.id} type="button"
                                                    onClick={() => form.setData('segment_ids', on
                                                        ? form.data.segment_ids.filter(id => id !== seg.id)
                                                        : [...form.data.segment_ids, seg.id])}
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
                                </>
                            )}
                        </div>

                        <div className="flex flex-wrap items-center gap-3">
                            <button type="submit" disabled={form.processing}
                                className="rounded-xl bg-brand-600 px-4 py-2 text-sm font-semibold text-white transition hover:bg-brand-700 disabled:opacity-60">
                                {form.processing ? t('common.saving') : t('contacts_page.save_record')}
                            </button>
                            <Link href={route('client.contacts.index')}
                                className="rounded-xl border border-neutral-300 px-4 py-2 text-sm font-medium text-neutral-600 transition hover:bg-neutral-50 dark:border-neutral-600 dark:text-neutral-300 dark:hover:bg-neutral-800">
                                {t('contacts_page.back_to_list')}
                            </Link>
                            <button type="button" onClick={remove}
                                className="ml-auto inline-flex items-center gap-1.5 text-sm font-medium text-coral-600 transition hover:underline">
                                <Trash2 className="h-4 w-4" /> {t('contacts_page.delete_contact')}
                            </button>
                        </div>
                    </form>
                )}

                {tab === 'activity' && (
                    <div className="rounded-2xl border border-neutral-200 bg-white p-5 dark:border-neutral-700 dark:bg-neutral-900">
                        <h2 className="text-base font-semibold text-neutral-900 dark:text-neutral-100">{t('contacts_page.timeline_title')}</h2>

                        {activity.length === 0 ? (
                            <p className="mt-4 text-sm text-ink-muted dark:text-neutral-400">{t('contacts_page.timeline_empty')}</p>
                        ) : (
                            <ul className="mt-4 space-y-4">
                                {activity.map((entry, i) => (
                                    <li key={i} className="flex gap-3">
                                        <span className="mt-0.5 shrink-0">
                                            {entry.channel
                                                ? <ChannelBrandIcon channel={entry.channel} className="h-4 w-4" />
                                                : <MessageSquare className="h-4 w-4 text-neutral-300" />}
                                        </span>
                                        <div className="min-w-0">
                                            <p className="text-sm text-neutral-800 dark:text-neutral-200">
                                                {activityText(entry, t)}
                                            </p>
                                            <p className="text-xs text-neutral-400">{formatInTz(entry.at, userTz)}</p>
                                        </div>
                                    </li>
                                ))}
                            </ul>
                        )}

                        <p className="mt-5 border-t border-neutral-100 pt-4 text-xs text-neutral-400 dark:border-neutral-800">
                            {t('contacts_page.timeline_hint')}
                        </p>
                    </div>
                )}

                {tab === 'consents' && (
                    <form onSubmit={save} className="rounded-2xl border border-neutral-200 bg-white p-5 dark:border-neutral-700 dark:bg-neutral-900">
                        <h2 className="text-base font-semibold text-neutral-900 dark:text-neutral-100">{t('contacts_page.tab_consents')}</h2>
                        <p className="mt-0.5 text-sm text-ink-muted dark:text-neutral-400">{t('contacts_page.consents_hint')}</p>

                        <div className="mt-4 space-y-2">
                            {[
                                ['opt_in_whatsapp', 'whatsapp', 'WhatsApp'],
                                ['opt_in_sms', 'sms', t('contacts_page.channel_sms')],
                                ['opt_in_email', 'email', t('common.email')],
                            ].map(([key, channel, label]) => (
                                <label key={key} className="flex cursor-pointer items-center justify-between rounded-xl border border-neutral-200 px-4 py-3 dark:border-neutral-700">
                                    <span className="inline-flex items-center gap-2 text-sm text-neutral-700 dark:text-neutral-300">
                                        <ChannelBrandIcon channel={channel} className="h-4 w-4" />{label}
                                    </span>
                                    <input type="checkbox" checked={form.data[key]}
                                        onChange={e => form.setData(key, e.target.checked)}
                                        className="h-4 w-4 rounded text-brand-600" />
                                </label>
                            ))}
                        </div>

                        <button type="submit" disabled={form.processing}
                            className="mt-4 rounded-xl bg-brand-600 px-4 py-2 text-sm font-semibold text-white transition hover:bg-brand-700 disabled:opacity-60">
                            {form.processing ? t('common.saving') : t('contacts_page.save_record')}
                        </button>
                    </form>
                )}
            </div>
        </ClientLayout>
    );
}
