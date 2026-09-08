import { Check, Trash2 } from 'lucide-react';
import { useTranslation } from 'react-i18next';
import { describeNotification, TONE } from '@/Utils/notificationDescriptor';
import { relativeTime } from '@/Utils/relativeTime';
import { formatInTz } from '@/Utils/datetime';

/**
 * A single notification row.
 *
 * The bell dropdown and the full page render the same thing at two densities,
 * so they share this rather than each keeping its own copy — which is how the
 * two drifted into showing a raw slug and a PHP class name respectively.
 *
 * The whole row is clickable, but the click target is the title button with a
 * stretched ::after rather than a role="button" on the wrapper: the row also
 * carries its own mark-read and delete buttons, and a button may not contain
 * other focusable elements. This way the row keeps one tab stop, and the two
 * actions sit above the overlay on their own.
 */

/** Icon chip colours, one set per tone. */
const TONE_CLASSES = {
    [TONE.BRAND]: 'bg-brand-50 text-brand-600 dark:bg-brand-900/30 dark:text-brand-300',
    [TONE.ACCENT]: 'bg-accent-100 text-accent-700 dark:bg-accent-900/30 dark:text-accent-200',
    [TONE.DANGER]: 'bg-coral-50 text-coral-600 dark:bg-coral-900/30 dark:text-coral-300',
    [TONE.NEUTRAL]: 'bg-neutral-100 text-neutral-500 dark:bg-neutral-800 dark:text-neutral-400',
};

export default function NotificationItem({
    notification,
    dense = false,
    timezone = 'Europe/Bucharest',
    onOpen,
    onMarkRead,
    onDelete,
}) {
    const { t, i18n } = useTranslation();
    const locale = i18n.language || 'ro';

    const { title, body, icon: Icon, tone, url } = describeNotification(notification.data, t, locale);
    const unread = ! notification.read_at;

    // Under a week reads better as "acum 2 ore"; older than that, the date is
    // more useful than a vague "acum 9 săptămâni".
    const stamp = relativeTime(notification.created_at, locale)
        ?? formatInTz(notification.created_at, timezone);

    // Nothing to link to and already read: the row is just text.
    const activatable = Boolean(url || (unread && onMarkRead));

    const activate = () => {
        if (unread) {
            onMarkRead?.(notification.id);
        }
        if (url) {
            onOpen?.(url);
        }
    };

    return (
        <div
            className={[
                'group relative flex items-start gap-3 transition-colors',
                dense ? 'px-4 py-3' : 'rounded-xl border px-4 py-3.5',
                dense
                    ? (unread ? 'bg-brand-50/50 dark:bg-brand-900/10' : '')
                    : (unread
                        ? 'border-brand-100 bg-brand-50/60 dark:border-brand-900/40 dark:bg-brand-900/15'
                        : 'border-neutral-200 bg-white dark:border-neutral-800 dark:bg-neutral-900/40'),
                activatable ? 'hover:bg-neutral-50 dark:hover:bg-neutral-800/60' : '',
            ].join(' ')}
        >
            <span
                className={`mt-0.5 flex shrink-0 items-center justify-center rounded-xl ${TONE_CLASSES[tone] ?? TONE_CLASSES[TONE.NEUTRAL]} ${dense ? 'h-8 w-8' : 'h-9 w-9'}`}
                aria-hidden="true"
            >
                <Icon className={dense ? 'h-4 w-4' : 'h-[18px] w-[18px]'} />
            </span>

            <div className="min-w-0 flex-1">
                <div className="flex items-start gap-2">
                    <p className="min-w-0 flex-1 text-sm font-medium text-neutral-900 dark:text-neutral-100">
                        {activatable ? (
                            <button
                                type="button"
                                onClick={activate}
                                className="text-left after:absolute after:inset-0 after:rounded-xl after:content-[''] focus:outline-none focus-visible:after:ring-2 focus-visible:after:ring-brand-500"
                            >
                                {title}
                            </button>
                        ) : title}
                    </p>
                    {unread && (
                        <span className="mt-1.5 h-1.5 w-1.5 shrink-0 rounded-full bg-brand-500" aria-hidden="true" />
                    )}
                </div>

                {body && (
                    <p className={`mt-0.5 text-sm text-ink-muted dark:text-neutral-400 ${dense ? 'line-clamp-2' : ''}`}>
                        {body}
                    </p>
                )}

                <p className="mt-1 text-xs text-neutral-400 dark:text-neutral-500">{stamp}</p>
            </div>

            {! dense && (onMarkRead || onDelete) && (
                <div className="relative z-10 flex shrink-0 items-center gap-0.5 opacity-0 transition-opacity focus-within:opacity-100 group-hover:opacity-100">
                    {unread && onMarkRead && (
                        <button
                            type="button"
                            onClick={() => onMarkRead(notification.id)}
                            title={t('notif.mark_read')}
                            aria-label={t('notif.mark_read')}
                            className="rounded-soft p-1.5 text-neutral-400 transition-colors hover:bg-neutral-100 hover:text-brand-600 dark:hover:bg-neutral-800 dark:hover:text-brand-400"
                        >
                            <Check className="h-4 w-4" />
                        </button>
                    )}
                    {onDelete && (
                        <button
                            type="button"
                            onClick={() => onDelete(notification.id)}
                            title={t('notif.delete')}
                            aria-label={t('notif.delete')}
                            className="rounded-soft p-1.5 text-neutral-400 transition-colors hover:bg-neutral-100 hover:text-coral-600 dark:hover:bg-neutral-800"
                        >
                            <Trash2 className="h-4 w-4" />
                        </button>
                    )}
                </div>
            )}
        </div>
    );
}
