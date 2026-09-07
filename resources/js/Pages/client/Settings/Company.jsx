import { Head, useForm, usePage } from '@inertiajs/react';
import { useTranslation } from 'react-i18next';
import ClientLayout from '@/Layouts/ClientLayout';
import SettingsBackLink from '@/Components/SettingsBackLink';
import { Button, Card, Input, Select } from '@/Components/ui';
import BusinessHoursCard from './Partials/BusinessHoursCard';
import CompanyLogoCard from './Partials/CompanyLogoCard';
import CompanyOnlineCard from './Partials/CompanyOnlineCard';
import Textarea from './Partials/Textarea';

const DAYS = [1, 2, 3, 4, 5, 6, 7];
const WEEKDAY_ROWS = [1, 2, 3, 4]; // rows 2-5, copied from Monday

/** Sizes are stored verbatim ('2-10'), so the label lookup needs a safe key. */
const SIZE_KEYS = {
    '1': 'size_1',
    '2-10': 'size_2_10',
    '11-30': 'size_11_30',
    '31-50': 'size_31_50',
    '50+': 'size_50_plus',
};

/**
 * Where a Romanian firm can plausibly be registered. Deliberately short: the
 * customer is an SRL in Cluj, not a multinational, and a 200-entry list would
 * bury the one answer almost everybody needs.
 */
const COUNTRIES = ['RO', 'MD', 'HU', 'BG', 'IT', 'ES', 'DE', 'AT', 'FR', 'GB'];

/**
 * The company profile. Administrators only — the controller aborts for everyone
 * else, the same guard TeamController uses.
 *
 * This is not just "our details": the fiscal block ends up on invoices, and the
 * description, hours, delivery zones and public links are what the AI agent
 * answers customers with. Hence the hints on those fields — someone filling the
 * form should know why a sentence about the business is worth writing.
 */
export default function SettingsCompany({ client = {}, profile = {}, hours = {}, options = {} }) {
    const { t } = useTranslation();
    const { flash = {} } = usePage().props;

    const form = useForm({
        client_name: client?.name ?? '',
        client_email: client?.email ?? '',
        client_phone: client?.phone ?? '',

        legal_name: profile?.legal_name ?? '',
        industry: profile?.industry ?? '',
        industry_other: profile?.industry_other ?? '',
        company_size: profile?.company_size ?? '',
        short_description: profile?.short_description ?? '',

        cui: profile?.cui ?? '',
        vat_status: profile?.vat_status ?? '',
        vat_rate: profile?.vat_rate ?? '',
        trade_register_no: profile?.trade_register_no ?? '',
        share_capital: profile?.share_capital ?? '',
        iban: profile?.iban ?? '',
        bank_name: profile?.bank_name ?? '',

        mobile_phone: profile?.mobile_phone ?? '',
        website: profile?.website ?? '',
        contact_person_name: profile?.contact_person_name ?? '',
        contact_person_role: profile?.contact_person_role ?? '',

        address_street: profile?.address_street ?? '',
        address_city: profile?.address_city ?? '',
        address_county: profile?.address_county ?? '',
        address_postcode: profile?.address_postcode ?? '',
        address_country: profile?.address_country ?? 'RO',

        timezone: profile?.timezone ?? 'Europe/Bucharest',
        delivery_zones: profile?.delivery_zones ?? '',
        delivery_time: profile?.delivery_time ?? '',

        facebook_url: profile?.facebook_url ?? '',
        instagram_url: profile?.instagram_url ?? '',
        google_maps_url: profile?.google_maps_url ?? '',
        online_shop_url: profile?.online_shop_url ?? '',

        // The server sends an object keyed 1..7; the form submits a plain list.
        hours: DAYS.map((day) => {
            const row = hours?.[day] ?? {};
            return {
                day_of_week: day,
                opens_at: row.opens_at ?? '',
                closes_at: row.closes_at ?? '',
                is_closed: row.is_closed ?? day >= 6,
            };
        }),
    });

    const set = (field, value) => form.setData(field, value);

    const setHour = (index, patch) => {
        form.setData('hours', form.data.hours.map((row, i) => (i === index ? { ...row, ...patch } : row)));
    };

    /** Almost every firm works the same hours Monday to Friday. */
    const applyWeekdays = () => {
        const monday = form.data.hours[0];
        form.setData('hours', form.data.hours.map((row, i) => (WEEKDAY_ROWS.includes(i)
            ? { ...row, opens_at: monday.opens_at, closes_at: monday.closes_at, is_closed: monday.is_closed }
            : row)));
    };

    /** Moving off "other" drops the free-text answer, which no longer applies. */
    const setIndustry = (value) => {
        form.setData((data) => ({
            ...data,
            industry: value,
            industry_other: value === 'other' ? data.industry_other : '',
        }));
    };

    /**
     * Switching to a VAT-registered status with no rate yet lands on the rate in
     * force today rather than on an empty box the user has to look up.
     */
    const setVatStatus = (value) => {
        form.setData((data) => ({
            ...data,
            vat_status: value,
            vat_rate: value !== '' && value !== 'none' && !data.vat_rate
                ? String(options?.default_vat_rate ?? '')
                : data.vat_rate,
        }));
    };

    const submit = (e) => {
        e.preventDefault();
        form.put(route('client.settings.company.update'), { preserveScroll: true });
    };

    const industryOptions = (options?.industries ?? []).map((slug) => ({
        value: slug,
        label: t(`company.industry_${slug}`),
    }));

    const sizeOptions = (options?.company_sizes ?? []).map((size) => ({
        value: size,
        label: SIZE_KEYS[size] ? t(`company.${SIZE_KEYS[size]}`) : size,
    }));

    const vatOptions = (options?.vat_statuses ?? []).map((status) => ({
        value: status,
        label: t(`company.vat_status_${status}`),
    }));

    const countyOptions = Object.entries(options?.counties ?? {}).map(([code, name]) => ({
        value: code,
        label: name,
    }));

    const countryOptions = COUNTRIES.map((code) => ({
        value: code,
        label: t(`company.country_${code.toLowerCase()}`),
    }));

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

                <CompanyLogoCard logoUrl={client?.logo_url ?? null} />

                <form onSubmit={submit} className="space-y-5">
                    <Card>
                        <Card.Header title={t('company.section_identity')} />
                        <Card.Body className="space-y-4">
                            <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                                <Input
                                    label={t('company.commercial_name')}
                                    name="client_name"
                                    value={form.data.client_name}
                                    onChange={(e) => set('client_name', e.target.value)}
                                    hint={t('company.commercial_name_hint')}
                                    error={form.errors.client_name}
                                />
                                <Input
                                    label={t('company.legal_name')}
                                    name="legal_name"
                                    value={form.data.legal_name}
                                    onChange={(e) => set('legal_name', e.target.value)}
                                    hint={t('company.legal_name_hint')}
                                    error={form.errors.legal_name}
                                />
                                <Select
                                    label={t('company.industry')}
                                    name="industry"
                                    value={form.data.industry}
                                    onChange={(e) => setIndustry(e.target.value)}
                                    options={industryOptions}
                                    placeholder={t('company.choose')}
                                    error={form.errors.industry}
                                />
                                <Select
                                    label={t('company.company_size')}
                                    name="company_size"
                                    value={form.data.company_size}
                                    onChange={(e) => set('company_size', e.target.value)}
                                    options={sizeOptions}
                                    placeholder={t('company.choose')}
                                    error={form.errors.company_size}
                                />
                                {form.data.industry === 'other' && (
                                    <Input
                                        label={t('company.industry_other_detail')}
                                        name="industry_other"
                                        value={form.data.industry_other}
                                        onChange={(e) => set('industry_other', e.target.value)}
                                        error={form.errors.industry_other}
                                    />
                                )}
                            </div>

                            <Textarea
                                label={t('company.short_description')}
                                name="short_description"
                                rows={3}
                                value={form.data.short_description}
                                onChange={(e) => set('short_description', e.target.value)}
                                hint={t('company.short_description_hint')}
                                error={form.errors.short_description}
                            />
                        </Card.Body>
                    </Card>

                    <Card>
                        <Card.Header title={t('company.section_fiscal')} />
                        <Card.Body className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                            <Input
                                label={t('company.cui')}
                                name="cui"
                                value={form.data.cui}
                                onChange={(e) => set('cui', e.target.value)}
                                hint={t('company.cui_hint')}
                                error={form.errors.cui}
                            />
                            <Input
                                label={t('company.trade_register_no')}
                                name="trade_register_no"
                                value={form.data.trade_register_no}
                                onChange={(e) => set('trade_register_no', e.target.value)}
                                hint={t('company.trade_register_no_hint')}
                                error={form.errors.trade_register_no}
                            />
                            <Select
                                label={t('company.vat_status')}
                                name="vat_status"
                                value={form.data.vat_status}
                                onChange={(e) => setVatStatus(e.target.value)}
                                options={vatOptions}
                                placeholder={t('company.choose')}
                                error={form.errors.vat_status}
                            />
                            {form.data.vat_status !== '' && form.data.vat_status !== 'none' && (
                                <Input
                                    type="number"
                                    step="0.01"
                                    min="0"
                                    max="100"
                                    label={t('company.vat_rate')}
                                    name="vat_rate"
                                    value={form.data.vat_rate}
                                    onChange={(e) => set('vat_rate', e.target.value)}
                                    hint={t('company.vat_rate_hint', { rate: options?.default_vat_rate ?? '' })}
                                    error={form.errors.vat_rate}
                                />
                            )}
                            <Input
                                type="number"
                                step="0.01"
                                min="0"
                                label={t('company.share_capital')}
                                name="share_capital"
                                value={form.data.share_capital}
                                onChange={(e) => set('share_capital', e.target.value)}
                                error={form.errors.share_capital}
                            />
                            <Input
                                label={t('company.iban')}
                                name="iban"
                                value={form.data.iban}
                                onChange={(e) => set('iban', e.target.value)}
                                error={form.errors.iban}
                            />
                            <Input
                                label={t('company.bank_name')}
                                name="bank_name"
                                value={form.data.bank_name}
                                onChange={(e) => set('bank_name', e.target.value)}
                                error={form.errors.bank_name}
                            />
                        </Card.Body>
                    </Card>

                    <Card>
                        <Card.Header title={t('company.section_contact')} />
                        <Card.Body className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                            <Input
                                type="email"
                                label={t('company.contact_email')}
                                name="client_email"
                                value={form.data.client_email}
                                onChange={(e) => set('client_email', e.target.value)}
                                error={form.errors.client_email}
                            />
                            <Input
                                label={t('company.phone')}
                                name="client_phone"
                                value={form.data.client_phone}
                                onChange={(e) => set('client_phone', e.target.value)}
                                error={form.errors.client_phone}
                            />
                            <Input
                                label={t('company.mobile_phone')}
                                name="mobile_phone"
                                value={form.data.mobile_phone}
                                onChange={(e) => set('mobile_phone', e.target.value)}
                                error={form.errors.mobile_phone}
                            />
                            <Input
                                type="url"
                                label={t('company.website')}
                                name="website"
                                placeholder="https://"
                                value={form.data.website}
                                onChange={(e) => set('website', e.target.value)}
                                error={form.errors.website}
                            />
                            <Input
                                label={t('company.contact_person_name')}
                                name="contact_person_name"
                                value={form.data.contact_person_name}
                                onChange={(e) => set('contact_person_name', e.target.value)}
                                error={form.errors.contact_person_name}
                            />
                            <Input
                                label={t('company.contact_person_role')}
                                name="contact_person_role"
                                value={form.data.contact_person_role}
                                onChange={(e) => set('contact_person_role', e.target.value)}
                                error={form.errors.contact_person_role}
                            />
                        </Card.Body>
                    </Card>

                    <Card>
                        <Card.Header title={t('company.section_address')} />
                        <Card.Body className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                            <div className="sm:col-span-2">
                                <Input
                                    label={t('company.address_street')}
                                    name="address_street"
                                    value={form.data.address_street}
                                    onChange={(e) => set('address_street', e.target.value)}
                                    error={form.errors.address_street}
                                />
                            </div>
                            <Input
                                label={t('company.address_city')}
                                name="address_city"
                                value={form.data.address_city}
                                onChange={(e) => set('address_city', e.target.value)}
                                error={form.errors.address_city}
                            />
                            <Select
                                label={t('company.address_county')}
                                name="address_county"
                                value={form.data.address_county}
                                onChange={(e) => set('address_county', e.target.value)}
                                options={countyOptions}
                                placeholder={t('company.choose')}
                                error={form.errors.address_county}
                            />
                            <Input
                                label={t('company.address_postcode')}
                                name="address_postcode"
                                value={form.data.address_postcode}
                                onChange={(e) => set('address_postcode', e.target.value)}
                                error={form.errors.address_postcode}
                            />
                            <Select
                                label={t('company.address_country')}
                                name="address_country"
                                value={form.data.address_country}
                                onChange={(e) => set('address_country', e.target.value)}
                                options={countryOptions}
                                placeholder=""
                                error={form.errors.address_country}
                            />
                        </Card.Body>
                    </Card>

                    <BusinessHoursCard
                        hours={form.data.hours}
                        timezone={form.data.timezone}
                        deliveryZones={form.data.delivery_zones}
                        deliveryTime={form.data.delivery_time}
                        errors={form.errors}
                        onHourChange={setHour}
                        onApplyWeekdays={applyWeekdays}
                        onFieldChange={set}
                    />

                    <CompanyOnlineCard data={form.data} errors={form.errors} onFieldChange={set} />

                    <div className="pb-2">
                        <Button type="submit" variant="primary" disabled={form.processing}>
                            {t('client.save_settings')}
                        </Button>
                    </div>
                </form>
            </div>
        </ClientLayout>
    );
}
