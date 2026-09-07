import { useTranslation } from 'react-i18next';
import { CopyPlus } from 'lucide-react';
import { Button, Card, Input, Toggle } from '@/Components/ui';
import TimezonePicker from '@/Components/TimezonePicker';
import Textarea from './Textarea';

/**
 * Opening hours, time zone and delivery — the block the AI agent answers
 * "are you open now" and "do you deliver to me" from.
 *
 * The table stores one row per interval, so a clinic closed over lunch is two
 * Monday rows. This form edits the first interval of each day only; the second
 * one is a later screen, not a later migration.
 */
export default function BusinessHoursCard({
    hours = [],
    timezone = '',
    deliveryZones = '',
    deliveryTime = '',
    errors = {},
    onHourChange,
    onApplyWeekdays,
    onFieldChange,
}) {
    const { t } = useTranslation();

    return (
        <Card>
            <Card.Header title={t('company.section_hours')} />
            <Card.Body className="space-y-5">
                <div>
                    <div className="mb-2 flex flex-wrap items-center justify-between gap-2">
                        <div>
                            <p className="text-sm font-medium text-neutral-700 dark:text-neutral-300">{t('company.hours_label')}</p>
                            <p className="mt-0.5 text-xs text-ink-muted dark:text-neutral-400">{t('company.hours_hint')}</p>
                        </div>
                        <Button variant="ghost" size="sm" onClick={onApplyWeekdays}>
                            <CopyPlus className="mr-1.5 h-4 w-4" />
                            {t('company.apply_weekdays')}
                        </Button>
                    </div>

                    <div className="divide-y divide-warm-border dark:divide-white/5">
                        {hours.map((row, index) => (
                            <div key={row.day_of_week} className="flex flex-wrap items-center gap-3 py-2.5">
                                <span className="w-24 shrink-0 text-sm font-medium text-neutral-700 dark:text-neutral-300">
                                    {t(`company.day_${row.day_of_week}`)}
                                </span>

                                <Toggle checked={!row.is_closed} onChange={(open) => onHourChange(index, { is_closed: !open })} />

                                <span className="w-16 shrink-0 text-xs text-ink-muted dark:text-neutral-400">
                                    {row.is_closed ? t('company.closed') : t('company.open')}
                                </span>

                                <div className="w-28">
                                    <Input
                                        type="time"
                                        name={`hours_${row.day_of_week}_opens_at`}
                                        aria-label={t('company.opens_at')}
                                        value={row.opens_at ?? ''}
                                        disabled={row.is_closed}
                                        className="disabled:opacity-50"
                                        onChange={(e) => onHourChange(index, { opens_at: e.target.value })}
                                    />
                                </div>
                                <span className="text-sm text-ink-muted">–</span>
                                <div className="w-28">
                                    <Input
                                        type="time"
                                        name={`hours_${row.day_of_week}_closes_at`}
                                        aria-label={t('company.closes_at')}
                                        value={row.closes_at ?? ''}
                                        disabled={row.is_closed}
                                        className="disabled:opacity-50"
                                        onChange={(e) => onHourChange(index, { closes_at: e.target.value })}
                                        error={errors[`hours.${index}.closes_at`]}
                                    />
                                </div>
                            </div>
                        ))}
                    </div>
                </div>

                <div>
                    <label className="mb-1 block text-sm font-medium text-neutral-700 dark:text-neutral-300">
                        {t('company.timezone')}
                    </label>
                    <TimezonePicker value={timezone} onChange={(tz) => onFieldChange('timezone', tz)} />
                    {errors.timezone && <p className="mt-1 text-xs text-coral-600">{errors.timezone}</p>}
                </div>

                <Textarea
                    label={t('company.delivery_zones')}
                    name="delivery_zones"
                    rows={3}
                    value={deliveryZones}
                    placeholder={t('company.delivery_zones_placeholder')}
                    onChange={(e) => onFieldChange('delivery_zones', e.target.value)}
                    hint={t('company.delivery_zones_hint')}
                    error={errors.delivery_zones}
                />

                <Input
                    label={t('company.delivery_time')}
                    name="delivery_time"
                    value={deliveryTime}
                    placeholder={t('company.delivery_time_placeholder')}
                    onChange={(e) => onFieldChange('delivery_time', e.target.value)}
                    error={errors.delivery_time}
                />
            </Card.Body>
        </Card>
    );
}
