import { Head, useForm, usePage } from '@inertiajs/react';
import { useTranslation } from 'react-i18next';
import ClientLayout from '@/Layouts/ClientLayout';
import SettingsBackLink from '@/Components/SettingsBackLink';
import { Button, Card, Input } from '@/Components/ui';

/**
 * The organisation's own details. Administrators only — the controller aborts
 * for everyone else, the same guard TeamController uses.
 */
export default function SettingsCompany({ client = {} }) {
    const { t } = useTranslation();
    const { flash = {} } = usePage().props;

    const form = useForm({
        client_name: client?.name ?? '',
        client_email: client?.email ?? '',
        client_phone: client?.phone ?? '',
        client_address: client?.address ?? '',
    });

    const submit = (e) => {
        e.preventDefault();
        form.put(route('client.settings.update'), { preserveScroll: true });
    };

    return (
        <ClientLayout title={t('settings_hub.company_details')}>
            <Head title={t('settings_hub.company_details')} />

            <div className="mx-auto max-w-3xl space-y-5">
                <div>
                    <SettingsBackLink />
                    <h1 className="mt-2 text-2xl font-bold text-neutral-900 dark:text-white">{t('settings_hub.company_details')}</h1>
                    <p className="mt-1 text-sm text-ink-muted dark:text-neutral-400">{t('settings_hub.company_details_desc')}</p>
                </div>

                {flash?.success && (
                    <div className="rounded-xl bg-brand-50 px-4 py-3 text-sm text-brand-800 dark:bg-brand-900/25 dark:text-brand-200">
                        {flash.success}
                    </div>
                )}

                <form onSubmit={submit}>
                    <Card>
                        <Card.Body className="space-y-4">
                            <Input
                                label={t('client.organization_name')}
                                name="client_name"
                                value={form.data.client_name}
                                onChange={(e) => form.setData('client_name', e.target.value)}
                                error={form.errors.client_name}
                            />
                            <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                                <Input
                                    type="email"
                                    label={t('client.organization_email')}
                                    name="client_email"
                                    value={form.data.client_email}
                                    onChange={(e) => form.setData('client_email', e.target.value)}
                                    error={form.errors.client_email}
                                />
                                <Input
                                    label={t('client.phone')}
                                    name="client_phone"
                                    value={form.data.client_phone}
                                    onChange={(e) => form.setData('client_phone', e.target.value)}
                                    error={form.errors.client_phone}
                                />
                            </div>
                            <div>
                                <label htmlFor="client_address" className="mb-1 block text-sm font-medium text-neutral-700 dark:text-neutral-300">
                                    {t('client.address')}
                                </label>
                                <textarea
                                    id="client_address"
                                    rows={2}
                                    value={form.data.client_address}
                                    onChange={(e) => form.setData('client_address', e.target.value)}
                                    className="w-full rounded-lg border border-neutral-300 bg-white px-3 py-2 text-sm focus:border-brand-500 focus:ring-brand-500/20 dark:border-neutral-600 dark:bg-neutral-800"
                                />
                                {form.errors.client_address && <p className="mt-1 text-xs text-coral-600">{form.errors.client_address}</p>}
                            </div>

                            <div className="pt-2">
                                <Button type="submit" variant="primary" disabled={form.processing}>
                                    {t('client.save_settings')}
                                </Button>
                            </div>
                        </Card.Body>
                    </Card>
                </form>
            </div>
        </ClientLayout>
    );
}
