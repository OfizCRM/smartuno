import { Link } from '@inertiajs/react';
import { CheckCircle, Clock, Inbox, MessageSquare, Plus, User } from 'lucide-react';
import { useTranslation } from 'react-i18next';
import { ChannelBrandIcon, CHANNEL_LABELS } from '@/Components/BrandIcons';

/**
 * The inbox filter rail: views, channels, numbers, labels.
 *
 * Shared by both inbox screens. They each carried a copy, and the copies had
 * already drifted — only one of them had the numbers.
 */

export const FOLDERS = [
    { key: null, countKey: 'all', labelKey: 'inbox.folder_all', icon: Inbox },
    { key: 'mine', countKey: 'mine', labelKey: 'inbox.folder_mine', icon: User },
    { key: 'unassigned', countKey: 'unassigned', labelKey: 'inbox.folder_unassigned', icon: MessageSquare },
    { key: 'resolved', countKey: 'resolved', labelKey: 'inbox.folder_resolved', icon: CheckCircle },
    { key: 'snoozed', countKey: 'snoozed', labelKey: 'inbox.folder_snoozed', icon: Clock },
];

/** Chips over the list. 'pending' is a real status the thread's dropdown sets. */
export const LIST_CHIPS = [
    { key: null, countKey: 'all', labelKey: 'inbox.chip_open' },
    { key: 'unread', countKey: 'unread', labelKey: 'inbox.chip_unread' },
    { key: 'pending', countKey: 'pending', labelKey: 'inbox.chip_pending' },
];

export const ALL_CHANNELS = ['whatsapp', 'instagram', 'messenger', 'sms', 'email'];

/**
 * A number beside a filter. Zero renders as nothing rather than as "0": the
 * absence reads the same and a column of noughts is noise.
 */
export function FilterCount({ value }) {
    if (! value) {
        return null;
    }

    return <span className="ml-auto shrink-0 text-xs font-medium tabular-nums text-neutral-400">{value}</span>;
}

/** One heading + rows block in the rail. */
function FilterSection({ title, children }) {
    return (
        <div className="px-2 py-3">
            <p className="px-2 pb-1.5 text-[10px] font-bold uppercase tracking-wider text-neutral-400">{title}</p>
            <div className="space-y-0.5">{children}</div>
        </div>
    );
}

const rowClass = (active) => `flex w-full items-center gap-2.5 rounded-lg px-2 py-2 text-sm transition ${
    active
        ? 'bg-brand-50 font-semibold text-brand-700 dark:bg-brand-900/30 dark:text-brand-300'
        : 'text-neutral-600 hover:bg-neutral-100 dark:text-neutral-400 dark:hover:bg-neutral-800'
}`;

export default function FilterSidebar({
    filters,
    labels = [],
    channelAccounts = [],
    counts = {},
    onFolder,
    onChannel,
    onAccount,
    onLabel,
}) {
    const { t } = useTranslation();

    return (
        <div className="flex h-full flex-col divide-y divide-neutral-100 overflow-y-auto dark:divide-neutral-800">
            <FilterSection title={t('inbox.views')}>
                {FOLDERS.map(({ key, countKey, labelKey, icon: Icon }) => (
                    <button key={key ?? 'all'} type="button" onClick={() => onFolder(key)}
                        className={rowClass((filters.folder ?? null) === key)}>
                        <Icon className="h-4 w-4 shrink-0" />
                        <span className="truncate">{t(labelKey)}</span>
                        <FilterCount value={counts.views?.[countKey]} />
                    </button>
                ))}
            </FilterSection>

            <FilterSection title={t('inbox.channels')}>
                {ALL_CHANNELS.map(ch => (
                    <button key={ch} type="button" onClick={() => onChannel(ch)}
                        className={rowClass(filters.channel === ch)}>
                        <ChannelBrandIcon channel={ch} className="h-4 w-4 shrink-0" />
                        <span className="truncate">{CHANNEL_LABELS[ch] ?? ch}</span>
                        <FilterCount value={counts.channels?.[ch]} />
                    </button>
                ))}
            </FilterSection>

            {channelAccounts.length > 0 && (
                <FilterSection title={t('inbox.numbers')}>
                    {channelAccounts.map(account => (
                        <button key={account.id} type="button" onClick={() => onAccount(account.id)}
                            className={rowClass(String(filters.account_id) === String(account.id))}>
                            <ChannelBrandIcon channel={account.channel} className="h-4 w-4 shrink-0" />
                            <span className="truncate">{account.display_name || account.phone_number_id || account.channel}</span>
                        </button>
                    ))}
                </FilterSection>
            )}

            <FilterSection title={t('inbox.labels')}>
                {labels.map(label => (
                    <button key={label.id} type="button" onClick={() => onLabel(label.id)}
                        className={rowClass(String(filters.label) === String(label.id))}>
                        <span className="h-2.5 w-2.5 shrink-0 rounded-full" style={{ backgroundColor: label.color }} />
                        <span className="truncate">{label.name}</span>
                        <FilterCount value={counts.labels?.[label.id]} />
                    </button>
                ))}
                {/* Labels are managed on their own page; the rail links to it rather
                    than growing an inline editor. */}
                <Link
                    href={route('client.inbox.labels.index')}
                    className="flex w-full items-center gap-2.5 rounded-lg px-2 py-2 text-sm text-neutral-400 transition hover:bg-neutral-100 hover:text-neutral-600 dark:hover:bg-neutral-800"
                >
                    <Plus className="h-3.5 w-3.5 shrink-0" />
                    <span className="truncate">{t('inbox.new_label')}</span>
                </Link>
            </FilterSection>
        </div>
    );
}
