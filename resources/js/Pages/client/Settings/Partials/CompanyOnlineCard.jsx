import { useTranslation } from 'react-i18next';
import { Card, Input } from '@/Components/ui';

const LINKS = ['facebook_url', 'instagram_url', 'google_maps_url', 'online_shop_url'];

/**
 * The public links. Four fields shaped identically, so they are generated from
 * one list rather than written out four times — and the AI agent sends exactly
 * these when a customer asks where to find the firm.
 */
export default function CompanyOnlineCard({ data = {}, errors = {}, onFieldChange }) {
    const { t } = useTranslation();

    return (
        <Card>
            <Card.Header title={t('company.section_online')} />
            <Card.Body className="space-y-4">
                <p className="text-sm text-ink-muted dark:text-neutral-400">{t('company.online_hint')}</p>
                <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    {LINKS.map((field) => (
                        <Input
                            key={field}
                            type="url"
                            label={t(`company.${field}`)}
                            name={field}
                            placeholder="https://"
                            value={data[field] ?? ''}
                            onChange={(e) => onFieldChange(field, e.target.value)}
                            error={errors[field]}
                        />
                    ))}
                </div>
            </Card.Body>
        </Card>
    );
}
