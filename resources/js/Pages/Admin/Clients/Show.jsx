import AdminLayout from '@/Layouts/AdminLayout';
import EmptyState from '@/Components/EmptyState';
import { Badge, Button, Card, Input, Select } from '@/Components/ui';
import { Head, Link, useForm, usePage } from '@inertiajs/react';
import { useMemo } from 'react';
import { useTranslation } from 'react-i18next';
import {
    ArrowLeft,
    Building2,
    CalendarDays,
    Clock,
    Coins,
    CreditCard,
    Landmark,
    Lock,
    MapPin,
    Phone,
    Users,
} from 'lucide-react';

const STATUS_ACTIVE = 'active';
const DAYS = [1, 2, 3, 4, 5, 6, 7];

/**
 * The tenant's online links are their own free text. React would block a
 * `javascript:` href anyway, but rendering anything that is not plainly http(s)
 * as text keeps the panel honest about what is stored.
 */
function isHttpUrl(value) {
    return typeof value === 'string' && /^https?:\/\//i.test(value);
}

function Stat({ icon: Icon, label, value }) {
    return (
        <div className="flex items-start gap-3">
            <Icon className="mt-0.5 h-4 w-4 shrink-0 text-brand-500" />
            <div className="min-w-0">
                <p className="text-xs font-medium uppercase tracking-wide text-neutral-500 dark:text-neutral-400">{label}</p>
                <p className="truncate text-sm font-semibold text-neutral-900 dark:text-neutral-100">{value}</p>
            </div>
        </div>
    );
}

function ReadField({ label, value, children }) {
    return (
        <div className="min-w-0">
            <dt className="text-xs font-medium uppercase tracking-wide text-neutral-500 dark:text-neutral-400">{label}</dt>
            <dd className="mt-0.5 break-words text-sm text-neutral-900 dark:text-neutral-100">
                {children ?? (value || '—')}
            </dd>
        </div>
    );
}

function ExternalValue({ href }) {
    if (!isHttpUrl(href)) {
        return <>{href || '—'}</>;
    }

    return (
        <a
            href={href}
            target="_blank"
            rel="noopener noreferrer"
            className="break-all text-brand-600 hover:underline dark:text-brand-400"
        >
            {href}
        </a>
    );
}

export default function AdminClientShow({ client, profile = null, businessHours = [], plan = null, userCount = 0, options = {} }) {
    const { t } = useTranslation();
    const page = usePage();
    const flash = page.props.flash || {};
    const permissions = page.props.auth?.permissions || [];
    const canUpdate = permissions.includes('update_clients');

    const standardVatRate = options.standard_vat_rate ?? null;

    const p = profile ?? {};
    const { data, setData, put, processing, errors } = useForm({
        legal_name: p.legal_name ?? '',
        cui: p.cui ?? '',
        trade_register_no: p.trade_register_no ?? '',
        vat_status: p.vat_status ?? '',
        vat_rate: p.vat_rate ?? '',
        iban: p.iban ?? '',
        bank_name: p.bank_name ?? '',
        share_capital: p.share_capital ?? '',
        contact_person_name: p.contact_person_name ?? '',
        contact_person_role: p.contact_person_role ?? '',
        mobile_phone: p.mobile_phone ?? '',
        website: p.website ?? '',
        address_street: p.address_street ?? '',
        address_city: p.address_city ?? '',
        address_county: p.address_county ?? '',
        address_postcode: p.address_postcode ?? '',
        address_country: p.address_country ?? 'RO',
    });

    const submit = (e) => {
        e.preventDefault();
        put(route('admin.clients.profile.update', { client: client.id }), { preserveScroll: true });
    };

    // The platform's own record of the tenant — name, status, currency. A second form
    // rather than a second section of the first: it posts to admin.clients.update, which
    // is a different endpoint with different rules, and it used to be the list page's
    // edit modal. `address` is deliberately absent — clients.address is derived from the
    // structured address below, so offering a free-text box here would let the two
    // writers fight over the same column.
    const account = useForm({
        name: client.name ?? '',
        email: client.email ?? '',
        phone: client.phone ?? '',
        status: client.status ?? STATUS_ACTIVE,
        base_currency: client.base_currency ?? '',
        currency_symbol: client.currency_symbol ?? '',
        currency_position: client.currency_position ?? '',
    });

    const submitAccount = (e) => {
        e.preventDefault();
        account.put(route('admin.clients.update', { client: client.id }), { preserveScroll: true });
    };

    const countyOptions = useMemo(
        () => Object.entries(options.counties ?? {}).map(([code, name]) => ({ value: code, label: name })),
        [options.counties],
    );

    const vatStatusOptions = useMemo(
        () => (options.vat_statuses ?? []).map((status) => ({ value: status, label: t(`admin_client.vat_status_${status}`) })),
        [options.vat_statuses, t],
    );

    // One row per interval, so a day closed for lunch arrives as two rows.
    const hoursByDay = useMemo(() => {
        const grouped = new Map();
        businessHours.forEach((hour) => {
            const rows = grouped.get(hour.day_of_week) ?? [];
            rows.push(hour);
            grouped.set(hour.day_of_week, rows);
        });

        return grouped;
    }, [businessHours]);

    const describeDay = (rows) => {
        if (!rows || rows.length === 0) {
            return '—';
        }
        if (rows.every((row) => row.is_closed)) {
            return t('admin_client.closed');
        }

        return rows
            .filter((row) => !row.is_closed && row.opens_at && row.closes_at)
            .map((row) => `${row.opens_at} – ${row.closes_at}`)
            .join(', ') || '—';
    };

    const industryLabel = p.industry
        ? [t(`admin_client.industry_${p.industry}`, { defaultValue: p.industry }), p.industry_other].filter(Boolean).join(' — ')
        : null;

    const currencyLabel = client.base_currency
        ? `${client.base_currency}${client.currency_symbol ? ` (${client.currency_symbol})` : ''}`
        : t('admin_client.currency_default');

    return (
        <AdminLayout title={client.name}>
            <Head title={`${client.name} · ${t('head.admin')}`} />
            <div className="max-w-5xl space-y-6">
                {flash.success && (
                    <div className="rounded-soft-lg bg-green-50 px-4 py-2 text-sm text-green-800 dark:bg-green-900/30 dark:text-green-200">
                        {flash.success}
                    </div>
                )}
                {flash.error && (
                    <div className="rounded-soft-lg bg-red-50 px-4 py-2 text-sm text-red-800 dark:bg-red-900/30 dark:text-red-200">
                        {flash.error}
                    </div>
                )}

                {/* Header */}
                <div className="flex flex-wrap items-start justify-between gap-3">
                    <div className="flex min-w-0 items-start gap-3">
                        <Link
                            href={route('admin.clients.index')}
                            className="mt-1 shrink-0 rounded-soft p-1.5 text-neutral-400 transition-colors hover:bg-neutral-100 hover:text-neutral-700 dark:hover:bg-neutral-700 dark:hover:text-neutral-200"
                            aria-label={t('admin_client.back_to_clients')}
                        >
                            <ArrowLeft className="h-4 w-4" />
                        </Link>
                        {client.logo_url && (
                            <img
                                src={client.logo_url}
                                alt={client.name}
                                className="h-10 w-10 shrink-0 rounded-soft object-contain"
                            />
                        )}
                        <div className="min-w-0">
                            <div className="flex flex-wrap items-center gap-2">
                                <h1 className="text-xl font-bold leading-tight text-neutral-900 dark:text-white">{client.name}</h1>
                                <Badge variant={client.status === STATUS_ACTIVE ? 'success' : 'default'}>
                                    {client.status === STATUS_ACTIVE ? t('common.active') : t('common.inactive')}
                                </Badge>
                            </div>
                            <p className="mt-0.5 text-sm text-neutral-500 dark:text-neutral-400">
                                {[client.email, client.phone].filter(Boolean).join(' · ') || '—'}
                            </p>
                        </div>
                    </div>
                    {/* Users and plans stay in the list page's modals — no second copy here. */}
                    <Link
                        href={route('admin.clients.index', { search: client.name })}
                        className="inline-flex items-center gap-2 rounded-lg border border-neutral-300 bg-white px-3 py-2 text-sm font-medium text-neutral-700 transition hover:bg-neutral-50 dark:border-neutral-600 dark:bg-neutral-800 dark:text-neutral-300 dark:hover:bg-neutral-700"
                    >
                        <Users className="h-4 w-4" /> {t('admin_client.manage_users_and_plan')}
                    </Link>
                </div>

                {/* Summary strip */}
                <Card>
                    <div className="grid grid-cols-2 gap-4 sm:grid-cols-4">
                        <Stat icon={CreditCard} label={t('admin_client.summary_plan')} value={plan?.name ?? t('admin.no_plan')} />
                        <Stat icon={Users} label={t('admin_client.summary_users')} value={userCount} />
                        <Stat icon={CalendarDays} label={t('admin_client.summary_created')} value={client.created_at ?? '—'} />
                        <Stat icon={Coins} label={t('admin_client.summary_currency')} value={currencyLabel} />
                    </div>
                </Card>

                {/* The platform record — what the list page's edit modal used to own. */}
                <form onSubmit={submitAccount}>
                    <Card>
                        <Card.Body className="space-y-5">
                            <div className="flex items-center gap-3 border-b border-neutral-100 pb-4 dark:border-neutral-800">
                                <Building2 className="h-5 w-5 text-brand-500" />
                                <div>
                                    <h3 className="font-semibold text-neutral-900 dark:text-neutral-100">{t('admin_client.section_account')}</h3>
                                    <p className="text-xs text-neutral-500 dark:text-neutral-400">{t('admin_client.section_account_hint')}</p>
                                </div>
                            </div>

                            <div className="grid grid-cols-1 gap-5 sm:grid-cols-2">
                                <Input
                                    label={t('admin.client_name')}
                                    value={account.data.name}
                                    onChange={(e) => account.setData('name', e.target.value)}
                                    error={account.errors.name}
                                    disabled={!canUpdate}
                                    required
                                />
                                <Input
                                    label={t('admin.col_email')}
                                    type="email"
                                    value={account.data.email}
                                    onChange={(e) => account.setData('email', e.target.value)}
                                    error={account.errors.email}
                                    disabled={!canUpdate}
                                />
                                <Input
                                    label={t('admin.phone')}
                                    value={account.data.phone}
                                    onChange={(e) => account.setData('phone', e.target.value)}
                                    error={account.errors.phone}
                                    disabled={!canUpdate}
                                />
                                <Select
                                    label={t('admin.col_status')}
                                    value={account.data.status}
                                    onChange={(e) => account.setData('status', e.target.value)}
                                    options={[
                                        { value: 'active', label: t('common.active') },
                                        { value: 'inactive', label: t('common.inactive') },
                                    ]}
                                    placeholder=""
                                    error={account.errors.status}
                                    disabled={!canUpdate}
                                />
                            </div>

                            {/* Left blank, all three inherit the platform default. */}
                            <div className="grid grid-cols-1 gap-5 sm:grid-cols-3">
                                <Input
                                    label={t('admin.base_currency')}
                                    value={account.data.base_currency}
                                    onChange={(e) => account.setData('base_currency', e.target.value)}
                                    placeholder={t('admin.currency_inherit')}
                                    error={account.errors.base_currency}
                                    disabled={!canUpdate}
                                />
                                <Input
                                    label={t('admin.currency_symbol')}
                                    value={account.data.currency_symbol}
                                    onChange={(e) => account.setData('currency_symbol', e.target.value)}
                                    placeholder={t('admin.currency_inherit')}
                                    error={account.errors.currency_symbol}
                                    disabled={!canUpdate}
                                />
                                <Select
                                    label={t('admin.currency_position')}
                                    value={account.data.currency_position}
                                    onChange={(e) => account.setData('currency_position', e.target.value)}
                                    options={[
                                        { value: 'before', label: t('admin.currency_position_before') },
                                        { value: 'after', label: t('admin.currency_position_after') },
                                    ]}
                                    placeholder={t('admin.currency_inherit')}
                                    error={account.errors.currency_position}
                                    disabled={!canUpdate}
                                />
                            </div>

                            {canUpdate && (
                                <div className="flex justify-end border-t border-neutral-100 pt-4 dark:border-neutral-800">
                                    <Button type="submit" disabled={account.processing}>{t('common.save')}</Button>
                                </div>
                            )}
                        </Card.Body>
                    </Card>
                </form>

                {/* Editable company profile */}
                <form onSubmit={submit} className="space-y-6">
                    <Card>
                        <Card.Body className="space-y-5">
                            <div className="flex items-center gap-3 border-b border-neutral-100 pb-4 dark:border-neutral-800">
                                <Landmark className="h-5 w-5 text-brand-500" />
                                <div>
                                    <h3 className="font-semibold text-neutral-900 dark:text-neutral-100">{t('admin_client.section_fiscal')}</h3>
                                    <p className="text-xs text-neutral-500 dark:text-neutral-400">{t('admin_client.section_fiscal_hint')}</p>
                                </div>
                            </div>

                            <div className="grid grid-cols-1 gap-5 sm:grid-cols-2">
                                <Input
                                    label={t('admin_client.legal_name')}
                                    value={data.legal_name}
                                    onChange={(e) => setData('legal_name', e.target.value)}
                                    error={errors.legal_name}
                                    disabled={!canUpdate}
                                />
                                <Input
                                    label={t('admin_client.cui')}
                                    value={data.cui}
                                    onChange={(e) => setData('cui', e.target.value)}
                                    placeholder={t('admin_client.cui_placeholder')}
                                    error={errors.cui}
                                    disabled={!canUpdate}
                                />
                                <Input
                                    label={t('admin_client.trade_register_no')}
                                    value={data.trade_register_no}
                                    onChange={(e) => setData('trade_register_no', e.target.value)}
                                    placeholder={t('admin_client.trade_register_placeholder')}
                                    error={errors.trade_register_no}
                                    disabled={!canUpdate}
                                />
                                <Input
                                    label={t('admin_client.share_capital')}
                                    type="number"
                                    step="0.01"
                                    min="0"
                                    value={data.share_capital}
                                    onChange={(e) => setData('share_capital', e.target.value)}
                                    error={errors.share_capital}
                                    disabled={!canUpdate}
                                />
                            </div>

                            <div className="grid grid-cols-1 gap-5 sm:grid-cols-2">
                                <Select
                                    label={t('admin_client.vat_status')}
                                    value={data.vat_status}
                                    onChange={(e) => setData('vat_status', e.target.value)}
                                    options={vatStatusOptions}
                                    placeholder={t('admin_client.not_set')}
                                    error={errors.vat_status}
                                    disabled={!canUpdate}
                                />
                                <div>
                                    <Input
                                        label={t('admin_client.vat_rate')}
                                        type="number"
                                        step="0.01"
                                        min="0"
                                        max="100"
                                        value={data.vat_rate}
                                        onChange={(e) => setData('vat_rate', e.target.value)}
                                        error={errors.vat_rate}
                                        disabled={!canUpdate}
                                    />
                                    {standardVatRate !== null && !errors.vat_rate && (
                                        <p className="mt-1.5 text-sm text-neutral-500 dark:text-neutral-400">
                                            {t('admin_client.vat_rate_hint', { rate: standardVatRate })}
                                        </p>
                                    )}
                                </div>
                            </div>

                            <div className="grid grid-cols-1 gap-5 sm:grid-cols-2">
                                <Input
                                    label={t('admin_client.iban')}
                                    value={data.iban}
                                    onChange={(e) => setData('iban', e.target.value)}
                                    placeholder={t('admin_client.iban_placeholder')}
                                    error={errors.iban}
                                    disabled={!canUpdate}
                                />
                                <Input
                                    label={t('admin_client.bank_name')}
                                    value={data.bank_name}
                                    onChange={(e) => setData('bank_name', e.target.value)}
                                    error={errors.bank_name}
                                    disabled={!canUpdate}
                                />
                            </div>
                        </Card.Body>
                    </Card>

                    <Card>
                        <Card.Body className="space-y-5">
                            <div className="flex items-center gap-3 border-b border-neutral-100 pb-4 dark:border-neutral-800">
                                <Phone className="h-5 w-5 text-brand-500" />
                                <div>
                                    <h3 className="font-semibold text-neutral-900 dark:text-neutral-100">{t('admin_client.section_contact')}</h3>
                                    <p className="text-xs text-neutral-500 dark:text-neutral-400">{t('admin_client.section_contact_hint')}</p>
                                </div>
                            </div>

                            <div className="grid grid-cols-1 gap-5 sm:grid-cols-2">
                                <Input
                                    label={t('admin_client.contact_person_name')}
                                    value={data.contact_person_name}
                                    onChange={(e) => setData('contact_person_name', e.target.value)}
                                    error={errors.contact_person_name}
                                    disabled={!canUpdate}
                                />
                                <Input
                                    label={t('admin_client.contact_person_role')}
                                    value={data.contact_person_role}
                                    onChange={(e) => setData('contact_person_role', e.target.value)}
                                    error={errors.contact_person_role}
                                    disabled={!canUpdate}
                                />
                                <Input
                                    label={t('admin_client.mobile_phone')}
                                    value={data.mobile_phone}
                                    onChange={(e) => setData('mobile_phone', e.target.value)}
                                    error={errors.mobile_phone}
                                    disabled={!canUpdate}
                                />
                                <Input
                                    label={t('admin_client.website')}
                                    type="url"
                                    value={data.website}
                                    onChange={(e) => setData('website', e.target.value)}
                                    placeholder="https://"
                                    error={errors.website}
                                    disabled={!canUpdate}
                                />
                            </div>
                        </Card.Body>
                    </Card>

                    <Card>
                        <Card.Body className="space-y-5">
                            <div className="flex items-center gap-3 border-b border-neutral-100 pb-4 dark:border-neutral-800">
                                <MapPin className="h-5 w-5 text-brand-500" />
                                <div>
                                    <h3 className="font-semibold text-neutral-900 dark:text-neutral-100">{t('admin_client.section_address')}</h3>
                                    <p className="text-xs text-neutral-500 dark:text-neutral-400">{t('admin_client.section_address_hint')}</p>
                                </div>
                            </div>

                            {client.address && (
                                <p className="text-xs text-neutral-500 dark:text-neutral-400">
                                    {t('admin_client.legacy_address')}: <span className="text-neutral-700 dark:text-neutral-300">{client.address}</span>
                                </p>
                            )}

                            <div className="grid grid-cols-1 gap-5 sm:grid-cols-2">
                                <Input
                                    label={t('admin_client.address_street')}
                                    value={data.address_street}
                                    onChange={(e) => setData('address_street', e.target.value)}
                                    error={errors.address_street}
                                    disabled={!canUpdate}
                                />
                                <Input
                                    label={t('admin_client.address_city')}
                                    value={data.address_city}
                                    onChange={(e) => setData('address_city', e.target.value)}
                                    error={errors.address_city}
                                    disabled={!canUpdate}
                                />
                                <Select
                                    label={t('admin_client.address_county')}
                                    value={data.address_county}
                                    onChange={(e) => setData('address_county', e.target.value)}
                                    options={countyOptions}
                                    placeholder={t('admin_client.select_county')}
                                    error={errors.address_county}
                                    disabled={!canUpdate}
                                />
                                <Input
                                    label={t('admin_client.address_postcode')}
                                    value={data.address_postcode}
                                    onChange={(e) => setData('address_postcode', e.target.value)}
                                    error={errors.address_postcode}
                                    disabled={!canUpdate}
                                />
                                <Input
                                    label={t('admin_client.address_country')}
                                    value={data.address_country}
                                    onChange={(e) => setData('address_country', e.target.value)}
                                    maxLength={2}
                                    error={errors.address_country}
                                    disabled={!canUpdate}
                                />
                            </div>
                        </Card.Body>
                    </Card>

                    {canUpdate && (
                        <div className="flex justify-end">
                            <Button type="submit" variant="primary" disabled={processing}>
                                {processing ? t('common.saving') : t('common.save')}
                            </Button>
                        </div>
                    )}
                </form>

                {/* Operating data the tenant owns — visible here, editable only in the client app. */}
                <Card>
                    <Card.Body className="space-y-5">
                        <div className="flex items-center gap-3 border-b border-neutral-100 pb-4 dark:border-neutral-800">
                            <Building2 className="h-5 w-5 text-brand-500" />
                            <div className="min-w-0">
                                <div className="flex flex-wrap items-center gap-2">
                                    <h3 className="font-semibold text-neutral-900 dark:text-neutral-100">{t('admin_client.section_operations')}</h3>
                                    <Badge variant="default" size="sm">
                                        <Lock className="mr-1 h-3 w-3" /> {t('admin_client.read_only_badge')}
                                    </Badge>
                                </div>
                                <p className="text-xs text-neutral-500 dark:text-neutral-400">{t('admin_client.read_only_hint')}</p>
                            </div>
                        </div>

                        {!profile ? (
                            <EmptyState
                                icon={<Building2 className="h-7 w-7" />}
                                title={t('admin_client.empty_profile_title')}
                                description={t('admin_client.empty_profile_description')}
                            />
                        ) : (
                            <div className="space-y-6">
                                <dl className="grid grid-cols-1 gap-5 sm:grid-cols-2">
                                    <ReadField label={t('admin_client.industry')} value={industryLabel} />
                                    <ReadField label={t('admin_client.company_size')} value={p.company_size} />
                                    <ReadField label={t('admin_client.timezone')} value={p.timezone} />
                                    <ReadField label={t('admin_client.delivery_time')} value={p.delivery_time} />
                                    <div className="sm:col-span-2">
                                        <ReadField label={t('admin_client.short_description')} value={p.short_description} />
                                    </div>
                                    <div className="sm:col-span-2">
                                        <ReadField label={t('admin_client.delivery_zones')} value={p.delivery_zones} />
                                    </div>
                                </dl>

                                <div>
                                    <div className="mb-2 flex items-center gap-2">
                                        <Clock className="h-4 w-4 text-neutral-400" />
                                        <p className="text-xs font-medium uppercase tracking-wide text-neutral-500 dark:text-neutral-400">
                                            {t('admin_client.business_hours')}
                                        </p>
                                    </div>
                                    {businessHours.length === 0 ? (
                                        <p className="text-sm text-neutral-500 dark:text-neutral-400">{t('admin_client.no_business_hours')}</p>
                                    ) : (
                                        <ul className="divide-y divide-neutral-100 rounded-soft border border-neutral-200 dark:divide-neutral-800 dark:border-neutral-700">
                                            {DAYS.map((day) => (
                                                <li key={day} className="flex items-center justify-between px-3 py-2 text-sm">
                                                    <span className="text-neutral-600 dark:text-neutral-300">{t(`admin_client.day_${day}`)}</span>
                                                    <span className="font-medium text-neutral-900 dark:text-neutral-100">{describeDay(hoursByDay.get(day))}</span>
                                                </li>
                                            ))}
                                        </ul>
                                    )}
                                </div>

                                <div>
                                    <p className="mb-2 text-xs font-medium uppercase tracking-wide text-neutral-500 dark:text-neutral-400">
                                        {t('admin_client.online_presence')}
                                    </p>
                                    <dl className="grid grid-cols-1 gap-5 sm:grid-cols-2">
                                        <ReadField label={t('admin_client.online_shop_url')}>
                                            <ExternalValue href={p.online_shop_url} />
                                        </ReadField>
                                        <ReadField label={t('admin_client.google_maps_url')}>
                                            <ExternalValue href={p.google_maps_url} />
                                        </ReadField>
                                        <ReadField label={t('admin_client.facebook_url')}>
                                            <ExternalValue href={p.facebook_url} />
                                        </ReadField>
                                        <ReadField label={t('admin_client.instagram_url')}>
                                            <ExternalValue href={p.instagram_url} />
                                        </ReadField>
                                    </dl>
                                </div>
                            </div>
                        )}
                    </Card.Body>
                </Card>
            </div>
        </AdminLayout>
    );
}
