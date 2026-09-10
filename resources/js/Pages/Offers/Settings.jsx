import { Head, Link, useForm, usePage } from '@inertiajs/react';
import { ArrowLeft } from 'lucide-react';
import { useTranslation } from 'react-i18next';
import { Button, Card, Input, NudgeBanner } from '@/Components/ui';
import ClientLayout from '@/Layouts/ClientLayout';
import { leiFromCents, normaliseLei } from '@/Utils/money';

/**
 * The five things a firm sets once and then forgets.
 *
 * Deliberately five fields and no tabs: everything else an offer needs — the
 * company name, the CUI, the IBAN, the address printed on the PDF — already
 * lives on the company profile, and a second place to type a CUI is a second
 * place for it to be wrong.
 *
 * Money is bani in the props and lei in the boxes, the same split as the
 * catalogue: the person types "25" and the server multiplies. The conversion is
 * not done here, because there is exactly one copy of it and it is in PHP.
 *
 * The settings hang off the CLIENT, not the workspace, and workspaces.client_id
 * is nullable — so `hasClient` false is a real state, not an edge case. The form
 * is inert then rather than absent: a disabled field the person can read tells
 * them what they will get once the company profile is filled in, where an empty
 * page tells them nothing.
 */
export default function OffersSettings({ settings = {}, hasClient = false, aiDraftingEnabled = false }) {
    const { t } = useTranslation();
    const { flash = {} } = usePage().props;

    const form = useForm({
        validity_days: String(settings.validity_days ?? 14),
        // leiFromCents gives '' for zero, which is what an unpriced box should
        // look like — 0 lei of shipping is "nothing typed here", not "0,00".
        shipping: leiFromCents(settings.shipping_cents),
        free_shipping: leiFromCents(settings.free_shipping_cents),
        default_discount_percent: String(settings.default_discount_percent ?? 0),
        footer_text: settings.footer_text ?? '',
        ai_drafting_enabled: !! aiDraftingEnabled,
    });

    const save = (e) => {
        e.preventDefault();

        // A Romanian keyboard produces "25,50" and Laravel's numeric rule
        // refuses it. Normalise the separator on the way out; the unit stays lei.
        form.transform((data) => ({
            ...data,
            shipping: normaliseLei(data.shipping),
            free_shipping: normaliseLei(data.free_shipping),
        }));

        // The confirmation is the server's flash line, not a toast invented
        // here: a save that redirects without one has not been saved.
        form.put(route('client.offers.settings.save'), { preserveScroll: true });
    };

    return (
        <ClientLayout title={t('offers.settings_title')}>
            <Head title={t('offers.settings_title')} />

            <form onSubmit={save} className="mx-auto max-w-2xl space-y-4">
                <div>
                    <Link
                        href={route('client.offers.index')}
                        className="inline-flex items-center gap-1.5 text-sm text-ink-muted transition hover:text-neutral-800 dark:hover:text-neutral-200"
                    >
                        <ArrowLeft className="h-4 w-4" /> {t('common.back')}
                    </Link>
                    <h1 className="mt-2 text-2xl font-bold text-neutral-900 dark:text-white">
                        {t('offers.settings_title')}
                    </h1>
                    <p className="mt-1 text-sm text-ink-muted dark:text-neutral-400">
                        {t('offers.settings_subtitle')}
                    </p>
                </div>

                {flash?.success && (
                    <div className="rounded-xl bg-brand-50 px-4 py-3 text-sm text-brand-800 dark:bg-brand-900/25 dark:text-brand-200">
                        {flash.success}
                    </div>
                )}

                {! hasClient && (
                    <NudgeBanner
                        tone="accent"
                        title={t('offers.no_client_title')}
                        description={t('offers.no_client_desc')}
                    />
                )}

                {/* The kill switch. It is the reason the drafting could be
                    deployed at all: it arrives off, and a firm is switched on by
                    hand once its catalogue has been described well enough for
                    the agent to have something to reason with. */}
                <Card>
                    <Card.Body>
                        <label className="flex cursor-pointer items-start gap-3">
                            <input
                                type="checkbox"
                                className="mt-0.5 rounded border-neutral-300 text-brand-600 focus:ring-brand-500/30"
                                checked={form.data.ai_drafting_enabled}
                                onChange={(e) => form.setData('ai_drafting_enabled', e.target.checked)}
                                disabled={! hasClient}
                            />
                            <span>
                                <span className="block text-sm font-medium text-neutral-900 dark:text-neutral-100">
                                    {t('offers.ai_drafting')}
                                </span>
                                <span className="mt-0.5 block text-xs text-neutral-500 dark:text-neutral-400">
                                    {t('offers.ai_drafting_hint')}
                                </span>
                            </span>
                        </label>
                    </Card.Body>
                </Card>

                <Card>
                    <Card.Body className="space-y-4">
                        <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                            <Input
                                name="validity_days"
                                type="number"
                                min="1"
                                max="365"
                                label={t('offers.validity_days')}
                                hint={t('offers.validity_days_hint')}
                                value={form.data.validity_days}
                                onChange={(e) => form.setData('validity_days', e.target.value)}
                                error={form.errors.validity_days}
                                disabled={! hasClient}
                            />
                            <Input
                                name="default_discount_percent"
                                type="number"
                                min="0"
                                max="100"
                                step="1"
                                label={t('offers.default_discount')}
                                value={form.data.default_discount_percent}
                                onChange={(e) => form.setData('default_discount_percent', e.target.value)}
                                error={form.errors.default_discount_percent}
                                disabled={! hasClient}
                            />
                            <Input
                                name="shipping"
                                inputMode="decimal"
                                label={t('offers.shipping_cost')}
                                placeholder="0,00"
                                value={form.data.shipping}
                                onChange={(e) => form.setData('shipping', e.target.value)}
                                error={form.errors.shipping}
                                disabled={! hasClient}
                            />
                            <Input
                                name="free_shipping"
                                inputMode="decimal"
                                label={t('offers.free_shipping_threshold')}
                                hint={t('offers.free_shipping_hint_setting')}
                                placeholder="0,00"
                                value={form.data.free_shipping}
                                onChange={(e) => form.setData('free_shipping', e.target.value)}
                                error={form.errors.free_shipping}
                                disabled={! hasClient}
                            />
                        </div>

                        <div>
                            <label
                                htmlFor="footer_text"
                                className="mb-1.5 block text-sm font-medium text-neutral-700 dark:text-neutral-300"
                            >
                                {t('offers.footer_text')}
                            </label>
                            <textarea
                                id="footer_text"
                                name="footer_text"
                                rows={3}
                                value={form.data.footer_text}
                                onChange={(e) => form.setData('footer_text', e.target.value)}
                                disabled={! hasClient}
                                className="w-full rounded-xl border border-warm-border bg-white px-3 py-2 text-[13px] text-warm-gray-900 shadow-inner transition duration-150 placeholder:text-warm-gray-500 focus:border-brand-500 focus:outline-none focus:ring-2 focus:ring-brand-500/20 disabled:opacity-50 dark:border-neutral-600 dark:bg-neutral-800 dark:text-neutral-100 dark:placeholder:text-neutral-500"
                            />
                            {form.errors.footer_text && (
                                <p className="mt-1.5 text-sm text-coral-600 dark:text-coral-400">{form.errors.footer_text}</p>
                            )}
                            {! form.errors.footer_text && (
                                <p className="mt-1.5 text-sm text-neutral-500 dark:text-neutral-400">
                                    {t('offers.footer_text_hint')}
                                </p>
                            )}
                        </div>

                        <div className="pt-1">
                            <Button type="submit" variant="primary" disabled={form.processing || ! hasClient}>
                                {t('offers.save')}
                            </Button>
                        </div>
                    </Card.Body>
                </Card>
            </form>
        </ClientLayout>
    );
}
