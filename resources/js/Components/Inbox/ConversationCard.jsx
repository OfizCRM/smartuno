import { Link, router } from '@inertiajs/react';
import { UserCheck } from 'lucide-react';
import { useTranslation } from 'react-i18next';
import { ChannelBrandIcon } from '@/Components/BrandIcons';
import { formatTimeTz } from '@/Utils/datetime';

/**
 * One row in the conversation list.
 *
 * There were two of these — one on the inbox landing page, one on the page of an
 * open conversation — 52 lines apart, and every change to the list had to be made
 * twice or the two screens stopped matching. This is both.
 *
 * `isFlashing` came from the landing page only: a row lights up for two seconds
 * when a message lands on it over the websocket. Sharing the component means the
 * conversation page gets it too.
 */
export default function ConversationCard({ conv, isActive = false, isFlashing = false, userTz }) {
    const { t } = useTranslation();
    const channel = conv.channel_account?.channel ?? 'whatsapp';
    const name = conv.contact?.first_name || conv.contact?.last_name
        ? `${conv.contact.first_name ?? ''} ${conv.contact.last_name ?? ''}`.trim()
        : conv.contact?.phone_e164 ?? t('inbox.unknown_contact');

    // The avatar and the name jump to the contact record rather than the thread,
    // so stop the row's own navigation.
    const openContact = (e) => {
        e.preventDefault();
        e.stopPropagation();
        if (conv.contact?.uuid) {
            router.visit(route('client.contacts.show', conv.contact.uuid));
        }
    };

    return (
        <Link
            href={route('client.inbox.show', conv.uuid)}
            className={`block border-b border-neutral-100 px-3 py-3 transition-colors dark:border-neutral-800 ${
                isActive
                    ? 'border-l-2 border-l-brand-600 bg-brand-50 dark:bg-brand-900/20'
                    : isFlashing
                        ? 'bg-brand-50/60 dark:bg-brand-900/10'
                        : 'hover:bg-neutral-50 dark:hover:bg-neutral-800/50'
            }`}
        >
            <div className="flex items-start gap-2.5">
                <button type="button" onClick={openContact} title={t('inbox.view_contact')} className="group relative shrink-0">
                    <div className="flex h-9 w-9 items-center justify-center rounded-full bg-brand-100 text-sm font-semibold text-brand-700 transition group-hover:ring-2 group-hover:ring-brand-400 dark:bg-brand-900/30 dark:text-brand-300">
                        {name[0]?.toUpperCase() ?? '?'}
                    </div>
                    <span className="absolute -bottom-0.5 -right-0.5 flex h-4 w-4 items-center justify-center rounded-full bg-white dark:bg-neutral-900">
                        <ChannelBrandIcon channel={channel} className="h-3 w-3" />
                    </span>
                </button>

                <div className="min-w-0 flex-1">
                    <div className="flex items-center justify-between gap-1">
                        <button
                            type="button"
                            onClick={openContact}
                            title={t('inbox.view_contact_profile')}
                            className={`truncate text-left text-sm hover:underline ${
                                conv.unread_count > 0
                                    ? 'font-semibold text-neutral-900 dark:text-neutral-100'
                                    : 'font-medium text-neutral-700 dark:text-neutral-300'
                            }`}
                        >
                            {name}
                        </button>
                        <span className="shrink-0 text-[11px] text-neutral-400">
                            {conv.last_message_at ? formatTimeTz(conv.last_message_at, userTz) : ''}
                        </span>
                    </div>

                    <p className={`mt-0.5 truncate text-xs ${conv.unread_count > 0 ? 'text-neutral-700 dark:text-neutral-300' : 'text-neutral-400'}`}>
                        {conv.last_message?.body || t('inbox.media_placeholder')}
                    </p>

                    {(conv.labels?.length > 0 || conv.assigned_user) && (
                        <div className="mt-1 flex flex-wrap items-center gap-1">
                            {conv.labels?.map(l => (
                                <span key={l.id} className="rounded-full px-1.5 py-px text-[10px] font-medium text-white" style={{ backgroundColor: l.color }}>
                                    {l.name}
                                </span>
                            ))}
                            {/* Only a real person is named: assigned_to defaults to 'bot'
                                on every row, so badging that would say nothing. */}
                            {conv.assigned_user && (
                                <span className="inline-flex items-center gap-0.5 text-[10px] text-neutral-400">
                                    <UserCheck className="h-3 w-3" />
                                    {conv.assigned_user.name}
                                </span>
                            )}
                        </div>
                    )}
                </div>

                {conv.unread_count > 0 && (
                    <span className="mt-0.5 flex h-5 min-w-5 shrink-0 items-center justify-center rounded-full bg-brand-600 px-1 text-[10px] font-bold text-white">
                        {conv.unread_count > 99 ? '99+' : conv.unread_count}
                    </span>
                )}
            </div>
        </Link>
    );
}
