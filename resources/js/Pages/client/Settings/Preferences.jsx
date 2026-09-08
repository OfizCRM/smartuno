import { Head, useForm, usePage } from '@inertiajs/react';
import { useTranslation } from 'react-i18next';
import ClientLayout from '@/Layouts/ClientLayout';
import SettingsBackLink from '@/Components/SettingsBackLink';
import { Button, Card, Select } from '@/Components/ui';
import TimezonePicker from '@/Components/TimezonePicker';
import { useTheme } from '@/context/ThemeContext';
import { browserTz } from '@/Utils/datetime';

/**
 * Personal preferences — language, currency, theme, timezone.
 *
 * Split out of the old single Settings page, which also carried the organisation
 * form and the digest toggle. Those went to Company and Notifications; a page
 * that saves three unrelated things at once made every save feel risky.
 */
export default function SettingsPreferences({ preferences = {}, supportedLocales = [], supportedCurrencies = [] }) {
    const { t } = useTranslation();
    const { flash = {} } = usePage().props;
    const { setTheme } = useTheme();

    const form = useForm({
        locale: preferences.locale ?? 'en',
        display_currency: preferences.display_currency ?? 'RON',
        theme: preferences.theme ?? 'light',
        timezone: preferences.timezone ?? browserTz() ?? 'Europe/Bucharest',
    });

    const submit = (e) => {
        e.preventDefault();
        form.put(route('client.settings.update'), {
            preserveScroll: true,
            // Saving the row is not enough: ThemeProvider treats localStorage as
            // authoritative and explicitly ignores a server theme that disagrees with
            // it, so without this the choice persists to the database and is never
            // applied — not even after a reload. setTheme writes both, which is what
            // the header toggle already does.
            onSuccess: () => setTheme(form.data.theme),
        });
    };

    return (
        <ClientLayout title={t('settings_hub.my_preferences')}>
            <Head title={t('settings_hub.my_preferences')} />

            <div className="mx-auto max-w-3xl space-y-5">
                <div>
                    <SettingsBackLink />
                    <h1 className="mt-2 text-2xl font-bold text-neutral-900 dark:text-white">{t('settings_hub.my_preferences')}</h1>
                    <p className="mt-1 text-sm text-ink-muted dark:text-neutral-400">{t('settings_hub.my_preferences_desc')}</p>
                </div>

                {flash?.success && (
                    <div className="rounded-xl bg-brand-50 px-4 py-3 text-sm text-brand-800 dark:bg-brand-900/25 dark:text-brand-200">
                        {flash.success}
                    </div>
                )}

                <form onSubmit={submit}>
                    <Card>
                        <Card.Body className="space-y-4">
                            <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                                <Select
                                    label={t('client.language')}
                                    value={form.data.locale}
                                    onChange={(e) => form.setData('locale', e.target.value)}
                                    options={supportedLocales.map((l) => ({ value: l.code, label: l.name }))}
                                    placeholder=""
                                    error={form.errors.locale}
                                />
                                <Select
                                    label={t('client.display_currency')}
                                    value={form.data.display_currency}
                                    onChange={(e) => form.setData('display_currency', e.target.value)}
                                    options={supportedCurrencies.map((c) => ({
                                        value: c.code,
                                        label: c.symbol ? `${c.code} (${c.symbol})` : c.code,
                                    }))}
                                    placeholder=""
                                    error={form.errors.display_currency}
                                />
                                <Select
                                    label={t('client.theme')}
                                    value={form.data.theme}
                                    onChange={(e) => form.setData('theme', e.target.value)}
                                    options={[
                                        { value: 'light', label: t('client.theme_light') },
                                        { value: 'dark', label: t('client.theme_dark') },
                                    ]}
                                    placeholder=""
                                    error={form.errors.theme}
                                />
                                <div>
                                    <label className="mb-1 block text-sm font-medium text-neutral-700 dark:text-neutral-300">
                                        {t('settings.timezone')}
                                    </label>
                                    <TimezonePicker value={form.data.timezone} onChange={(tz) => form.setData('timezone', tz)} />
                                    {form.errors.timezone && <p className="mt-1 text-xs text-coral-600">{form.errors.timezone}</p>}
                                    <p className="mt-1 text-xs text-ink-muted">{t('settings.timezone_hint')}</p>
                                </div>
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
