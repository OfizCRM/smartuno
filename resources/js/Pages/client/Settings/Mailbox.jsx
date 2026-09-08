import { Head, router, useForm } from '@inertiajs/react';
import axios from 'axios';
import { AlertTriangle, CheckCircle2, Loader2, Mail, Trash2 } from 'lucide-react';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import ClientLayout from '@/Layouts/ClientLayout';
import SettingsBackLink from '@/Components/SettingsBackLink';
import { Button, Card, Select } from '@/Components/ui';

/**
 * The firm's mailbox — the address customers write to.
 *
 * Separate from the campaign SMTP on purpose: a firm sends its newsletter from
 * marketing@ and talks to customers from office@, and a bulk sender's
 * reputation has no business sitting on real correspondence.
 *
 * Passwords are never sent to the browser. The form reports only whether one is
 * stored; leaving the field empty on save keeps it.
 */

const ENCRYPTIONS = ['ssl', 'tls', 'none'];

function ServerFields({ prefix, title, hint, data, errors, onChange, hasPassword }) {
    const { t } = useTranslation();

    return (
        <Card>
            <Card.Body className="space-y-4">
                <div>
                    <h2 className="text-base font-semibold text-neutral-900 dark:text-neutral-100">{title}</h2>
                    <p className="mt-0.5 text-sm text-ink-muted dark:text-neutral-400">{hint}</p>
                </div>

                <div className="grid gap-4 sm:grid-cols-2">
                    <div className="sm:col-span-2">
                        <label className="mb-1 block text-sm font-medium text-neutral-700 dark:text-neutral-300">
                            {t('mailbox.host')}
                        </label>
                        <input
                            value={data.host}
                            onChange={e => onChange('host', e.target.value)}
                            placeholder="mail.firma.ro"
                            className="w-full rounded-xl border border-neutral-300 bg-white px-3 py-2 text-sm focus:border-brand-400 focus:outline-none focus:ring-2 focus:ring-brand-500/20 dark:border-neutral-600 dark:bg-neutral-800"
                        />
                        {errors[`${prefix}.host`] && <p className="mt-1 text-xs text-coral-600">{errors[`${prefix}.host`]}</p>}
                    </div>

                    <div>
                        <label className="mb-1 block text-sm font-medium text-neutral-700 dark:text-neutral-300">
                            {t('mailbox.port')}
                        </label>
                        <input
                            type="number"
                            value={data.port ?? ''}
                            onChange={e => onChange('port', e.target.value)}
                            className="w-full rounded-xl border border-neutral-300 bg-white px-3 py-2 text-sm tabular-nums focus:border-brand-400 focus:outline-none focus:ring-2 focus:ring-brand-500/20 dark:border-neutral-600 dark:bg-neutral-800"
                        />
                        {errors[`${prefix}.port`] && <p className="mt-1 text-xs text-coral-600">{errors[`${prefix}.port`]}</p>}
                    </div>

                    <Select
                        label={t('mailbox.encryption')}
                        value={data.encryption}
                        onChange={e => onChange('encryption', e.target.value)}
                        options={ENCRYPTIONS.map(v => ({ value: v, label: t(`mailbox.encryption_${v}`) }))}
                        placeholder=""
                    />

                    <div>
                        <label className="mb-1 block text-sm font-medium text-neutral-700 dark:text-neutral-300">
                            {t('mailbox.username')}
                        </label>
                        <input
                            value={data.username}
                            onChange={e => onChange('username', e.target.value)}
                            className="w-full rounded-xl border border-neutral-300 bg-white px-3 py-2 text-sm focus:border-brand-400 focus:outline-none focus:ring-2 focus:ring-brand-500/20 dark:border-neutral-600 dark:bg-neutral-800"
                        />
                        {errors[`${prefix}.username`] && <p className="mt-1 text-xs text-coral-600">{errors[`${prefix}.username`]}</p>}
                    </div>

                    <div>
                        <label className="mb-1 block text-sm font-medium text-neutral-700 dark:text-neutral-300">
                            {t('mailbox.password')}
                        </label>
                        <input
                            type="password"
                            value={data.password}
                            autoComplete="new-password"
                            placeholder={hasPassword ? t('mailbox.password_stored') : ''}
                            onChange={e => onChange('password', e.target.value)}
                            className="w-full rounded-xl border border-neutral-300 bg-white px-3 py-2 text-sm focus:border-brand-400 focus:outline-none focus:ring-2 focus:ring-brand-500/20 dark:border-neutral-600 dark:bg-neutral-800"
                        />
                        {errors[`${prefix}.password`] && <p className="mt-1 text-xs text-coral-600">{errors[`${prefix}.password`]}</p>}
                    </div>
                </div>
            </Card.Body>
        </Card>
    );
}

function TestResult({ label, result }) {
    if (! result) {
        return null;
    }

    const Icon = result.ok ? CheckCircle2 : AlertTriangle;

    return (
        <p className={`flex items-start gap-1.5 text-sm ${result.ok ? 'text-brand-700 dark:text-brand-300' : 'text-coral-600'}`}>
            <Icon className="mt-0.5 h-4 w-4 shrink-0" />
            <span><strong>{label}:</strong> {result.message}</span>
        </p>
    );
}

export default function Mailbox({ mailbox, pollChoices = [10] }) {
    const { t } = useTranslation();
    const [testing, setTesting] = useState(false);
    const [testResult, setTestResult] = useState(null);

    const blank = { host: '', port: '', encryption: 'ssl', username: '', password: '' };

    const form = useForm({
        email: mailbox?.email ?? '',
        from_name: mailbox?.from_name ?? '',
        imap: { ...blank, ...(mailbox?.imap ?? {}), password: '', port: mailbox?.imap?.port ?? 993 },
        smtp: { ...blank, ...(mailbox?.smtp ?? {}), password: '', port: mailbox?.smtp?.port ?? 465 },
        poll_minutes: mailbox?.poll_minutes ?? 10,
        initial_days: mailbox?.initial_days ?? 2,
    });

    const setServer = (part) => (field, value) => form.setData(part, { ...form.data[part], [field]: value });

    // Fill in the servers the way a mail client does, from the domain. Only ever
    // a suggestion — every field stays editable.
    const guess = () => {
        const domain = (form.data.email.split('@')[1] ?? '').toLowerCase();
        if (! domain) return;

        const known = {
            'gmail.com': ['imap.gmail.com', 'smtp.gmail.com'],
            'googlemail.com': ['imap.gmail.com', 'smtp.gmail.com'],
            'outlook.com': ['outlook.office365.com', 'smtp.office365.com'],
            'hotmail.com': ['outlook.office365.com', 'smtp.office365.com'],
            'yahoo.com': ['imap.mail.yahoo.com', 'smtp.mail.yahoo.com'],
        };
        const [imapHost, smtpHost] = known[domain] ?? [`mail.${domain}`, `mail.${domain}`];

        form.setData({
            ...form.data,
            imap: { ...form.data.imap, host: imapHost, port: 993, encryption: 'ssl', username: form.data.email },
            smtp: { ...form.data.smtp, host: smtpHost, port: 465, encryption: 'ssl', username: form.data.email },
        });
    };

    const runTest = () => {
        setTesting(true);
        setTestResult(null);
        axios.post(route('client.mailbox.test'), form.data)
            .then(r => setTestResult(r.data))
            .catch(e => setTestResult({
                imap: { ok: false, message: e.response?.data?.message ?? t('mailbox.test_failed') },
                smtp: { ok: false, message: '' },
            }))
            .finally(() => setTesting(false));
    };

    const save = (e) => {
        e.preventDefault();
        form.post(route('client.mailbox.store'), { preserveScroll: true });
    };

    const remove = () => {
        if (confirm(t('mailbox.remove_confirm'))) {
            router.delete(route('client.mailbox.destroy'), { preserveScroll: true });
        }
    };

    return (
        <ClientLayout title={t('mailbox.title')}>
            <Head title={t('mailbox.title')} />

            <form onSubmit={save} className="mx-auto max-w-3xl space-y-5">
                <div>
                    <SettingsBackLink />
                    <h1 className="mt-2 flex items-center gap-2 text-2xl font-bold text-neutral-900 dark:text-white">
                        <Mail className="h-6 w-6 text-brand-600" /> {t('mailbox.title')}
                    </h1>
                    <p className="mt-1 text-sm text-ink-muted dark:text-neutral-400">{t('mailbox.subtitle')}</p>
                </div>

                {mailbox?.status === 'error' && mailbox.last_error && (
                    <div className="flex items-start gap-2 rounded-xl bg-coral-50 px-4 py-3 text-sm text-coral-700 dark:bg-coral-900/20 dark:text-coral-300">
                        <AlertTriangle className="mt-0.5 h-4 w-4 shrink-0" />
                        <span>{t('mailbox.stopped_working')} {mailbox.last_error}</span>
                    </div>
                )}

                <Card>
                    <Card.Body className="space-y-4">
                        <div className="grid gap-4 sm:grid-cols-2">
                            <div>
                                <label className="mb-1 block text-sm font-medium text-neutral-700 dark:text-neutral-300">
                                    {t('mailbox.address')}
                                </label>
                                <input
                                    type="email"
                                    value={form.data.email}
                                    onChange={e => form.setData('email', e.target.value)}
                                    onBlur={() => { if (! form.data.imap.host) guess(); }}
                                    placeholder="office@firma.ro"
                                    className="w-full rounded-xl border border-neutral-300 bg-white px-3 py-2 text-sm focus:border-brand-400 focus:outline-none focus:ring-2 focus:ring-brand-500/20 dark:border-neutral-600 dark:bg-neutral-800"
                                />
                                {form.errors.email && <p className="mt-1 text-xs text-coral-600">{form.errors.email}</p>}
                            </div>

                            <div>
                                <label className="mb-1 block text-sm font-medium text-neutral-700 dark:text-neutral-300">
                                    {t('mailbox.from_name')}
                                </label>
                                <input
                                    value={form.data.from_name}
                                    onChange={e => form.setData('from_name', e.target.value)}
                                    placeholder="Farmacia Verde"
                                    className="w-full rounded-xl border border-neutral-300 bg-white px-3 py-2 text-sm focus:border-brand-400 focus:outline-none focus:ring-2 focus:ring-brand-500/20 dark:border-neutral-600 dark:bg-neutral-800"
                                />
                            </div>
                        </div>

                        <Button type="button" variant="secondary" onClick={guess} disabled={! form.data.email.includes('@')}>
                            {t('mailbox.autofill')}
                        </Button>
                    </Card.Body>
                </Card>

                <ServerFields
                    prefix="imap"
                    title={t('mailbox.imap_title')}
                    hint={t('mailbox.imap_hint')}
                    data={form.data.imap}
                    errors={form.errors}
                    onChange={setServer('imap')}
                    hasPassword={mailbox?.imap?.has_password}
                />

                <ServerFields
                    prefix="smtp"
                    title={t('mailbox.smtp_title')}
                    hint={t('mailbox.smtp_hint')}
                    data={form.data.smtp}
                    errors={form.errors}
                    onChange={setServer('smtp')}
                    hasPassword={mailbox?.smtp?.has_password}
                />

                <Card>
                    <Card.Body className="space-y-4">
                        <div className="grid gap-4 sm:grid-cols-2">
                            <Select
                                label={t('mailbox.poll_minutes')}
                                value={String(form.data.poll_minutes)}
                                onChange={e => form.setData('poll_minutes', Number(e.target.value))}
                                options={pollChoices.map(v => ({ value: String(v), label: t('mailbox.every_minutes', { count: v }) }))}
                                placeholder=""
                            />
                            <div>
                                <label className="mb-1 block text-sm font-medium text-neutral-700 dark:text-neutral-300">
                                    {t('mailbox.initial_days')}
                                </label>
                                <input
                                    type="number"
                                    min="0"
                                    max="90"
                                    value={form.data.initial_days}
                                    onChange={e => form.setData('initial_days', e.target.value)}
                                    className="w-full rounded-xl border border-neutral-300 bg-white px-3 py-2 text-sm tabular-nums focus:border-brand-400 focus:outline-none focus:ring-2 focus:ring-brand-500/20 dark:border-neutral-600 dark:bg-neutral-800"
                                />
                                <p className="mt-1 text-xs text-ink-muted">{t('mailbox.initial_days_hint')}</p>
                            </div>
                        </div>
                    </Card.Body>
                </Card>

                {(testResult) && (
                    <div className="space-y-1.5 rounded-xl border border-neutral-200 bg-white px-4 py-3 dark:border-neutral-700 dark:bg-neutral-900">
                        <TestResult label={t('mailbox.imap_short')} result={testResult.imap} />
                        <TestResult label={t('mailbox.smtp_short')} result={testResult.smtp} />
                    </div>
                )}

                <div className="flex flex-wrap items-center gap-3">
                    <Button type="submit" variant="primary" disabled={form.processing}>
                        {form.processing ? t('common.saving') : t('mailbox.save')}
                    </Button>
                    <Button type="button" variant="secondary" onClick={runTest} disabled={testing}>
                        {testing ? <Loader2 className="h-4 w-4 animate-spin" /> : t('mailbox.test')}
                    </Button>
                    {mailbox && (
                        <button type="button" onClick={remove}
                            className="ml-auto inline-flex items-center gap-1.5 text-sm font-medium text-coral-600 transition hover:underline">
                            <Trash2 className="h-4 w-4" /> {t('mailbox.remove')}
                        </button>
                    )}
                </div>
            </form>
        </ClientLayout>
    );
}
