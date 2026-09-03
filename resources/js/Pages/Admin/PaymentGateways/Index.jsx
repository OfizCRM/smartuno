import { useState, useEffect } from 'react';
import AdminLayout from '@/Layouts/AdminLayout';
import { Button, Card, Modal, Toggle } from '@/Components/ui';
import { Head, router, usePage } from '@inertiajs/react';
import { useForm } from '@inertiajs/react';
import axios from 'axios';
import { useTranslation } from 'react-i18next';

// Brand marks served from public/images/gateways (Simple Icons). Gateways without
// a bundled mark fall back to a brand-colored letter tile below.
const GATEWAY_LOGOS = {
    stripe: '/images/gateways/stripe.svg',
    paypal: '/images/gateways/paypal.svg',
};

const GATEWAY_FALLBACK_COLORS = {};

function GatewayLogo({ gateway, name }) {
    const src = GATEWAY_LOGOS[gateway];
    if (src) {
        return (
            <span className="flex h-10 w-10 shrink-0 items-center justify-center rounded-soft-lg bg-white ring-1 ring-neutral-200 dark:ring-neutral-600">
                <img src={src} alt={`${name} logo`} className="h-5 w-5" loading="lazy" />
            </span>
        );
    }
    return (
        <span
            className="flex h-10 w-10 shrink-0 items-center justify-center rounded-soft-lg text-sm font-bold text-white"
            style={{ backgroundColor: GATEWAY_FALLBACK_COLORS[gateway] ?? '#6B7280' }}
        >
            {name.charAt(0).toUpperCase()}
        </span>
    );
}

export default function AdminPaymentGatewaysIndex({ gateways = [], flash = {} }) {
    const { t } = useTranslation();
    const { props } = usePage();
    const validationErrors = props.errors ?? {};
    const [editGateway, setEditGateway] = useState(null);
    const [formData, setFormData] = useState(null);
    const [loading, setLoading] = useState(false);
    const [fetchError, setFetchError] = useState(null);

    const openEdit = async (gatewayKey) => {
        setEditGateway(gatewayKey);
        setFetchError(null);
        setFormData(null);
        setLoading(true);
        try {
            const { data } = await axios.get(route('admin.payment-gateways.show', gatewayKey));
            setFormData(data);
        } catch (e) {
            setFetchError(e?.response?.data?.message || t('admin.gateway_load_failed'));
        } finally {
            setLoading(false);
        }
    };

    const closeEdit = () => {
        setEditGateway(null);
        setFormData(null);
        setFetchError(null);
    };

    return (
        <AdminLayout title={t('admin.payment_gateways')}>
            <Head title={`${t('admin.payment_gateways')} · Admin`} />
            <div className="space-y-6">
                <div className="flex flex-wrap items-center justify-between gap-4">
                    <h2 className="text-xl font-semibold text-neutral-900 dark:text-neutral-100">{t('admin.payment_gateways')}</h2>
                    <a
                        href={route('admin.payments.index')}
                        className="text-sm font-medium text-brand-600 hover:text-brand-700 dark:text-brand-400 dark:hover:text-brand-300"
                    >
                        {t('admin.view_payments')}
                    </a>
                </div>
                {flash?.success && (
                    <div className="rounded-soft-lg bg-green-50 dark:bg-green-900/30 text-green-800 dark:text-green-200 px-4 py-2 text-sm">
                        {flash.success}
                    </div>
                )}
                <p className="text-sm text-neutral-500 dark:text-neutral-400">
                    {t('admin.payment_gateways_desc')}
                </p>
                <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                    {gateways.map((g) => (
                        <Card key={g.gateway}>
                            <Card.Body className="flex flex-col">
                                <div className="flex items-center justify-between gap-3">
                                    <div className="flex min-w-0 items-center gap-3">
                                        <GatewayLogo gateway={g.gateway} name={g.name} />
                                        <h3 className="truncate font-semibold text-neutral-900 dark:text-neutral-100">{g.name}</h3>
                                    </div>
                                    <span
                                        className={`rounded-full px-2 py-0.5 text-xs font-medium ${
                                            g.configured
                                                ? 'bg-green-100 text-green-800 dark:bg-green-900/40 dark:text-green-200'
                                                : 'bg-neutral-100 text-neutral-600 dark:bg-neutral-700 dark:text-neutral-300'
                                        }`}
                                    >
                                        {g.configured ? t('admin.configured') : t('admin.not_configured')}
                                    </span>
                                </div>
                                <p className="mt-1 text-sm text-neutral-500 dark:text-neutral-400">
                                    {g.enabled ? t('common.enabled') : t('admin.disabled')} · {g.test_mode ? t('admin.test_mode') : t('admin.live_mode')}
                                </p>
                                <Button
                                    type="button"
                                    variant="outline"
                                    size="sm"
                                    className="mt-4"
                                    onClick={() => openEdit(g.gateway)}
                                >
                                    {t('admin.edit_payment_gateway')}
                                </Button>
                            </Card.Body>
                        </Card>
                    ))}
                </div>
                <EditGatewayModal
                    show={!!editGateway}
                    gatewayKey={editGateway}
                    initialData={formData}
                    loading={loading}
                    error={fetchError}
                    validationErrors={validationErrors}
                    onClose={closeEdit}
                    onSaved={() => {
                        closeEdit();
                        router.reload();
                    }}
                />
            </div>
        </AdminLayout>
    );
}

function EditGatewayModal({ show, gatewayKey, initialData, loading, error, validationErrors = {}, onClose, onSaved }) {
    const { t } = useTranslation();
    const { data, setData, put, processing } = useForm({
        test_mode: true,
        enabled: false,
        test_publishable_key: '',
        test_secret_key: '',
        test_webhook_secret: '',
        live_publishable_key: '',
        live_secret_key: '',
        live_webhook_secret: '',
    });

    useEffect(() => {
        if (!initialData || initialData.gateway !== gatewayKey) return;
        setData({
            test_mode: initialData.test_mode,
            enabled: initialData.enabled,
            test_publishable_key: initialData.test_publishable_key ?? '',
            test_secret_key: initialData.test_secret_key ?? '',
            test_webhook_secret: initialData.test_webhook_secret ?? '',
            live_publishable_key: initialData.live_publishable_key ?? '',
            live_secret_key: initialData.live_secret_key ?? '',
            live_webhook_secret: initialData.live_webhook_secret ?? '',
        });
    }, [gatewayKey, initialData]);

    const handleSubmit = (e) => {
        e.preventDefault();
        put(route('admin.payment-gateways.update', gatewayKey), {
            preserveScroll: true,
            onSuccess: () => onSaved(),
        });
    };

    const isStripe = gatewayKey === 'stripe';
    const publishableHint = isStripe ? t('admin.stripe_pk_hint') : t('admin.publishable_key_hint');
    const secretHint = isStripe ? t('admin.stripe_sk_hint') : t('admin.secret_key_hint');
    const webhookHint = isStripe ? t('admin.stripe_webhook_hint') : t('admin.webhook_hint');
    const gatewayNote = t('admin.gateway_credentials_note');

    return (
        <Modal show={show} onClose={onClose} maxWidth="2xl">
            <Modal.Header title={t('admin.edit_payment_gateway')} onClose={onClose} />
            <form onSubmit={handleSubmit}>
                <Modal.Body className="space-y-6">
                    {loading && (
                        <div className="py-8 text-center text-neutral-500 dark:text-neutral-400">{t('common.loading')}</div>
                    )}
                    {error && (
                        <div className="rounded-soft-lg border border-coral-200 bg-coral-50 dark:bg-coral-900/20 dark:border-coral-800 px-4 py-2 text-sm text-coral-800 dark:text-coral-200">
                            {error}
                        </div>
                    )}
                    {!loading && initialData && (
                        <>
                            <div>
                                <h4 className="text-sm font-medium text-neutral-900 dark:text-neutral-100">{t('admin.test_mode')}</h4>
                                <p className="mt-0.5 text-sm text-neutral-500 dark:text-neutral-400">
                                    {t('admin.test_mode_desc')}
                                </p>
                                <Toggle
                                    checked={data.test_mode}
                                    onChange={(v) => setData('test_mode', v)}
                                    label={data.test_mode ? t('admin.toggle_on') : t('admin.toggle_off')}
                                    className="mt-2"
                                />
                            </div>

                            <div>
                                <h4 className="text-sm font-semibold text-neutral-900 dark:text-neutral-100 uppercase tracking-wide">{t('admin.test_credentials')}</h4>
                                <div className="mt-3 space-y-3">
                                    <div>
                                        <label className="block text-sm font-medium text-neutral-700 dark:text-neutral-300">
                                            {t('admin.publishable_key')} <span className="text-coral-500">*</span>
                                        </label>
                                        <input
                                            type="text"
                                            value={data.test_publishable_key}
                                            onChange={(e) => setData('test_publishable_key', e.target.value)}
                                            placeholder={t('admin.stripe_pk_placeholder')}
                                            className="mt-1 w-full rounded-soft border border-neutral-300 dark:border-neutral-600 bg-white dark:bg-neutral-800 text-neutral-900 dark:text-neutral-100 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-brand-500/20"
                                        />
                                        <p className="mt-0.5 text-xs text-neutral-500 dark:text-neutral-400">{publishableHint}</p>
                                    </div>
                                    <div>
                                        <label className="block text-sm font-medium text-neutral-700 dark:text-neutral-300">
                                            {t('admin.secret_key')} <span className="text-coral-500">*</span>
                                        </label>
                                        <input
                                            type="password"
                                            value={data.test_secret_key}
                                            onChange={(e) => setData('test_secret_key', e.target.value)}
                                            placeholder={t('admin.stripe_sk_placeholder')}
                                            className={`mt-1 w-full rounded-soft border px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-brand-500/20 ${
                                                validationErrors.test_secret_key
                                                    ? 'border-coral-500 bg-coral-50 dark:bg-coral-900/10 dark:border-coral-600'
                                                    : 'border-neutral-300 dark:border-neutral-600 bg-white dark:bg-neutral-800'
                                            } text-neutral-900 dark:text-neutral-100`}
                                        />
                                        {validationErrors.test_secret_key && (
                                            <p className="mt-0.5 text-xs text-coral-600 dark:text-coral-400">{validationErrors.test_secret_key}</p>
                                        )}
                                        <p className="mt-0.5 text-xs text-neutral-500 dark:text-neutral-400">{secretHint}</p>
                                    </div>
                                    <div>
                                        <label className="block text-sm font-medium text-neutral-700 dark:text-neutral-300">{t('admin.webhook_secret')}</label>
                                        <input
                                            type="password"
                                            value={data.test_webhook_secret}
                                            onChange={(e) => setData('test_webhook_secret', e.target.value)}
                                            placeholder={t('admin.webhook_secret_placeholder')}
                                            className="mt-1 w-full rounded-soft border border-neutral-300 dark:border-neutral-600 bg-white dark:bg-neutral-800 text-neutral-900 dark:text-neutral-100 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-brand-500/20"
                                        />
                                        <p className="mt-0.5 text-xs text-neutral-500 dark:text-neutral-400">{webhookHint}</p>
                                    </div>
                                </div>
                            </div>

                            <div>
                                <h4 className="text-sm font-semibold text-neutral-900 dark:text-neutral-100 uppercase tracking-wide">{t('admin.live_credentials')}</h4>
                                <div className="mt-3 space-y-3">
                                    <div>
                                        <label className="block text-sm font-medium text-neutral-700 dark:text-neutral-300">{t('admin.publishable_key')}</label>
                                        <input
                                            type="text"
                                            value={data.live_publishable_key}
                                            onChange={(e) => setData('live_publishable_key', e.target.value)}
                                            placeholder={t('admin.stripe_pk_live_placeholder')}
                                            className="mt-1 w-full rounded-soft border border-neutral-300 dark:border-neutral-600 bg-white dark:bg-neutral-800 text-neutral-900 dark:text-neutral-100 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-brand-500/20"
                                        />
                                    </div>
                                    <div>
                                        <label className="block text-sm font-medium text-neutral-700 dark:text-neutral-300">{t('admin.secret_key')}</label>
                                        <input
                                            type="password"
                                            value={data.live_secret_key}
                                            onChange={(e) => setData('live_secret_key', e.target.value)}
                                            placeholder={t('admin.stripe_sk_live_placeholder')}
                                            className={`mt-1 w-full rounded-soft border px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-brand-500/20 ${
                                                validationErrors.live_secret_key
                                                    ? 'border-coral-500 bg-coral-50 dark:bg-coral-900/10 dark:border-coral-600'
                                                    : 'border-neutral-300 dark:border-neutral-600 bg-white dark:bg-neutral-800'
                                            } text-neutral-900 dark:text-neutral-100`}
                                        />
                                        {validationErrors.live_secret_key && (
                                            <p className="mt-0.5 text-xs text-coral-600 dark:text-coral-400">{validationErrors.live_secret_key}</p>
                                        )}
                                    </div>
                                    <div>
                                        <label className="block text-sm font-medium text-neutral-700 dark:text-neutral-300">{t('admin.webhook_secret')}</label>
                                        <input
                                            type="password"
                                            value={data.live_webhook_secret}
                                            onChange={(e) => setData('live_webhook_secret', e.target.value)}
                                            placeholder={t('admin.webhook_secret_placeholder')}
                                            className="mt-1 w-full rounded-soft border border-neutral-300 dark:border-neutral-600 bg-white dark:bg-neutral-800 text-neutral-900 dark:text-neutral-100 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-brand-500/20"
                                        />
                                    </div>
                                </div>
                            </div>

                            <div className="rounded-soft-lg border border-brand-100 bg-brand-50 dark:bg-brand-900/20 dark:border-brand-800 px-4 py-3 text-sm text-brand-800 dark:text-brand-200">
                                <strong>{t('admin.note_label')}:</strong> {gatewayNote}
                            </div>

                            <div>
                                <h4 className="text-sm font-medium text-neutral-900 dark:text-neutral-100">{t('common.enabled')}</h4>
                                <p className="mt-0.5 text-sm text-neutral-500 dark:text-neutral-400">
                                    {t('admin.gateway_enable_desc')}
                                </p>
                                <Toggle
                                    checked={data.enabled}
                                    onChange={(v) => setData('enabled', v)}
                                    label={data.enabled ? t('common.enabled') : t('admin.disabled')}
                                    className="mt-2"
                                />
                            </div>
                        </>
                    )}
                </Modal.Body>
                {!loading && initialData && (
                    <Modal.Footer>
                        <Button type="button" variant="outline" onClick={onClose}>
                            {t('common.cancel')}
                        </Button>
                        <Button type="submit" variant="primary" disabled={processing}>
                            {t('common.save')}
                        </Button>
                    </Modal.Footer>
                )}
            </form>
        </Modal>
    );
}
