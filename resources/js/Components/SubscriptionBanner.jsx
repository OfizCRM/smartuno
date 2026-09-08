import { Link, usePage } from '@inertiajs/react';
import { useTranslation } from 'react-i18next';
import { AlertTriangle, Lock } from 'lucide-react';
import { browserTz, formatInTz } from '@/Utils/datetime';

/**
 * Where the CTA goes: the plan-selection page, which posts to the allowlisted
 * client.checkout.store. Not client.billing.index — that is the payment-history
 * table, and a lapsed trial has no payments, so the one button on the bar would
 * land the customer on an empty archive.
 *
 * Returns null when the route is unknown, so a build without the pricing page
 * cannot crash the whole app for the one customer who most needs to reach it.
 */
function payHref() {
    try {
        return route('client.pricing');
    } catch {
        return null;
    }
}

/**
 * Account-level bar for the grace period and the read-only state that follows it.
 *
 * Deliberately not dismissible in either state: the layout remounts on every
 * Inertia navigation, so a dismiss flag would come straight back on the next
 * click and only read as a bug.
 */
export default function SubscriptionBanner() {
    const { t } = useTranslation();
    const { subscription } = usePage().props;

    const state = subscription?.state;
    if (state !== 'grace' && state !== 'readonly') return null;

    const href = payHref();
    // A firm still on its free trial has no subscription to "reactivate", and
    // telling it one has ended makes it wonder whether it was charged.
    const trial = subscription?.reason === 'trial';

    if (state === 'grace') {
        // daysLeft() rounds up, so "one more day" can mean ten minutes. The exact
        // deadline is already in the props; showing it stops a plumber reading
        // "one more day" at 23:50 and planning to pay in the morning.
        const deadline = subscription?.grace_ends_at
            ? formatInTz(subscription.grace_ends_at, browserTz(), { timeZoneName: undefined })
            : '';

        return (
            <div
                role="status"
                className="flex shrink-0 flex-wrap items-center justify-between gap-x-4 gap-y-2 border-b border-amber-200 bg-amber-50 px-4 py-2 text-sm dark:border-amber-800 dark:bg-amber-900/20"
            >
                <div className="flex items-start gap-2 text-amber-800 dark:text-amber-300">
                    <AlertTriangle className="mt-0.5 h-4 w-4 flex-shrink-0" />
                    <span>
                        <span className="font-medium">
                            {t(trial ? 'subscription.trial_grace_message' : 'subscription.grace_message', {
                                count: Number(subscription?.days_left ?? 0),
                            })}
                        </span>{' '}
                        {deadline && (
                            <span className="font-medium">{t('subscription.grace_deadline', { date: deadline })}</span>
                        )}{' '}
                        <span className="text-amber-700 dark:text-amber-400">{t('subscription.grace_note')}</span>
                    </span>
                </div>
                {href && (
                    <Link
                        href={href}
                        className="flex-shrink-0 rounded-soft bg-amber-500 px-3 py-1.5 font-medium text-white transition hover:bg-amber-600"
                    >
                        {t(trial ? 'subscription.trial_cta' : 'subscription.grace_cta')}
                    </Link>
                )}
            </div>
        );
    }

    return (
        <div
            role="alert"
            className="flex shrink-0 flex-wrap items-center justify-between gap-x-4 gap-y-2 bg-coral-600 px-4 py-2 text-sm text-white dark:bg-coral-700"
        >
            <div className="flex items-start gap-2">
                <Lock className="mt-0.5 h-4 w-4 flex-shrink-0" />
                <span>
                    <span className="font-medium">
                        {t(trial ? 'subscription.trial_readonly_message' : 'subscription.readonly_message')}
                    </span>{' '}
                    <span className="text-white/85">{t('subscription.readonly_note')}</span>
                </span>
            </div>
            {href && (
                <Link
                    href={href}
                    className="flex-shrink-0 rounded-soft bg-white/20 px-3 py-1.5 font-medium transition hover:bg-white/30"
                >
                    {t(trial ? 'subscription.trial_cta' : 'subscription.readonly_cta')}
                </Link>
            )}
        </div>
    );
}
