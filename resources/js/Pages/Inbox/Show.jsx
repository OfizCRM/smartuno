import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import InboxLayout from '@/Layouts/InboxLayout';
import EmptyState from '@/Components/EmptyState';
import NewConversationModal from '@/Components/Inbox/NewConversationModal';
import ConversationListPanel from '@/Components/Inbox/ConversationListPanel';
import FilterSidebar from '@/Components/Inbox/FilterSidebar';
import {
    Send, AlertTriangle, Eye, StickyNote, MessageSquare, Phone, Globe,
    Search, User, CheckCircle, X, Smile,
    Paperclip, Image as ImageIcon, ChevronDown, UserCheck,
    LayoutTemplate, Plus, Loader2, Bot, Calendar, BarChart2, PhoneMissed,
    Volume2, VolumeX, ShoppingBag, History, Tag, ArrowRightLeft, UserMinus,
    Zap, Radio, IdCard, Check, Trash2, Download,
} from 'lucide-react';
import { ChannelBrandIcon, CHANNEL_LABELS } from '@/Components/BrandIcons';
import { formatTimeTz, formatInTz, formatDateTz, msUntilMidnightTz } from '@/Utils/datetime';
import { activityText } from '@/Utils/conversationActivity';
import { Button, Modal } from '@/Components/ui';
import MarkdownLite from '@/Components/MarkdownLite';
import AttachmentPreview, { previewKindFor } from '@/Components/Inbox/AttachmentPreview';
import AttachmentIcon from '@/Components/Inbox/AttachmentIcon';
import ComposerFormatting, { applyFormat, formatShortcut } from '@/Components/Inbox/ComposerFormatting';
import { playInboundSound, getSoundPrefs, setChannelSoundEnabled, SOUND_CHANNELS } from '@/Utils/notificationSound';
import { useState, useEffect, useRef, useCallback } from 'react';
import { useTranslation } from 'react-i18next';
import axios from 'axios';
import { toast } from 'sonner';

/**
 * Surface what the server actually said. These side-panel actions used to end in
 * `.catch(() => {})`, so under the subscription gate (402) or a demo block (403)
 * the label simply never appeared and the note stayed in the box with no
 * explanation at all — a button that does nothing, clicked five times.
 * <Toaster /> is already mounted by InboxLayout.
 */
function reportActionError(err, fallback) {
    toast.error(err?.response?.data?.message || fallback);
}

/* ─── helpers ─────────────────────────────────────────── */

/**
 * How tall the reply box may grow before it starts scrolling instead.
 *
 * About fourteen lines. Past that the composer would be eating the thread it is
 * a reply to, which is the other way of making a message hard to write.
 */
const COMPOSER_MAX_HEIGHT = 320;

/**
 * Who sent an outbound message, from messages.sent_by. 'human' gets no badge —
 * a colleague typing is the default and labelling it would be noise. The other
 * three are the platform acting on the tenant's behalf, which is exactly what a
 * person scanning the thread needs to be able to tell apart.
 */
const SENDER_BADGES = {
    bot: { icon: Bot, labelKey: 'inbox.sent_by_bot' },
    automation: { icon: Zap, labelKey: 'inbox.sent_by_automation' },
    broadcast: { icon: Radio, labelKey: 'inbox.sent_by_broadcast' },
};

const STATUS_COLORS = {
    open:     'bg-green-100 text-green-700 dark:bg-green-900/30 dark:text-green-300',
    pending:  'bg-amber-100 text-amber-700 dark:bg-amber-900/30 dark:text-amber-300',
    resolved: 'bg-neutral-100 text-neutral-600 dark:bg-neutral-800 dark:text-neutral-400',
    snoozed:  'bg-purple-100 text-purple-700 dark:bg-purple-900/30 dark:text-purple-300',
};

// Conversation activity log — icon + accent colour per event type
const ACTIVITY_STYLES = {
    created:        { Icon: Plus,           color: 'bg-green-100 text-green-600 dark:bg-green-900/30 dark:text-green-300' },
    assigned:       { Icon: UserCheck,      color: 'bg-brand-100 text-brand-600 dark:bg-brand-900/30 dark:text-brand-300' },
    transferred:    { Icon: ArrowRightLeft, color: 'bg-blue-100 text-blue-600 dark:bg-blue-900/30 dark:text-blue-300' },
    unassigned:     { Icon: UserMinus,      color: 'bg-neutral-100 text-neutral-500 dark:bg-neutral-800 dark:text-neutral-400' },
    status_changed: { Icon: CheckCircle,    color: 'bg-amber-100 text-amber-600 dark:bg-amber-900/30 dark:text-amber-300' },
    handover:       { Icon: Bot,            color: 'bg-purple-100 text-purple-600 dark:bg-purple-900/30 dark:text-purple-300' },
    label_added:    { Icon: Tag,            color: 'bg-teal-100 text-teal-600 dark:bg-teal-900/30 dark:text-teal-300' },
    label_removed:  { Icon: Tag,            color: 'bg-rose-100 text-rose-600 dark:bg-rose-900/30 dark:text-rose-300' },
};


function ActivityRow({ activity, t, userTz, isLast }) {
    const { Icon, color } = ACTIVITY_STYLES[activity.type]
        ?? { Icon: History, color: 'bg-neutral-100 text-neutral-500 dark:bg-neutral-800 dark:text-neutral-400' };
    return (
        <div className="flex gap-3">
            <div className="flex flex-col items-center">
                <span className={`flex h-7 w-7 shrink-0 items-center justify-center rounded-full ${color}`}>
                    <Icon className="h-3.5 w-3.5" />
                </span>
                {!isLast && <span className="w-px flex-1 bg-neutral-200 dark:bg-neutral-700 my-1" />}
            </div>
            <div className={`min-w-0 ${isLast ? '' : 'pb-4'}`}>
                <p className="text-sm text-neutral-700 dark:text-neutral-300">{activityText(activity, t)}</p>
                <p className="text-xs text-neutral-400 mt-0.5">{formatInTz(activity.created_at, userTz)}</p>
            </div>
        </div>
    );
}

// Common emoji set for the picker
const EMOJI_LIST = [
    '😀','😂','😊','😍','🤔','😢','😡','👍','👎','❤️',
    '🎉','✅','❌','🔥','⭐','💡','📌','🚀','💬','📞',
    '✉️','📎','🖼️','📁','🕐','💰','🎯','👋','🙏','💪',
];

function renderCannedBody(body, contact) {
    return body.replace(/\{\{contact\.(\w+)\}\}/g, (_, key) => {
        if (key === 'name') return [contact?.first_name, contact?.last_name].filter(Boolean).join(' ');
        return contact?.[key] ?? '';
    });
}

/** Render a WA template body text from its components */
function templatePreview(components) {
    const body = components?.find(c => c.type === 'BODY');
    return body?.text ?? '';
}

/** Parse WhatsApp formatting: *bold*, _italic_, ~strike~, `code`, newlines */
function WaText({ text, className = '' }) {
    if (!text) return null;
    const parts = [];
    let remaining = text;
    let key = 0;

    // Replace patterns iteratively
    const patterns = [
        { re: /\*([^*\n]+)\*/g,   Tag: 'strong', cls: 'font-semibold' },
        { re: /_([^_\n]+)_/g,     Tag: 'em',     cls: 'italic' },
        { re: /~([^~\n]+)~/g,     Tag: 'del',    cls: 'line-through' },
        { re: /`([^`\n]+)`/g,     Tag: 'code',   cls: 'font-mono text-[0.85em] bg-black/10 rounded px-0.5' },
    ];

    // Build segments with React elements
    const segments = [];
    let src = text;
    // Simple approach: split on newlines first, then process each line
    const lines = src.split('\n');
    lines.forEach((line, li) => {
        if (li > 0) segments.push(<br key={`br-${li}`} />);
        // Process inline patterns on each line
        let rest = line;
        let lineSegs = [];
        let lk = 0;
        while (rest.length > 0) {
            let earliest = null;
            let earliestIdx = Infinity;
            for (const p of patterns) {
                p.re.lastIndex = 0;
                const m = p.re.exec(rest);
                if (m && m.index < earliestIdx) {
                    earliest = { ...p, match: m };
                    earliestIdx = m.index;
                }
            }
            if (!earliest) {
                lineSegs.push(<span key={lk++}>{rest}</span>);
                break;
            }
            if (earliest.match.index > 0) {
                lineSegs.push(<span key={lk++}>{rest.slice(0, earliest.match.index)}</span>);
            }
            const { Tag, cls, match } = earliest;
            lineSegs.push(<Tag key={lk++} className={cls}>{match[1]}</Tag>);
            rest = rest.slice(earliest.match.index + match[0].length);
        }
        segments.push(...lineSegs);
    });

    return <span className={className}>{segments}</span>;
}

/* ─── message type renderers ─────────────────────────── */

function MediaImage({ src, alt, conversationId, messageId }) {
    const { t } = useTranslation();
    const [loaded, setLoaded]   = useState(false);
    const [errored, setErrored] = useState(false);
    const [open, setOpen]       = useState(false);
    const proxyUrl = src ?? route('client.inbox.message-media', { conversation: conversationId, message: messageId });
    if (errored) return <span className="text-xs opacity-60 italic">{t('inbox.image_unavailable')}</span>;
    return (
        <>
            <button
                type="button"
                onClick={() => setOpen(true)}
                className="relative block rounded-xl overflow-hidden mb-1 bg-black/10 min-h-[80px] cursor-zoom-in"
                title={t('inbox.view_full_size')}
            >
                {!loaded && <div className="absolute inset-0 flex items-center justify-center"><Loader2 className="h-5 w-5 animate-spin opacity-40" /></div>}
                <img
                    src={proxyUrl}
                    alt={alt || 'image'}
                    onLoad={() => setLoaded(true)}
                    onError={() => setErrored(true)}
                    className={`max-w-full max-h-72 object-contain rounded-xl transition-opacity ${loaded ? 'opacity-100' : 'opacity-0'}`}
                />
            </button>
            {open && (
                <ImageLightbox
                    images={[{ id: messageId, src: proxyUrl, caption: alt || '' }]}
                    index={0}
                    onClose={() => setOpen(false)}
                    onIndex={() => {}}
                />
            )}
        </>
    );
}

function MediaVideo({ src, conversationId, messageId }) {
    const proxyUrl = src ?? route('client.inbox.message-media', { conversation: conversationId, message: messageId });
    return <video src={proxyUrl} controls className="rounded-xl max-w-full max-h-64 mb-1 bg-black" />;
}

// Images sent together as an album arrive from WhatsApp as separate messages
// (no album id), so we group consecutive same-direction image messages that
// land within this window and render them as one gallery.
const ALBUM_WINDOW_MS = 15000;

/**
 * A content-less "unsupported" stub WhatsApp emits (error "Message type unknown"
 * / 131051, no text or media). We only hide these when they sit next to album
 * images — a *standalone* unsupported message (e.g. a poll) is always shown.
 */
function isAlbumNoiseStub(msg) {
    if (msg.type !== 'unsupported') return false;
    const errs = Array.isArray(msg.payload?.errors) ? msg.payload.errors : [];
    if (!errs.length) return false;
    const blob = JSON.stringify(errs).toLowerCase();
    if (blob.includes('poll') || blob.includes('event')) return false;
    const p = msg.payload || {};
    const hasMedia = !!(p.image || p.video || p.audio || p.document || p.sticker);
    return (blob.includes('unsupported') || blob.includes('unknown') || blob.includes('131051')) && !hasMedia;
}

/**
 * Collapse runs of adjacent image messages into albums.
 * Returns render items: { kind: 'album', messages } or { kind: 'single', msg }.
 */
function groupMessagesForRender(messages) {
    // Hide album-noise stubs only when an adjacent same-direction message (within
    // the album window) is an image — never standalone polls / unsupported msgs.
    const hiddenIds = new Set();
    for (let k = 0; k < messages.length; k++) {
        const m = messages[k];
        if (!isAlbumNoiseStub(m)) continue;
        const adjacentToImage = [messages[k - 1], messages[k + 1]].some(
            (n) =>
                n &&
                n.type === 'image' &&
                n.direction === m.direction &&
                Math.abs(new Date(n.sent_at) - new Date(m.sent_at)) <= ALBUM_WINDOW_MS,
        );
        if (adjacentToImage) hiddenIds.add(m.id);
    }
    if (hiddenIds.size) {
        messages = messages.filter((m) => !hiddenIds.has(m.id));
    }

    const items = [];
    let i = 0;
    while (i < messages.length) {
        const msg = messages[i];
        if (msg.type === 'image') {
            const group = [msg];
            let j = i + 1;
            while (j < messages.length) {
                const next = messages[j];
                const prev = group[group.length - 1];
                if (
                    next.type !== 'image' ||
                    next.direction !== msg.direction ||
                    Math.abs(new Date(next.sent_at) - new Date(prev.sent_at)) > ALBUM_WINDOW_MS
                ) break;
                group.push(next);
                j++;
            }
            if (group.length >= 2) {
                items.push({ kind: 'album', key: `album-${group[0].id}`, messages: group });
                i = j;
                continue;
            }
        }
        items.push({ kind: 'single', key: msg.id, msg });
        i++;
    }
    return items;
}

/** Human file size for an attachment row. */
function formatBytes(bytes) {
    const n = Number(bytes) || 0;
    if (n < 1024) return `${n} B`;
    if (n < 1024 * 1024) return `${Math.round(n / 1024)} KB`;

    return `${(n / 1024 / 1024).toFixed(1)} MB`;
}

/**
 * The calendar day an instant falls on, in the reader's timezone, as YYYY-MM-DD.
 * Built from Intl parts rather than toISOString(), which would answer in UTC and
 * put a 01:30 message under the previous day for anyone east of Greenwich.
 */
/**
 * "clientă din martie 2025", built from contacts.created_at.
 *
 * There is no customer_since column; created_at is when the row first appeared
 * in SmartUno — the first inbound message or the import that created it. That is
 * the honest reading of the phrase, and it is already in the page props.
 */
function customerSince(iso, locale) {
    const d = new Date(iso);
    if (Number.isNaN(d.getTime())) return null;

    return new Intl.DateTimeFormat(locale || 'ro', { month: 'long', year: 'numeric' }).format(d);
}

function dayKeyTz(iso, tz) {
    const d = new Date(iso);
    if (Number.isNaN(d.getTime())) return '';
    const parts = new Intl.DateTimeFormat('en-CA', {
        timeZone: tz || 'UTC', year: 'numeric', month: '2-digit', day: '2-digit',
    }).formatToParts(d);
    const get = (type) => parts.find(x => x.type === type)?.value ?? '';

    return get('year') + '-' + get('month') + '-' + get('day');
}

/**
 * Insert a day marker before the first item of each calendar day, so a thread
 * read months later still says when things happened.
 */
function withDayDividers(items, tz) {
    const out = [];
    let lastDay = null;
    for (const item of items) {
        const at = item.kind === 'album' ? item.messages[0]?.sent_at : item.msg?.sent_at;
        const day = at ? dayKeyTz(at, tz) : null;
        if (day && day !== lastDay) {
            out.push({ kind: 'day', key: 'day-' + day, day, at });
            lastDay = day;
        }
        out.push(item);
    }

    return out;
}

function DayDivider({ at, day, tz, nowMs }) {
    const { t } = useTranslation();
    // `nowMs` is read once when the page mounts rather than on every render:
    // calling Date.now() during render is impure and makes the label unstable.
    const today = dayKeyTz(new Date(nowMs).toISOString(), tz);
    const yesterday = dayKeyTz(new Date(nowMs - 86400000).toISOString(), tz);

    const label = day === today ? t('inbox.day_today')
        : day === yesterday ? t('inbox.day_yesterday')
        : formatDateTz(at, tz);

    return (
        <div className="my-3 flex items-center justify-center">
            <span className="rounded-full bg-neutral-200/70 px-3 py-1 text-[11px] font-medium text-neutral-500 dark:bg-neutral-800 dark:text-neutral-400">
                {label}
            </span>
        </div>
    );
}

function galleryImageSrc(msg, conversationId) {
    return msg.payload?.preview_url
        ?? route('client.inbox.message-media', { conversation: conversationId, message: msg.id });
}

function ImageGallery({ messages, conversationId }) {
    const { props: pageProps } = usePage();
    const bubbleTz = pageProps.timezone || 'Europe/Bucharest';
    const [lightbox, setLightbox] = useState(null);

    const isOut = messages[0].direction === 'out';
    const last = messages[messages.length - 1];

    const images = messages.map((m) => ({
        id: m.id,
        src: galleryImageSrc(m, conversationId),
        caption: m.payload?.caption ?? (m.body && m.body !== '(media)' ? m.body : ''),
    }));

    const shown = images.slice(0, 4);
    const extra = images.length - shown.length;

    const statusGlyph = last.status === 'read' || last.status === 'delivered' ? '✓✓'
        : last.status === 'sent' ? '✓'
        : last.status === 'failed' ? '✗' : '⋯';
    const statusClass = last.status === 'read' ? 'text-sky-200' : last.status === 'failed' ? 'text-red-200' : '';

    return (
        <div className={`flex ${isOut ? 'justify-end' : 'justify-start'} mb-2`}>
            <div className={`max-w-[70%] rounded-2xl overflow-hidden ${isOut
                ? 'bg-brand-600 text-white rounded-br-sm'
                : 'bg-white dark:bg-neutral-800 border border-neutral-200 dark:border-neutral-700 rounded-bl-sm'}`}>
                <div className={`grid gap-0.5 p-0.5 ${shown.length === 1 ? 'grid-cols-1' : 'grid-cols-2'}`}>
                    {shown.map((img, idx) => (
                        <button
                            key={img.id}
                            type="button"
                            onClick={() => setLightbox(idx)}
                            className="relative block aspect-square overflow-hidden bg-black/10 group"
                        >
                            <img src={img.src} alt="image" loading="lazy"
                                className="h-full w-full object-cover transition group-hover:opacity-90" />
                            {idx === 3 && extra > 0 && (
                                <span className="absolute inset-0 bg-black/55 text-white flex items-center justify-center text-xl font-semibold">
                                    +{extra}
                                </span>
                            )}
                        </button>
                    ))}
                </div>
                <div className={`flex items-center justify-end gap-1 px-3 py-1 text-[10px] text-neutral-400`}>
                    {last.sent_at ? formatTimeTz(last.sent_at, bubbleTz) : ''}
                    {isOut && <span title={last.status} className={statusClass}>{statusGlyph}</span>}
                </div>
            </div>

            {lightbox !== null && (
                <ImageLightbox
                    images={images}
                    index={lightbox}
                    onClose={() => setLightbox(null)}
                    onIndex={setLightbox}
                />
            )}
        </div>
    );
}

function ImageLightbox({ images, index, onClose, onIndex }) {
    const go = (delta) => onIndex((index + delta + images.length) % images.length);

    useEffect(() => {
        const onKey = (e) => {
            if (e.key === 'Escape') onClose();
            else if (e.key === 'ArrowLeft') onIndex((index - 1 + images.length) % images.length);
            else if (e.key === 'ArrowRight') onIndex((index + 1) % images.length);
        };
        window.addEventListener('keydown', onKey);
        return () => window.removeEventListener('keydown', onKey);
    }, [index, images.length, onClose, onIndex]);

    const current = images[index];

    return (
        <div className="fixed inset-0 z-50 bg-black/80 flex items-center justify-center" onClick={onClose}>
            <button type="button" onClick={onClose} className="absolute top-4 right-4 text-white/80 hover:text-white">
                <X className="h-6 w-6" />
            </button>
            {images.length > 1 && (
                <button type="button" onClick={(e) => { e.stopPropagation(); go(-1); }}
                    className="absolute left-3 md:left-6 text-white/70 hover:text-white text-4xl leading-none px-2">‹</button>
            )}
            <figure className="flex flex-col items-center" onClick={(e) => e.stopPropagation()}>
                <img src={current.src} alt="image" className="max-w-[90vw] max-h-[80vh] object-contain rounded" />
                {current.caption && (
                    <figcaption className="mt-3 text-sm text-white/80 max-w-[80vw] text-center">{current.caption}</figcaption>
                )}
                {images.length > 1 && <div className="mt-2 text-xs text-white/50">{index + 1} / {images.length}</div>}
            </figure>
            {images.length > 1 && (
                <button type="button" onClick={(e) => { e.stopPropagation(); go(1); }}
                    className="absolute right-3 md:right-6 text-white/70 hover:text-white text-4xl leading-none px-2">›</button>
            )}
        </div>
    );
}

function MediaAudio({ src, conversationId, messageId }) {
    const proxyUrl = src ?? route('client.inbox.message-media', { conversation: conversationId, message: messageId });
    return <audio src={proxyUrl} controls className="w-full min-w-[200px] mb-1" />;
}

function MediaDocument({ src, filename, conversationId, messageId }) {
    const { t } = useTranslation();
    const proxyUrl = src ?? route('client.inbox.message-media', { conversation: conversationId, message: messageId });
    return (
        <a href={proxyUrl} target="_blank" rel="noopener noreferrer"
            className={`flex items-center gap-2.5 rounded-xl px-3 py-2.5 mb-1 transition bg-black/[0.04] hover:bg-black/[0.07] dark:bg-white/10 dark:hover:bg-white/15`}>
            <div className={`h-9 w-9 rounded-lg flex items-center justify-center shrink-0 bg-black/10 dark:bg-white/15`}>
                <Paperclip className="h-4 w-4" />
            </div>
            <div className="min-w-0">
                <p className="text-xs font-medium truncate">{filename || t('inbox.document')}</p>
                <p className="text-[10px] opacity-60">{t('inbox.tap_to_open')}</p>
            </div>
        </a>
    );
}

function LocationCard({ location }) {
    const { t } = useTranslation();
    const { latitude, longitude, name, address } = location ?? {};
    if (!latitude || !longitude) return <span className="text-xs opacity-60 italic">{t('inbox.location_unavailable')}</span>;
    const mapUrl = `https://www.google.com/maps?q=${latitude},${longitude}`;
    const staticMap = `https://maps.googleapis.com/maps/api/staticmap?center=${latitude},${longitude}&zoom=14&size=300x150&markers=${latitude},${longitude}`;
    return (
        <a href={mapUrl} target="_blank" rel="noopener noreferrer" className="block rounded-xl overflow-hidden mb-1 border border-black/10">
            <div className="relative h-32 bg-neutral-200 dark:bg-neutral-700 flex items-center justify-center">
                <div className="text-center opacity-60">
                    <svg className="h-8 w-8 mx-auto mb-1" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path strokeLinecap="round" strokeLinejoin="round" strokeWidth={1.5} d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z" /><path strokeLinecap="round" strokeLinejoin="round" strokeWidth={1.5} d="M15 11a3 3 0 11-6 0 3 3 0 016 0z" /></svg>
                    <span className="text-xs">{t('inbox.map')}</span>
                </div>
            </div>
            <div className={`px-3 py-2 bg-black/[0.03] dark:bg-white/5`}>
                {name && <p className="text-xs font-semibold truncate">{name}</p>}
                {address && <p className="text-[10px] opacity-70 truncate">{address}</p>}
                <p className="text-[10px] opacity-50">{latitude}, {longitude}</p>
            </div>
        </a>
    );
}

function ContactCard({ contacts }) {
    const { t } = useTranslation();
    const c = contacts?.[0];
    if (!c) return <span className="text-xs opacity-60 italic">{t('inbox.contact_unavailable')}</span>;
    const name = c.name?.formatted_name ?? c.name?.first_name ?? t('inbox.contact');
    const phone = c.phones?.[0]?.phone ?? c.phones?.[0]?.wa_id ?? '';
    return (
        <div className={`flex items-center gap-2.5 rounded-xl px-3 py-2.5 mb-1 bg-black/[0.04] dark:bg-white/10`}>
            <div className={`h-9 w-9 rounded-full flex items-center justify-center text-sm font-bold shrink-0 bg-black/10 dark:bg-white/15`}>
                {name[0]?.toUpperCase() ?? '?'}
            </div>
            <div className="min-w-0">
                <p className="text-xs font-semibold truncate">{name}</p>
                {phone && <p className="text-[10px] opacity-60">{phone}</p>}
                {contacts.length > 1 && <p className="text-[10px] opacity-50">{t('inbox.plus_n_more', { count: contacts.length - 1 })}</p>}
            </div>
        </div>
    );
}

function StickerBubble({ src, conversationId, messageId }) {
    const proxyUrl = src ?? route('client.inbox.message-media', { conversation: conversationId, message: messageId });
    return <img src={proxyUrl} alt="sticker" className="h-24 w-24 object-contain" />;
}

function ReactionBubble({ reaction }) {
    const { t } = useTranslation();
    return (
        <div className="flex items-center gap-1 text-sm">
            <span className="text-xl">{reaction?.emoji}</span>
            <span className="text-[10px] opacity-50">{t('inbox.reacted')}</span>
        </div>
    );
}

function UnsupportedBubble({ payload, body }) {
    const { t } = useTranslation();
    const errors = payload?.errors ?? [];
    const errorTitle = errors[0]?.title ?? '';
    const isEvent = errorTitle.toLowerCase().includes('event') || body?.toLowerCase().includes('event');
    const isPoll  = errorTitle.toLowerCase().includes('poll') || body?.toLowerCase().includes('poll');
    const Icon  = isEvent ? Calendar : isPoll ? BarChart2 : AlertTriangle;
    const label = isEvent ? t('inbox.event_unsupported')
                : isPoll  ? t('inbox.poll_unsupported')
                : (errorTitle || body || t('inbox.unsupported_message_type'));
    return (
        <div className="flex items-center gap-2 opacity-70 italic text-xs py-0.5">
            <Icon className="h-3.5 w-3.5 shrink-0" />
            <span>{label}</span>
        </div>
    );
}

/** Resolve {{N}} placeholders in a template component text using its parameters array */
function resolveTemplateText(text, parameters = []) {
    if (!text) return text;
    return text.replace(/\{\{(\d+)\}\}/g, (_, n) => {
        const param = parameters[parseInt(n, 10) - 1];
        if (!param) return `{{${n}}}`;
        return param.text ?? param.payload ?? param.default ?? `{{${n}}}`;
    });
}

/** Render a full WA template: body text, footer, and quick-reply / call-to-action buttons */
function TemplateBodyContent({ components }) {
    if (!components?.length) return null;
    const header   = components.find(c => (c.type ?? '').toUpperCase() === 'HEADER');
    const body     = components.find(c => (c.type ?? '').toUpperCase() === 'BODY');
    const footer   = components.find(c => (c.type ?? '').toUpperCase() === 'FOOTER');
    const buttons  = components.find(c => (c.type ?? '').toUpperCase() === 'BUTTONS');

    const headerText = header?.format === 'TEXT' ? resolveTemplateText(header.text, header.parameters) : null;
    const bodyText   = body   ? resolveTemplateText(body.text, body.parameters)   : null;
    const footerText = footer ? resolveTemplateText(footer.text, footer.parameters) : null;
    const btns       = buttons?.buttons ?? [];

    return (
        <div>
            {headerText && (
                <p className="font-semibold mb-1"><WaText text={headerText} /></p>
            )}
            {bodyText && <WaText text={bodyText} />}
            {footerText && (
                <p className={`text-[11px] mt-1.5 text-neutral-400`}>{footerText}</p>
            )}
            {btns.length > 0 && (
                <div className={`mt-2 pt-2 border-t border-black/10 dark:border-white/15 flex flex-col gap-1`}>
                    {btns.map((b, i) => (
                        <div key={i} className={`text-xs text-center py-1 rounded text-brand-600 dark:text-brand-400`}>
                            {b.type === 'URL' ? '🔗 ' : b.type === 'PHONE_NUMBER' ? '📞 ' : '↩ '}
                            {b.text}
                        </div>
                    ))}
                </div>
            )}
        </div>
    );
}

function TemplateHeaderMedia({ components, conversationId, messageId }) {
    const headerComp = components?.find(c => (c.type ?? '').toUpperCase() === 'HEADER');
    if (!headerComp) return null;
    const param = headerComp.parameters?.[0];
    if (!param) return null;
    const mediaType  = param.type; // 'image' | 'video' | 'document'
    const mediaObj   = param[mediaType] ?? {};
    const displayUrl = mediaObj.preview_url ?? mediaObj.link ?? null;
    if (mediaType === 'image') return <MediaImage src={displayUrl} conversationId={conversationId} messageId={messageId} />;
    if (mediaType === 'video') return <MediaVideo src={displayUrl} conversationId={conversationId} messageId={messageId} />;
    if (mediaType === 'document') return <MediaDocument src={displayUrl} filename={mediaObj.filename} conversationId={conversationId} messageId={messageId} />;
    return null;
}

function PollBubble({ nfmReply }) {
    const { t } = useTranslation();
    const name = nfmReply?.name ?? '';
    // poll vote response
    if (name === 'vote' || name === 'poll_creation') {
        let responseJson = {};
        try { responseJson = JSON.parse(nfmReply?.response_json ?? '{}'); } catch (_) {}
        const pollName = responseJson.poll_name ?? responseJson.title ?? '';
        const selectedOptions = responseJson.selected_options ?? [];
        const options = responseJson.options ?? [];
        return (
            <div className={`rounded-xl px-3 py-2.5 mb-1 bg-black/[0.04] dark:bg-white/10`}>
                <div className="flex items-center gap-1.5 mb-2">
                    <BarChart2 className="h-3.5 w-3.5 shrink-0" />
                    <span className="text-xs font-semibold">{pollName || t('inbox.poll')}</span>
                </div>
                {options.length > 0 && options.map((opt, i) => {
                    const label = opt.name ?? opt.title ?? opt;
                    const voted = selectedOptions.some(s => (s.local_id ?? s.id) === i || s.name === label);
                    return (
                        <div key={i} className={`text-xs px-2 py-1 rounded mt-1 ${voted ? 'bg-brand-100 font-semibold text-brand-700 dark:bg-brand-900/40 dark:text-brand-300' : 'opacity-70'}`}>
                            {voted ? '✓ ' : ''}{label}
                        </div>
                    );
                })}
                {selectedOptions.length > 0 && options.length === 0 && (
                    <div className="text-xs opacity-70 mt-1">{t('inbox.voted_on_poll')}</div>
                )}
            </div>
        );
    }
    return <WaText text={`[interactive: ${name}]`} />;
}

function InteractiveBubble({ payload, isOut }) {
    const interactive = payload?.interactive ?? {};
    const body = interactive.body?.text ?? '';
    const buttons = interactive.action?.buttons ?? [];
    const buttonReply = interactive.button_reply;
    const listReply   = interactive.list_reply;
    const nfmReply    = interactive.nfm_reply;
    if (buttonReply) return <WaText text={`✓ ${buttonReply.title}`} />;
    if (listReply)   return <WaText text={`☑ ${listReply.title}`} />;
    if (nfmReply)    return <PollBubble nfmReply={nfmReply} />;
    return (
        <div>
            {body && <WaText text={body} className="mb-2 block" />}
            {buttons.map((b, i) => (
                <div key={i} className={`mt-1 rounded-lg border px-3 py-1.5 text-xs text-center border-black/10 dark:border-white/15`}>
                    {b.reply?.title ?? b.title ?? b.text}
                </div>
            ))}
        </div>
    );
}

function PollTopLevelBubble({ payload }) {
    const { t } = useTranslation();
    const poll = payload?.poll ?? {};
    const title = poll.title ?? t('inbox.poll');
    const options = poll.options ?? [];
    return (
        <div className={`rounded-xl px-3 py-2.5 mb-1 bg-black/[0.04] dark:bg-white/10`}>
            <div className="flex items-center gap-1.5 mb-2">
                <BarChart2 className="h-3.5 w-3.5 shrink-0" />
                <span className="text-xs font-semibold">{title}</span>
            </div>
            {options.map((opt, i) => (
                <div key={i} className="text-xs px-2 py-1 rounded mt-1 opacity-80 border border-current/10">
                    {opt.name ?? opt.title ?? opt}
                </div>
            ))}
        </div>
    );
}

function EventBubble({ payload, body }) {
    const { t } = useTranslation();
    const ev = payload?.event ?? {};
    const type = ev.type ?? '';
    const dir  = ev.direction ?? '';
    const label = body || [type, dir].filter(Boolean).join(' — ') || t('inbox.call_event');
    const CallIcon = dir?.includes('MISSED') ? PhoneMissed : Phone;
    return (
        <div className="flex items-center gap-2 opacity-70 italic text-xs py-0.5">
            <CallIcon className="h-3.5 w-3.5 shrink-0" />
            <span>{label}</span>
        </div>
    );
}

/* ─── main MessageBubble ─────────────────────────────── */

function SoundPrefsMenu() {
    const { t } = useTranslation();
    const [open, setOpen]   = useState(false);
    const [prefs, setPrefs] = useState(getSoundPrefs());
    const ref = useRef(null);

    useEffect(() => {
        if (!open) return;
        const onDoc = (e) => { if (ref.current && !ref.current.contains(e.target)) setOpen(false); };
        document.addEventListener('mousedown', onDoc);
        return () => document.removeEventListener('mousedown', onDoc);
    }, [open]);

    const anyOn = Object.values(prefs).some(Boolean);

    const toggle = (key) => {
        const next = !prefs[key];
        setChannelSoundEnabled(key, next);
        setPrefs((p) => ({ ...p, [key]: next }));
        if (next) playInboundSound(key); // preview the sound when enabling
    };

    return (
        <div className="relative" ref={ref}>
            <button
                type="button"
                onClick={() => setOpen((o) => !o)}
                title={t('inbox.notification_sounds')}
                className="p-1 rounded hover:bg-neutral-100 dark:hover:bg-neutral-800 text-neutral-400 transition"
            >
                {anyOn ? <Volume2 className="h-3.5 w-3.5" /> : <VolumeX className="h-3.5 w-3.5" />}
            </button>
            {open && (
                <div className="absolute right-0 z-30 mt-1 w-48 rounded-lg border border-neutral-200 dark:border-neutral-700 bg-white dark:bg-neutral-800 shadow-lg p-1.5">
                    <div className="px-2 py-1 text-[11px] font-semibold uppercase tracking-wide text-neutral-400">
                        {t('inbox.notification_sounds')}
                    </div>
                    {SOUND_CHANNELS.map((c) => (
                        <button
                            key={c.key}
                            type="button"
                            onClick={() => toggle(c.key)}
                            className="flex w-full items-center justify-between gap-2 rounded-md px-2 py-1.5 text-xs text-neutral-700 dark:text-neutral-200 hover:bg-neutral-50 dark:hover:bg-neutral-700"
                        >
                            <span className="flex items-center gap-1.5">
                                <ChannelBrandIcon channel={c.key} className="h-3.5 w-3.5" />
                                {c.label}
                            </span>
                            <span className={`relative inline-block h-4 w-7 shrink-0 rounded-full transition ${prefs[c.key] ? 'bg-brand-500' : 'bg-neutral-300 dark:bg-neutral-600'}`}>
                                <span className={`absolute top-0.5 h-3 w-3 rounded-full bg-white shadow transition-all ${prefs[c.key] ? 'left-3.5' : 'left-0.5'}`} />
                            </span>
                        </button>
                    ))}
                </div>
            )}
        </div>
    );
}

function MessageBubble({ msg, conversationId, contactName }) {
    const { t } = useTranslation();
    const [preview, setPreview] = useState(null);
    const { props: pageProps } = usePage();
    const bubbleTz = pageProps.timezone || 'Europe/Bucharest';
    const isOut = msg.direction === 'out';
    const p     = msg.payload ?? {};

    // Resolve media source: outbound has preview_url directly; inbound raw webhook nests under type key
    const mediaType   = msg.type ?? 'text';
    const previewUrl  = p.preview_url ?? p[mediaType]?.preview_url ?? null;
    const rawMediaId  = p[mediaType]?.id ?? p.media_id ?? null;
    // Use proxy if no previewUrl for inbound media
    const mediaSrc = previewUrl ?? (rawMediaId ? route('client.inbox.message-media', { conversation: conversationId, message: msg.id }) : null);

    // Template header components — prefer `definition` (full text+params) over raw `components` (params-only)
    const templateComponents = p.template?.definition ?? p.template?.components ?? (Array.isArray(p.components) ? p.components : null);

    // Inbound raw payload extras
    const location = p.location ?? p[mediaType];
    const contacts = p.contacts;
    const reaction = p.reaction;
    const sticker  = mediaType === 'sticker';
    const caption  = p.caption ?? p[mediaType]?.caption ?? (msg.body && msg.body !== '(media)' ? msg.body : '');

    // An email is a document, not a chat line: it carries a subject, paragraphs
    // and a signature, and squeezing it into 72% of the column wastes half the
    // screen on the longest messages in the inbox. It gets the full width — and
    // because full width means left/right no longer says who wrote it, the
    // colour and the named header have to carry that on their own.
    const isEmail = msg.channel === 'email';

    // Both sides sit on a light surface: outbound is a brand tint rather than a
    // solid fill, so a long reply does not become a slab of colour and the status
    // ticks, links and inset cards stay legible without a second palette.
    const bubbleBase = isEmail
        ? `w-full overflow-hidden rounded-xl border border-l-4 text-sm ${isOut
            ? 'border-brand-100 border-l-brand-500 bg-brand-50/70 text-neutral-900 dark:border-brand-900/40 dark:border-l-brand-500 dark:bg-brand-900/20 dark:text-neutral-100'
            : 'border-neutral-200 border-l-neutral-300 bg-white text-neutral-900 dark:border-neutral-700 dark:border-l-neutral-500 dark:bg-neutral-800 dark:text-neutral-100'}`
        : `max-w-[72%] overflow-hidden rounded-2xl border text-sm ${isOut
            ? 'rounded-br-md border-brand-100 bg-brand-50 text-neutral-900 dark:border-brand-900/40 dark:bg-brand-900/25 dark:text-neutral-100'
            : 'rounded-bl-md border-neutral-200 bg-white text-neutral-900 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-100'}`;

    // Reaction: no bubble, just float
    if (mediaType === 'reaction' || reaction) {
        return (
            <div className={`flex ${isOut ? 'justify-end' : 'justify-start'} mb-1`}>
                <ReactionBubble reaction={reaction ?? p} />
            </div>
        );
    }

    // Sticker: transparent background
    if (sticker) {
        return (
            <div className={`flex ${isOut ? 'justify-end' : 'justify-start'} mb-2`}>
                <StickerBubble src={mediaSrc} conversationId={conversationId} messageId={msg.id} />
            </div>
        );
    }

    // messages.sent_by is an enum written by the platform itself — 'bot' by the
    // chatbot, 'automation' by a workflow, 'broadcast' by a campaign. It has always
    // been stored and never shown, so an automated reply was indistinguishable from
    // one a colleague typed. Inbound messages carry no useful value here.
    const senderKind = isOut ? (msg.sent_by ?? 'human') : 'human';
    const senderBadge = SENDER_BADGES[senderKind] ? (() => {
        const { icon: BadgeIcon, labelKey } = SENDER_BADGES[senderKind];
        return (
            <span className="mb-1 flex items-center gap-1.5 text-[11px] font-semibold text-brand-700 dark:text-brand-300">
                <BadgeIcon className="h-3.5 w-3.5" />
                {t(labelKey)}
            </span>
        );
    })() : null;

    const statusGlyph = msg.status === 'read'      ? '✓✓'
                       : msg.status === 'delivered' ? '✓✓'
                       : msg.status === 'sent'      ? '✓'
                       : msg.status === 'failed'    ? '✗'
                       : '⋯';
    const statusClass = msg.status === 'read'   ? 'text-brand-600 dark:text-brand-400'
                      : msg.status === 'failed' ? 'text-coral-600'
                      : 'text-neutral-400';
    // An unmapped status must read as itself rather than as a missing key.
    const statusFallback = { defaultValue: msg.status };

    // Outside the bubble, under it: inside, the timestamp competed with the last
    // line of the message and forced a blank strip on every short reply.
    const timeRow = (
        <div className={`mt-1 flex items-center gap-1 px-1 text-[10px] text-neutral-400 ${isOut ? 'justify-end' : ''}`}>
            {/* An email prints its own time in the card header, beside the sender. */}
            <span>{!isEmail && msg.sent_at ? formatTimeTz(msg.sent_at, bubbleTz) : ''}</span>
            {isOut && msg.status && (
                <>
                    <span aria-hidden>·</span>
                    <span title={msg.status} className={statusClass}>{t(`inbox.msg_status_${msg.status}`, statusFallback)}</span>
                    <span className={statusClass}>{statusGlyph}</span>
                </>
            )}
        </div>
    );

    return (
        <div className={`mb-2 flex flex-col ${isEmail ? 'items-stretch' : isOut ? 'items-end' : 'items-start'}`}>
            <div className={bubbleBase}>
                {/* Template header image/video/doc */}
                {templateComponents && (
                    <TemplateHeaderMedia components={templateComponents} conversationId={conversationId} messageId={msg.id} />
                )}

                <div className="px-3.5 py-2.5">
                    {senderBadge}

                    {/* Email carries a subject; chat does not. Shown above the body
                        because a thread can be renamed halfway through. The name
                        above it is not decoration: at full width there is no left
                        or right to say who wrote this one. */}
                    {isEmail && (
                        <div className="mb-1.5 flex items-baseline justify-between gap-2 border-b border-black/5 pb-1.5 dark:border-white/10">
                            <div className="min-w-0">
                                <p className="truncate text-[11px] font-semibold uppercase tracking-wide text-neutral-500 dark:text-neutral-400">
                                    {isOut ? t('inbox.you') : (contactName || t('inbox.unknown_contact'))}
                                </p>
                                {p.subject && (
                                    <p className="mt-0.5 truncate text-[13px] font-semibold">{p.subject}</p>
                                )}
                            </div>
                            <span className="shrink-0 text-[10px] tabular-nums text-neutral-400">
                                {msg.sent_at ? formatTimeTz(msg.sent_at, bubbleTz) : ''}
                            </span>
                        </div>
                    )}

                    {/* IMAGE */}
                    {mediaType === 'image' && (
                        <MediaImage src={mediaSrc} alt={caption} conversationId={conversationId} messageId={msg.id} />
                    )}

                    {/* VIDEO */}
                    {mediaType === 'video' && (
                        <MediaVideo src={mediaSrc} conversationId={conversationId} messageId={msg.id} />
                    )}

                    {/* AUDIO */}
                    {mediaType === 'audio' && (
                        <MediaAudio src={mediaSrc} conversationId={conversationId} messageId={msg.id} />
                    )}

                    {/* DOCUMENT */}
                    {mediaType === 'document' && (
                        <MediaDocument
                            src={mediaSrc}
                            filename={p.filename ?? p.document?.filename ?? p[mediaType]?.filename}
                            conversationId={conversationId}
                            messageId={msg.id}
                        />
                    )}

                    {/* LOCATION */}
                    {mediaType === 'location' && (
                        <LocationCard location={p.location ?? p} />
                    )}

                    {/* CONTACTS */}
                    {mediaType === 'contacts' && (
                        <ContactCard contacts={contacts ?? [p]} />
                    )}

                    {/* INTERACTIVE */}
                    {mediaType === 'interactive' && (
                        <InteractiveBubble payload={p} isOut={isOut} />
                    )}

                    {/* TEMPLATE — with components (outbound structured) */}
                    {templateComponents && (
                        <TemplateBodyContent components={templateComponents} />
                    )}

                    {/* TEMPLATE — inbound raw (no components) */}
                    {mediaType === 'template' && !templateComponents && (
                        <WaText text={msg.body || '[template]'} />
                    )}

                    {/* POLL (native WhatsApp poll type) */}
                    {mediaType === 'poll' && (
                        <PollTopLevelBubble payload={p} body={msg.body} />
                    )}

                    {/* EVENT */}
                    {mediaType === 'event' && (
                        <EventBubble payload={p} body={msg.body} isOut={isOut} />
                    )}

                    {/* UNSUPPORTED */}
                    {mediaType === 'unsupported' && (
                        <UnsupportedBubble payload={p} body={msg.body} />
                    )}

                    {/* TEXT / fallback. Email is Markdown at both ends — inbound
                        was converted from HTML when it was filed, outbound is what
                        the composer wrote — so it renders through MarkdownLite,
                        which puts everything in React text nodes and never touches
                        innerHTML. WaText is WhatsApp's own *bold* dialect and would
                        read an email's asterisks wrong. */}
                    {!templateComponents && (mediaType === 'text' || (!['image','video','audio','document','location','contacts','interactive','template','poll','event','unsupported'].includes(mediaType))) && (
                        isEmail
                            ? <MarkdownLite content={msg.body || '(media)'} />
                            : <WaText text={msg.body || '(media)'} />
                    )}

                    {/* Email attachments. The few types that are safe to display
                        open in place; everything else is a download, because the
                        server will not serve it any other way. */}
                    {isEmail && p.attachments?.length > 0 && (
                        <div className="mt-2 space-y-1">
                            {p.attachments.map((file, i) => {
                                const url = file.path
                                    ? route('client.email.attachment', { conversation: conversationId, message: msg.id, index: i })
                                    : null;
                                const rowClass = 'group/att flex w-full items-center gap-2.5 rounded-xl border border-black/5 bg-white/70 px-2.5 py-2 text-left transition hover:border-black/10 hover:bg-white dark:border-white/10 dark:bg-white/5 dark:hover:bg-white/10';
                                const Action = previewKindFor(file.path) ? Eye : Download;

                                if (!url) {
                                    return (
                                        <p key={i} className="flex items-center gap-2.5 rounded-xl border border-dashed border-black/10 px-2.5 py-2 dark:border-white/10">
                                            <AttachmentIcon file={file} muted />
                                            <span className="min-w-0 flex-1">
                                                <span className="block truncate text-xs font-medium opacity-60">{file.name}</span>
                                                <span className="block text-[11px] text-neutral-400">{t('inbox.attachment_too_large')}</span>
                                            </span>
                                        </p>
                                    );
                                }

                                const inner = (
                                    <>
                                        <AttachmentIcon file={file} />
                                        <span className="min-w-0 flex-1">
                                            <span className="block truncate text-xs font-medium">{file.name}</span>
                                            <span className="block text-[11px] tabular-nums text-neutral-400">{formatBytes(file.size)}</span>
                                        </span>
                                        <Action className="h-4 w-4 shrink-0 text-neutral-400 transition group-hover/att:text-brand-600" />
                                    </>
                                );

                                return previewKindFor(file.path) ? (
                                    <button key={i} type="button" onClick={() => setPreview({ file, url })} className={rowClass}>
                                        {inner}
                                    </button>
                                ) : (
                                    <a key={i} href={url} className={rowClass}>{inner}</a>
                                );
                            })}
                        </div>
                    )}

                    {/* Caption below media */}
                    {['image','video','document','audio'].includes(mediaType) && caption && (
                        <p className="text-xs mt-1 opacity-90"><WaText text={caption} /></p>
                    )}

                </div>
            </div>
            {timeRow}
            {preview && <AttachmentPreview file={preview.file} url={preview.url} onClose={() => setPreview(null)} />}
        </div>
    );
}

/* ─── emoji picker ───────────────────────────────────── */
function EmojiPicker({ onPick, onClose }) {
    const ref = useRef(null);
    useEffect(() => {
        const handler = (e) => { if (ref.current && !ref.current.contains(e.target)) onClose(); };
        document.addEventListener('mousedown', handler);
        return () => document.removeEventListener('mousedown', handler);
    }, [onClose]);
    return (
        <div ref={ref} className="absolute bottom-full mb-2 left-0 z-50 bg-white dark:bg-neutral-900 border border-neutral-200 dark:border-neutral-700 rounded-xl shadow-lg p-2 w-64">
            <div className="grid grid-cols-10 gap-0.5">
                {EMOJI_LIST.map(e => (
                    <button key={e} type="button" onClick={() => { onPick(e); onClose(); }}
                        className="h-8 w-8 flex items-center justify-center text-lg rounded hover:bg-neutral-100 dark:hover:bg-neutral-800 transition">
                        {e}
                    </button>
                ))}
            </div>
        </div>
    );
}

/* ─── template picker ────────────────────────────────── */
/** Extract {{N}} variable indices from a template component text */
function extractVars(components) {
    const body = components?.find(c => c.type === 'BODY');
    const text = body?.text ?? '';
    const matches = [...text.matchAll(/\{\{(\d+)\}\}/g)];
    const indices = [...new Set(matches.map(m => m[1]))].sort((a, b) => Number(a) - Number(b));
    return indices; // e.g. ['1', '2']
}

function resolveBody(text, vars) {
    return text.replace(/\{\{(\d+)\}\}/g, (_, i) => vars[i] ?? `{{${i}}}`);
}

function TemplatePicker({ conversationId, onSent, onClose }) {
    const { t } = useTranslation();
    const ref = useRef(null);
    const headerFileRef = useRef(null);
    const [query, setQuery]                 = useState('');
    const [templates, setTemplates]         = useState([]);
    const [loading, setLoading]             = useState(true);
    const [picked, setPicked]               = useState(null);
    const [vars, setVars]                   = useState({});
    const [headerMedia, setHeaderMedia]     = useState(null);
    const [uploadError, setUploadError]     = useState('');
    const [sending, setSending]             = useState(false);
    const [sendError, setSendError]         = useState('');

    useEffect(() => {
        const h = (e) => { if (ref.current && !ref.current.contains(e.target)) onClose(); };
        document.addEventListener('mousedown', h);
        return () => document.removeEventListener('mousedown', h);
    }, [onClose]);

    useEffect(() => {
        axios.get(route('client.inbox.templates'))
            .then(r => setTemplates(r.data ?? []))
            .catch(() => {})
            .finally(() => setLoading(false));
    }, []);

    const filtered = templates.filter(t => t.name.toLowerCase().includes(query.toLowerCase()));

    const pickTemplate = (t) => {
        const varIds = extractVars(t.components);
        setPicked(t);
        setVars(Object.fromEntries(varIds.map(id => [id, ''])));
        setHeaderMedia(null);
        setUploadError('');
    };

    // Detect header component type
    const headerComp = picked?.components?.find(c => c.type === 'HEADER');
    const headerFormat = headerComp?.format ?? null; // IMAGE | VIDEO | DOCUMENT | TEXT | null

    const handleHeaderFile = async (e) => {
        const file = e.target.files?.[0];
        if (!file) return;
        const previewUrl = file.type.startsWith('image/') ? URL.createObjectURL(file) : null;
        setHeaderMedia({ file, previewUrl, mediaId: null, uploading: true });
        setUploadError('');
        const fd = new FormData();
        fd.append('file', file);
        try {
            const r = await axios.post(
                route('client.inbox.upload-media', conversationId),
                fd,
                { headers: { 'Content-Type': 'multipart/form-data' } }
            );
            setHeaderMedia(prev => ({ ...prev, mediaId: r.data.media_id, previewUrl: r.data.preview_url ?? prev.previewUrl, uploading: false }));
        } catch (err) {
            setUploadError(err.response?.data?.error ?? t('inbox.upload_failed'));
            setHeaderMedia(prev => ({ ...prev, uploading: false }));
        }
        e.target.value = '';
    };

    const handleSend = async () => {
        setSendError('');
        setSending(true);

        const bodyComp     = picked.components?.find(c => c.type === 'BODY');
        const bodyText     = bodyComp?.text ?? picked.name;
        const resolvedBody = resolveBody(bodyText, vars);
        const varIds       = extractVars(picked.components);
        const components   = [];

        if (headerFormat && headerFormat !== 'TEXT' && headerMedia?.mediaId) {
            const typeMap   = { IMAGE: 'image', VIDEO: 'video', DOCUMENT: 'document' };
            const mediaType = typeMap[headerFormat] ?? 'image';
            const mediaObj  = { id: headerMedia.mediaId };
            if (headerMedia.previewUrl) mediaObj.preview_url = headerMedia.previewUrl;
            components.push({
                type: 'header',
                parameters: [{ type: mediaType, [mediaType]: mediaObj }],
            });
        }

        if (varIds.length > 0) {
            components.push({
                type: 'body',
                parameters: varIds.map(id => ({ type: 'text', text: vars[id] ?? '' })),
            });
        }

        // Build full definition with text + parameters merged for UI display
        const definition = (picked.components ?? []).map(comp => {
            const ct = (comp.type ?? '').toUpperCase();
            if (ct === 'HEADER' && headerFormat && headerFormat !== 'TEXT' && headerMedia?.mediaId) {
                const typeMap   = { IMAGE: 'image', VIDEO: 'video', DOCUMENT: 'document' };
                const mediaType = typeMap[headerFormat] ?? 'image';
                const mediaObj  = { id: headerMedia.mediaId };
                if (headerMedia.previewUrl) mediaObj.preview_url = headerMedia.previewUrl;
                return { ...comp, parameters: [{ type: mediaType, [mediaType]: mediaObj }] };
            }
            if (ct === 'BODY' && varIds.length > 0) {
                return { ...comp, parameters: varIds.map(id => ({ type: 'text', text: vars[id] ?? '' })) };
            }
            return comp;
        });

        try {
            const r = await axios.post(route('client.inbox.reply', conversationId), {
                body: resolvedBody,
                type: 'template',
                payload: {
                    template: {
                        name: picked.name,
                        language: picked.language ?? 'en',
                        components,
                        definition,
                    },
                },
            });
            onSent?.(r.data?.message);
            onClose();
        } catch (err) {
            setSendError(err.response?.data?.message ?? err.response?.data?.error ?? t('inbox.send_failed'));
            setSending(false);
        }
    };

    const bodyText = picked ? (picked.components?.find(c => c.type === 'BODY')?.text ?? '') : '';
    const preview  = picked ? resolveBody(bodyText, vars) : '';
    const varIds   = picked ? extractVars(picked.components) : [];
    const needsHeader = headerFormat && headerFormat !== 'TEXT';
    const headerReady = !needsHeader || (headerMedia?.mediaId && !headerMedia?.uploading);
    const canSend  = headerReady && !sending && varIds.every(id => (vars[id] ?? '').trim() !== '');

    const headerAccept = headerFormat === 'IMAGE' ? 'image/*'
        : headerFormat === 'VIDEO' ? 'video/*'
        : headerFormat === 'DOCUMENT' ? '.pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx'
        : '*/*';

    return (
        <div ref={ref} className="absolute bottom-full mb-2 left-0 right-0 z-50 bg-white dark:bg-neutral-900 border border-neutral-200 dark:border-neutral-700 rounded-xl shadow-lg overflow-hidden flex flex-col max-h-[28rem]">
            {/* Picker header */}
            <div className="flex items-center gap-2 px-3 py-2 border-b border-neutral-100 dark:border-neutral-800 shrink-0">
                <LayoutTemplate className="h-4 w-4 text-neutral-400 shrink-0" />
                {!picked ? (
                    <input autoFocus value={query} onChange={e => setQuery(e.target.value)}
                        placeholder={t('inbox.search_templates')}
                        className="flex-1 text-sm bg-transparent focus:outline-none placeholder-neutral-400" />
                ) : (
                    <button type="button" onClick={() => setPicked(null)} className="text-xs text-brand-600 hover:underline dark:text-brand-400 flex-1 text-left">
                        {t('inbox.back_to_templates')}
                    </button>
                )}
                <button type="button" onClick={onClose} className="text-neutral-400 hover:text-neutral-600 shrink-0">
                    <X className="h-4 w-4" />
                </button>
            </div>

            {/* Template list */}
            {!picked && (
                <div className="overflow-y-auto flex-1">
                    {loading && <p className="text-xs text-neutral-400 text-center py-6">{t('inbox.loading_templates')}</p>}
                    {!loading && filtered.length === 0 && <p className="text-xs text-neutral-400 text-center py-6">{t('inbox.no_approved_templates')}</p>}
                    {filtered.map(t => {
                        const bodyPreview = t.components?.find(c => c.type === 'BODY')?.text ?? '';
                        const hdr = t.components?.find(c => c.type === 'HEADER');
                        return (
                            <button key={t.id} type="button" onClick={() => pickTemplate(t)}
                                className="w-full text-left px-3 py-2.5 hover:bg-brand-50 dark:hover:bg-brand-900/20 transition border-b border-neutral-100 dark:border-neutral-800 last:border-0">
                                <div className="flex items-center gap-1.5 mb-0.5 flex-wrap">
                                    <span className="text-xs font-semibold text-neutral-800 dark:text-neutral-200">{t.name}</span>
                                    <span className="text-[10px] text-neutral-400 bg-neutral-100 dark:bg-neutral-800 rounded px-1.5">{t.language}</span>
                                    <span className="text-[10px] text-neutral-400 bg-neutral-100 dark:bg-neutral-800 rounded px-1.5">{t.category}</span>
                                    {hdr?.format && hdr.format !== 'TEXT' && (
                                        <span className="text-[10px] text-amber-600 bg-amber-50 dark:bg-amber-900/20 rounded px-1.5 flex items-center gap-0.5">
                                            <ImageIcon className="h-2.5 w-2.5" />{hdr.format}
                                        </span>
                                    )}
                                </div>
                                <p className="text-xs text-neutral-500 line-clamp-2">{bodyPreview || t('inbox.no_body_preview')}</p>
                            </button>
                        );
                    })}
                </div>
            )}

            {/* Fill variables + header media */}
            {picked && (
                <div className="overflow-y-auto flex-1 p-3 space-y-3">
                    <div className="flex items-center gap-2">
                        <span className="text-xs font-semibold text-neutral-800 dark:text-neutral-200">{picked.name}</span>
                        <span className="text-[10px] text-neutral-400 bg-neutral-100 dark:bg-neutral-800 rounded px-1.5">{picked.language}</span>
                        {needsHeader && <span className="text-[10px] text-amber-600 bg-amber-50 dark:bg-amber-900/20 rounded px-1.5">{t('inbox.format_header', { format: headerFormat })}</span>}
                    </div>

                    {/* Header media upload */}
                    {needsHeader && (
                        <div>
                            <p className="text-[10px] font-bold uppercase tracking-wider text-neutral-400 mb-1">
                                {t('inbox.header_label', { format: headerFormat })} <span className="text-red-400">{t('inbox.required')}</span>
                            </p>
                            <input ref={headerFileRef} type="file" accept={headerAccept} className="hidden" onChange={handleHeaderFile} />
                            {!headerMedia ? (
                                <button type="button" onClick={() => headerFileRef.current?.click()}
                                    className="w-full rounded-xl border-2 border-dashed border-neutral-300 dark:border-neutral-600 py-4 flex flex-col items-center gap-1.5 text-neutral-400 hover:border-brand-400 hover:text-brand-500 transition">
                                    <ImageIcon className="h-6 w-6" />
                                    <span className="text-xs">{t('inbox.click_to_upload', { format: headerFormat.toLowerCase() })}</span>
                                </button>
                            ) : (
                                <div className="rounded-xl border border-neutral-200 dark:border-neutral-700 overflow-hidden">
                                    {headerMedia.previewUrl && (
                                        <img src={headerMedia.previewUrl} alt="header preview" className="w-full h-32 object-cover" />
                                    )}
                                    <div className="flex items-center gap-2 px-3 py-2 bg-neutral-50 dark:bg-neutral-800">
                                        {headerMedia.uploading ? (
                                            <><Loader2 className="h-4 w-4 animate-spin text-brand-600" /><span className="text-xs text-neutral-500">{t('inbox.uploading_to_whatsapp')}</span></>
                                        ) : headerMedia.mediaId ? (
                                            <><span className="text-xs text-green-600 dark:text-green-400 font-medium flex-1">{t('inbox.uploaded')}</span></>
                                        ) : (
                                            <span className="text-xs text-red-500 flex-1">{t('inbox.upload_failed')}</span>
                                        )}
                                        <button type="button" onClick={() => { setHeaderMedia(null); setUploadError(''); }}
                                            className="text-neutral-400 hover:text-red-500">
                                            <X className="h-3.5 w-3.5" />
                                        </button>
                                    </div>
                                </div>
                            )}
                            {uploadError && <p className="text-xs text-red-500 mt-1">{uploadError}</p>}
                        </div>
                    )}

                    {/* Body variables */}
                    {varIds.length > 0 && (
                        <div className="space-y-2">
                            <p className="text-[10px] font-bold uppercase tracking-wider text-neutral-400">{t('inbox.body_variables')}</p>
                            {varIds.map(id => (
                                <div key={id}>
                                    <label className="text-xs text-neutral-500 mb-0.5 block">{'{{' + id + '}}'}</label>
                                    <input type="text" value={vars[id] ?? ''} onChange={e => setVars(v => ({ ...v, [id]: e.target.value }))}
                                        placeholder={t('inbox.value_for_variable', { token: `{{${id}}}` })}
                                        className="w-full rounded-lg border border-neutral-300 dark:border-neutral-600 bg-neutral-50 dark:bg-neutral-800 px-2.5 py-1.5 text-xs focus:outline-none focus:ring-2 focus:ring-brand-500" />
                                </div>
                            ))}
                        </div>
                    )}

                    {/* Live preview */}
                    <div>
                        <p className="text-[10px] font-bold uppercase tracking-wider text-neutral-400 mb-1">{t('inbox.preview')}</p>
                        <div className="rounded-xl bg-neutral-50 dark:bg-neutral-800 border border-neutral-200 dark:border-neutral-700 overflow-hidden">
                            {needsHeader && headerMedia?.previewUrl && (
                                <img src={headerMedia.previewUrl} alt="" className="w-full h-24 object-cover" />
                            )}
                            <div className="p-3 text-xs text-neutral-700 dark:text-neutral-300 whitespace-pre-wrap max-h-28 overflow-y-auto">
                                <WaText text={preview || bodyText} />
                            </div>
                        </div>
                    </div>

                    {sendError && (
                        <p className="text-xs text-red-500 bg-red-50 dark:bg-red-900/20 rounded-lg px-3 py-2">{sendError}</p>
                    )}

                    <button type="button" onClick={handleSend} disabled={!canSend}
                        className="w-full rounded-xl bg-brand-600 hover:bg-brand-700 disabled:opacity-50 text-white text-sm font-semibold py-2.5 transition flex items-center justify-center gap-2">
                        {(headerMedia?.uploading || sending) ? <Loader2 className="h-4 w-4 animate-spin" /> : <Send className="h-4 w-4" />}
                        {headerMedia?.uploading ? t('inbox.uploading') : sending ? t('inbox.sending') : t('inbox.send')}
                    </button>
                </div>
            )}
        </div>
    );
}

/* ─── product picker (share a store product) ─────────── */
function ProductPicker({ conversationId, onSent, onClose }) {
    const { t } = useTranslation();
    const ref = useRef(null);
    const [query, setQuery]       = useState('');
    const [products, setProducts] = useState([]);
    const [loading, setLoading]   = useState(true);
    const [sendingId, setSendingId] = useState(null);
    const [error, setError]       = useState('');

    useEffect(() => {
        const h = (e) => { if (ref.current && !ref.current.contains(e.target)) onClose(); };
        document.addEventListener('mousedown', h);
        return () => document.removeEventListener('mousedown', h);
    }, [onClose]);

    // Debounced search so each keystroke doesn't fire a request.
    useEffect(() => {
        let active = true;
        setLoading(true);
        const t = setTimeout(() => {
            axios.get(route('client.ecommerce.products.search'), { params: { q: query } })
                .then(r => { if (active) setProducts(r.data ?? []); })
                .catch(() => { if (active) setProducts([]); })
                .finally(() => { if (active) setLoading(false); });
        }, 250);
        return () => { active = false; clearTimeout(t); };
    }, [query]);

    const share = (p) => {
        if (sendingId !== null) return;
        setSendingId(p.id);
        setError('');
        axios.post(route('client.inbox.share-product', conversationId), { product_id: p.id }, { headers: { Accept: 'application/json' } })
            .then(r => { onSent(r.data?.message, r.data?.error); })
            .catch(err => setError(err.response?.data?.error ?? err.response?.data?.message ?? t('inbox.failed_share_product')))
            .finally(() => setSendingId(null));
    };

    return (
        <div ref={ref} className="absolute bottom-full mb-2 left-0 right-0 z-20 rounded-xl border border-neutral-200 dark:border-neutral-700 bg-white dark:bg-neutral-900 shadow-xl flex flex-col max-h-80">
            <div className="p-2 border-b border-neutral-100 dark:border-neutral-800 flex items-center gap-2">
                <Search className="h-4 w-4 text-neutral-400 shrink-0" />
                <input autoFocus value={query} onChange={e => setQuery(e.target.value)}
                    placeholder={t('inbox.search_products_share')}
                    className="flex-1 bg-transparent text-sm focus:outline-none text-neutral-700 dark:text-neutral-200" />
                <button type="button" onClick={onClose} className="text-neutral-400 hover:text-neutral-600 transition"><X className="h-4 w-4" /></button>
            </div>
            {error && <p className="px-3 py-2 text-xs text-red-500">{error}</p>}
            <div className="overflow-y-auto">
                {loading ? (
                    <p className="text-sm text-neutral-400 py-6 text-center">{t('inbox.loading_products')}</p>
                ) : products.length === 0 ? (
                    <div className="py-6"><EmptyState icon={<ShoppingBag className="h-7 w-7" />} title={t('inbox.no_products')} description={t('inbox.no_products_match')} /></div>
                ) : products.map(p => (
                    <button key={p.id} type="button" onClick={() => share(p)} disabled={sendingId !== null}
                        className="w-full flex items-center gap-3 px-3 py-2 text-left hover:bg-brand-50 dark:hover:bg-brand-900/20 transition border-b border-neutral-100 dark:border-neutral-800 last:border-0 disabled:opacity-60">
                        {p.image_url
                            ? <img src={p.image_url} alt="" className="h-10 w-10 rounded-lg object-cover shrink-0" />
                            : <div className="h-10 w-10 rounded-lg bg-neutral-200 dark:bg-neutral-700 flex items-center justify-center shrink-0"><ShoppingBag className="h-4 w-4 text-neutral-400" /></div>}
                        <div className="flex-1 min-w-0">
                            <p className="text-sm font-medium text-neutral-800 dark:text-neutral-100 truncate">{p.name}</p>
                            <p className="text-xs text-neutral-500 truncate">
                                {p.sku ? `${p.sku} · ` : ''}{p.currency ? `${p.currency} ` : ''}{p.price}
                                {p.inventory_quantity != null && <span className="text-neutral-400"> · {t('inbox.in_stock', { count: p.inventory_quantity })}</span>}
                            </p>
                        </div>
                        {sendingId === p.id
                            ? <Loader2 className="h-4 w-4 animate-spin text-brand-500 shrink-0" />
                            : <Send className="h-4 w-4 text-neutral-300 dark:text-neutral-600 shrink-0" />}
                    </button>
                ))}
            </div>
        </div>
    );
}

/* ─── agent assign dropdown ──────────────────────────── */
function AgentDropdown({ teamMembers, currentUserId, conversationId, onAssigned, onClose }) {
    const { t } = useTranslation();
    const ref = useRef(null);
    const [query, setQuery] = useState('');
    const [loading, setLoading] = useState(false);
    useEffect(() => {
        const handler = (e) => { if (ref.current && !ref.current.contains(e.target)) onClose(); };
        document.addEventListener('mousedown', handler);
        return () => document.removeEventListener('mousedown', handler);
    }, [onClose]);

    const assign = (userId) => {
        setLoading(true);
        axios.post(route('client.inbox.assign', conversationId), { user_id: userId })
            .then(() => { onAssigned(userId); onClose(); })
            .catch(err => reportActionError(err, t('inbox.action_failed')))
            .finally(() => setLoading(false));
    };

    const filtered = (teamMembers ?? []).filter(m =>
        m.name.toLowerCase().includes(query.toLowerCase()) ||
        m.email.toLowerCase().includes(query.toLowerCase())
    );

    return (
        <div ref={ref} className="absolute top-full mt-1 right-0 z-50 w-56 bg-white dark:bg-neutral-900 border border-neutral-200 dark:border-neutral-700 rounded-xl shadow-lg overflow-hidden">
            <div className="p-2 border-b border-neutral-100 dark:border-neutral-800">
                <input
                    autoFocus
                    value={query}
                    onChange={e => setQuery(e.target.value)}
                    placeholder={t('inbox.search_agents')}
                    className="w-full text-xs bg-neutral-100 dark:bg-neutral-800 rounded-lg px-2.5 py-1.5 focus:outline-none placeholder-neutral-400"
                />
            </div>
            <div className="max-h-52 overflow-y-auto">
                <button type="button" onClick={() => assign(null)}
                    className="w-full text-left px-3 py-2 text-xs text-neutral-500 hover:bg-neutral-50 dark:hover:bg-neutral-800 transition border-b border-neutral-100 dark:border-neutral-800">
                    {t('inbox.unassign')}
                </button>
                {filtered.map(m => (
                    <button key={m.id} type="button" onClick={() => assign(m.id)}
                        className={`w-full text-left px-3 py-2 hover:bg-brand-50 dark:hover:bg-brand-900/20 transition ${loading ? 'opacity-50 pointer-events-none' : ''}`}>
                        <div className="flex items-center gap-2">
                            <div className="h-6 w-6 rounded-full bg-brand-100 dark:bg-brand-900/30 flex items-center justify-center text-xs font-semibold text-brand-700 dark:text-brand-300 shrink-0">
                                {m.name[0]?.toUpperCase()}
                            </div>
                            <div className="min-w-0">
                                <p className={`text-xs font-medium truncate ${m.id === currentUserId ? 'text-brand-600 dark:text-brand-400' : 'text-neutral-800 dark:text-neutral-200'}`}>
                                    {m.name} {m.id === currentUserId && t('inbox.you_paren')}
                                </p>
                                <p className="text-[10px] text-neutral-400 truncate">{m.email}</p>
                            </div>
                        </div>
                    </button>
                ))}
            </div>
        </div>
    );
}

/* ─── main component ─────────────────────────────────── */

export default function InboxShow({
    conversation,
    messages: initialMessages,
    allLabels = [],
    conversations: initialConversations,
    filters = {},
    teamMembers = [],
    whatsappTemplates = [],
    channelAccounts = [],
    hasEcommerceStore = false,
    counts = {},
}) {
    const { t, i18n } = useTranslation();
    const { props } = usePage();
    const flash = props.flash ?? {};
    const authUser = props.auth?.user;
    const userTz = props.timezone || 'Europe/Bucharest';
    // Prefer the active workspace (user can switch between accessible workspaces);
    // fall back to the user's primary workspace if not present.
    const workspaceId = props.currentWorkspace?.id ?? authUser?.workspace_id;
    // The server resolves this the same way; keep the two in step. Read from the
    // prop rather than the `messages` state, which is declared below — and a
    // conversation does not change channel mid-thread, so the seed is enough.
    const channel = conversation.channel_account?.channel
        ?? initialMessages?.[initialMessages.length - 1]?.channel
        ?? 'whatsapp';
    const isWindowOpen = conversation.is_whatsapp_window_open ?? (channel !== 'whatsapp');
    const isWhatsApp = channel === 'whatsapp';
    const isEmail = channel === 'email';

    // Left empty, the reply keeps the thread's own subject with a Re: in front —
    // show that, so nobody wonders what the customer will see.
    const lastInboundSubject = [...(initialMessages ?? [])]
        .reverse()
        .find(m => m.direction === 'in' && m.payload?.subject)?.payload?.subject ?? '';
    const emailSubjectPlaceholder = lastInboundSubject
        ? (/^(re:|răspuns:)/i.test(lastInboundSubject) ? lastInboundSubject : `Re: ${lastInboundSubject}`)
        : t('inbox.subject_placeholder');

    const [messages, setMessages]           = useState(initialMessages ?? []);
    const [viewers, setViewers]             = useState([]);
    const [typingUsers, setTypingUsers]     = useState([]);
    const [activeTab, setActiveTab]         = useState('messages');
    const [notes, setNotes]                 = useState([]);
    const [notesLoaded, setNotesLoaded]     = useState(false);
    const [ordersTotal, setOrdersTotal]     = useState(0);
    const [noteBody, setNoteBody]           = useState('');
    const [notePosting, setNotePosting]     = useState(false);
    const [cannedReplies, setCannedReplies] = useState([]);
    const [slashMenu, setSlashMenu]         = useState([]);
    const [convLabels, setConvLabels]       = useState(conversation.labels ?? []);
    const [assignedTo, setAssignedTo]       = useState(conversation.assigned_to ?? 'bot');
    const [assignedUserId, setAssignedUserId] = useState(conversation.assigned_user_id ?? null);
    const [conversations, setConversations] = useState(initialConversations);
    const [listLoading, setListLoading]     = useState(false);
    const [showNewModal, setShowNewModal]   = useState(false);
    const [sending, setSending]             = useState(false);
    const [sendError, setSendError]         = useState(null);
    // Read once on mount, so the "Astazi" / "Ieri" dividers do not recompute the
    // clock on every render. A session left open past midnight relabels on the
    // next navigation, which is the right trade for a stable render.
    // Read once so rendering stays pure — but then a tab left open overnight
    // keeps answering "today" with yesterday, which is every night for an inbox
    // that is never closed. Re-armed at the real boundary in the user's timezone.
    const [nowMs, setNowMs] = useState(() => Date.now());
    useEffect(() => {
        const timer = setTimeout(() => setNowMs(Date.now()), msUntilMidnightTz(userTz, nowMs));

        return () => clearTimeout(timer);
    }, [nowMs, userTz]);

    // When Inertia navigates between conversations the page component is
    // re-used, so seed local state from the new server props on conversation
    // change. The websocket listeners dedupe by `id` so any freshly broadcast
    // message that already lives in local state is not duplicated.
    useEffect(() => {
        setMessages(initialMessages ?? []);
        setConvLabels(conversation.labels ?? []);
        setAssignedTo(conversation.assigned_to ?? 'bot');
        setAssignedUserId(conversation.assigned_user_id ?? null);
        setSendError(null);
        setNotesLoaded(false);
        setOrdersTotal(0);
    }, [conversation.id]);

    useEffect(() => {
        setConversations(initialConversations);
        setListLoading(false);
    }, [initialConversations]);

    // Toolbar state
    const [showEmoji, setShowEmoji]           = useState(false);
    const [showTemplates, setShowTemplates]   = useState(false);
    const [showProducts, setShowProducts]     = useState(false);
    const [showAgentDrop, setShowAgentDrop]   = useState(false);
    // A list, not one: an email carries as many files as fit, and picking a
    // second used to silently replace the first. The other channels still take
    // one, because Meta's media API carries one per message.
    const [attachments, setAttachments] = useState([]); // [{ id, file, url, type }]
    const fileRef = useRef(null);
    const bodyRef = useRef(null);
    // Markdown is what gets stored, so the writer needs a way to see it rendered
    // before it leaves. Email only: WhatsApp has its own *bold* dialect and a
    // toolbar that emitted Markdown there would send the marks as literal text.
    const [previewingBody, setPreviewingBody] = useState(false);
    const bottomRef = useRef(null);

    const { data, setData, reset } = useForm({ body: '', type: 'text', payload: null, subject: '' });

    const scrollToBottom = useCallback(() => {
        bottomRef.current?.scrollIntoView({ behavior: 'smooth' });
    }, []);

    useEffect(() => {
        axios.get(route('client.inbox.canned-replies.list'))
            .then(r => setCannedReplies(r.data ?? []))
            .catch(() => {});
    }, []);

    useEffect(() => { scrollToBottom(); }, [messages]);

    // WS: conversation events
    useEffect(() => {
        if (!window.Echo) {
            console.warn('[inbox] Echo not initialised — real-time updates disabled. Check Pusher config.');
            return;
        }
        const ch = window.Echo.private(`conversation.${conversation.id}`);
        ch
            .listen('.MessageReceived', (e) => {
                setMessages(prev => prev.some(m => m.id === e.id) ? prev : [...prev, e]);
            })
            .listen('.MessageSent', (e) => {
                setMessages(prev => {
                    if (prev.some(m => m.id === e.id)) {
                        // Existing optimistic message — refresh status / payload from server.
                        return prev.map(m => m.id === e.id ? { ...m, ...e } : m);
                    }
                    return [...prev, e];
                });
            })
            .listen('.MessageStatusUpdated', (e) => {
                setMessages(prev => prev.map(m =>
                    m.id === e.id ? { ...m, status: e.status } : m
                ));
            })
            .listen('.ConversationAssigned', () => {
                router.reload({ only: ['conversation'] });
            })
            .listen('.TypingChanged', (e) => {
                if (e.user_id === authUser?.id) return;
                if (e.is_typing) {
                    setTypingUsers(prev => prev.some(u => u.user_id === e.user_id) ? prev : [...prev, e]);
                    setTimeout(() => setTypingUsers(prev => prev.filter(u => u.user_id !== e.user_id)), 3000);
                } else {
                    setTypingUsers(prev => prev.filter(u => u.user_id !== e.user_id));
                }
            });
        window.Echo.join(`presence-conversation.${conversation.id}`)
            .here(u => setViewers(u))
            .joining(u => setViewers(prev => [...prev.filter(v => v.id !== u.id), u]))
            .leaving(u => setViewers(prev => prev.filter(v => v.id !== u.id)));
        return () => {
            window.Echo.leave(`conversation.${conversation.id}`);
            window.Echo.leave(`presence-conversation.${conversation.id}`);
        };
    }, [conversation.id]);

    // WS: list updates
    useEffect(() => {
        if (!window.Echo || !workspaceId) return;
        window.Echo.private(`workspace.${workspaceId}`)
            .listen('.MessageReceived', (e) => {
                // Per-channel inbound notification sound (fires for every inbound
                // message in the workspace, regardless of which thread is open).
                playInboundSound(e.channel);
                setConversations(prev => {
                    if (!prev) return prev;
                    const exists = prev.data?.find(c => c.id === e.conversation_id);
                    if (!exists) return prev;
                    return { ...prev, data: [
                        { ...exists, unread_count: (exists.unread_count ?? 0) + 1, last_message_at: e.created_at, last_message: { body: e.body } },
                        ...prev.data.filter(c => c.id !== e.conversation_id),
                    ]};
                });
            });
        return () => { window.Echo.leave(`workspace.${workspaceId}`); };
    }, [workspaceId]);

    const typingTimer = useRef(null);
    const handleTyping = () => {
        // Use server-side broadcast instead of Pusher client events (whispers),
        // which require "Client Events" to be enabled in the Pusher dashboard.
        clearTimeout(typingTimer.current);
        axios.post(route('client.inbox.typing', conversation.uuid), { is_typing: true }).catch(() => {});
        typingTimer.current = setTimeout(() => {
            axios.post(route('client.inbox.typing', conversation.uuid), { is_typing: false }).catch(() => {});
        }, 3000);
    };

    const loadNotes = useCallback(() => {
        axios.get(route('client.inbox.notes.index', conversation.uuid))
            .then(r => { setNotes(r.data ?? []); setNotesLoaded(true); })
            .catch(() => {});
    }, [conversation.id]);

    useEffect(() => { if (activeTab === 'notes') loadNotes(); }, [activeTab]);

    const [orders, setOrders] = useState([]);
    const [ordersLoaded, setOrdersLoaded] = useState(false);
    const loadOrders = useCallback(() => {
        if (!conversation.contact?.uuid) return;
        axios.get(route('client.ecommerce.contacts.orders', conversation.contact.uuid))
            .then(r => {
                // The endpoint used to answer with a bare array; it now returns
                // { orders, total } so the tab can show a count the five rendered
                // rows could never supply.
                const payload = r.data ?? {};
                setOrders(Array.isArray(payload) ? payload : (payload.orders ?? []));
                setOrdersTotal(Array.isArray(payload) ? payload.length : (payload.total ?? 0));
            })
            .catch(() => {})
            .finally(() => setOrdersLoaded(true));
    }, [conversation.id]);

    useEffect(() => { if (activeTab === 'orders') loadOrders(); }, [activeTab]);

    const [activities, setActivities] = useState([]);
    const [activitiesLoaded, setActivitiesLoaded] = useState(false);
    const loadActivities = useCallback(() => {
        axios.get(route('client.inbox.activities.index', conversation.uuid))
            .then(r => setActivities(r.data ?? []))
            .catch(() => {})
            .finally(() => setActivitiesLoaded(true));
    }, [conversation.id]);

    useEffect(() => { if (activeTab === 'activity') loadActivities(); }, [activeTab, loadActivities]);

    const postNote = (e) => {
        e.preventDefault();
        if (!noteBody.trim() || notePosting) return;
        setNotePosting(true);
        axios.post(route('client.inbox.notes.store', conversation.uuid), { body: noteBody })
            .then(r => { setNotes(prev => [r.data, ...prev]); setNoteBody(''); })
            .catch(err => reportActionError(err, t('inbox.action_failed')))
            .finally(() => setNotePosting(false));
    };

    const handleReplyChange = (value) => {
        setData('body', value);
        handleTyping();
        const match = value.match(/^\/(\w*)$/);
        if (match) {
            const q = match[1].toLowerCase();
            setSlashMenu(cannedReplies.filter(r => r.shortcut.toLowerCase().startsWith(q)));
        } else {
            setSlashMenu([]);
        }
    };

    const toggleLabel = (label) => {
        const attached = convLabels.some(l => l.id === label.id);
        if (attached) {
            axios.delete(route('client.inbox.labels.detach', { conversation: conversation.uuid, label: label.id }))
                .then(() => setConvLabels(prev => prev.filter(l => l.id !== label.id)))
                .catch(err => reportActionError(err, t('inbox.action_failed')));
        } else {
            axios.post(route('client.inbox.labels.attach', conversation.uuid), { label_id: label.id })
                .then(r => setConvLabels(prev => [...prev, r.data.label]))
                .catch(err => reportActionError(err, t('inbox.action_failed')));
        }
    };

    const appendMessage = (msg) => {
        if (!msg) return;
        setMessages(prev => prev.some(m => m.id === msg.id) ? prev : [...prev, msg]);
    };

    // The box grows to fit what is in it rather than scrolling inside two rows.
    // An email is written in paragraphs, and following your own sentences through
    // a two-line window with its own scrollbar is the thing that makes a long
    // reply unpleasant to write.
    useEffect(() => {
        const el = bodyRef.current;
        if (!el) {
            return;
        }

        // Reset first: without it the box can only ever grow, because scrollHeight
        // never reports less than the height already set.
        el.style.height = 'auto';
        el.style.height = `${Math.min(el.scrollHeight, COMPOSER_MAX_HEIGHT)}px`;
        el.style.overflowY = el.scrollHeight > COMPOSER_MAX_HEIGHT ? 'auto' : 'hidden';
    }, [data.body, previewingBody, attachments.length]);

    const handleSend = (e) => {
        e?.preventDefault?.();
        if (sending) return;
        if (!data.body.trim() && attachments.length === 0) return;

        setSending(true);
        setSendError(null);
        axios.post(route('client.inbox.typing', conversation.uuid), { is_typing: false }).catch(() => {});

        const onDone = (res) => {
            const msg = res?.data?.message;
            const errText = res?.data?.error;
            appendMessage(msg);
            reset();
            attachments.forEach(a => URL.revokeObjectURL(a.url));
            setAttachments([]);
            if (errText) setSendError(errText);
        };

        const onErr = (err) => {
            const errText = err?.response?.data?.error
                ?? err?.response?.data?.message
                ?? err?.message
                ?? t('inbox.failed_send_message');
            setSendError(errText);
        };

        const config = { headers: { Accept: 'application/json' } };

        if (attachments.length > 0) {
            const fd = new FormData();
            fd.append('body', data.body || attachments[0].file.name);
            if (isEmail) {
                // Plain text: an email renders its body and lists its files. Typing
                // it as a document would draw a broken media card above them.
                fd.append('type', 'text');
                if (data.subject) fd.append('subject', data.subject);
                attachments.forEach(a => fd.append('attachments[]', a.file));
            } else {
                fd.append('type', attachments[0].type === 'image' ? 'image' : 'document');
                fd.append('attachment', attachments[0].file);
            }
            axios.post(route('client.inbox.reply', conversation.uuid), fd, {
                headers: { 'Content-Type': 'multipart/form-data', Accept: 'application/json' },
            })
                .then(onDone)
                .catch(onErr)
                .finally(() => setSending(false));
        } else {
            axios.post(route('client.inbox.reply', conversation.uuid), {
                body: data.body,
                type: 'text',
                payload: null,
                // Only email has one; blank means "keep the thread's subject",
                // which the driver prefixes with Re: for us.
                subject: isEmail ? data.subject : undefined,
            }, config)
                .then(onDone)
                .catch(onErr)
                .finally(() => setSending(false));
        }
    };

    const handleFileChange = (e) => {
        const picked = Array.from(e.target.files ?? []);
        if (picked.length === 0) return;

        const mapped = picked.map(file => ({
            id: `${file.name}-${file.size}-${file.lastModified}`,
            file,
            url: URL.createObjectURL(file),
            type: file.type.startsWith('image/') ? 'image' : 'document',
        }));

        setAttachments(prev => {
            // Only email keeps a list; the others replace, because only one of
            // them can ever be sent.
            if (!isEmail) {
                prev.forEach(a => URL.revokeObjectURL(a.url));

                return mapped.slice(0, 1);
            }

            // Picking the same file twice is a slip, not an instruction.
            const seen = new Set(prev.map(a => a.id));
            mapped.filter(a => seen.has(a.id)).forEach(a => URL.revokeObjectURL(a.url));

            return [...prev, ...mapped.filter(a => !seen.has(a.id))];
        });
        e.target.value = '';
    };

    const removeAttachment = (id) => setAttachments(prev => {
        prev.filter(a => a.id === id).forEach(a => URL.revokeObjectURL(a.url));

        return prev.filter(a => a.id !== id);
    });

    const switchHandover = (mode) => {
        axios.post(route('client.inbox.handover', conversation.uuid), { mode })
            .then(() => setAssignedTo(mode))
            .catch(err => reportActionError(err, t('inbox.action_failed')));
    };

    const handleStatus = (status) => router.post(route('client.inbox.status', conversation.uuid), { status }, { preserveScroll: true });

    // Deleting hides the thread from every list for good. The row survives in the
    // database, and nothing is touched in the customer's own mailbox.
    const [confirmDelete, setConfirmDelete] = useState(false);
    const [deleting, setDeleting] = useState(false);
    const deleteConversation = () => {
        setDeleting(true);
        router.delete(route('client.inbox.destroy', conversation.uuid), {
            onFinish: () => setDeleting(false),
        });
    };

    // Memoised so the debounced search effect below can depend on it without
    // rebuilding its timer on every render, which would defeat the debounce.
    const navigateList = useCallback((params) => {
        setListLoading(true);
        router.get(route('client.inbox.show', conversation.uuid), { ...filters, ...params }, { preserveState: true, replace: true });
    }, [conversation.uuid, filters]);

    const otherViewers = viewers.filter(v => v.id !== authUser?.id);


    const contactName = conversation.contact?.first_name || conversation.contact?.last_name
        ? `${conversation.contact.first_name ?? ''} ${conversation.contact.last_name ?? ''}`.trim()
        : conversation.contact?.phone_e164 ?? 'Unknown';

    const contactSince = conversation.contact?.created_at
        ? customerSince(conversation.contact.created_at, i18n.language)
        : null;

    const assignedAgent = teamMembers.find(m => m.id === assignedUserId);

    return (
        <InboxLayout>
            <Head title={t('inbox.show_title', { name: contactName })} />
            {showNewModal && <NewConversationModal onClose={() => setShowNewModal(false)} />}
            <div className="flex flex-1 overflow-hidden">

                {/* ── Filter sidebar ── */}
                <aside className="flex w-56 shrink-0 flex-col overflow-hidden border-r border-neutral-200 bg-white dark:border-neutral-700 dark:bg-neutral-900">
                    <div className="border-b border-neutral-100 p-3 dark:border-neutral-800">
                        <button
                            onClick={() => setShowNewModal(true)}
                            className="flex w-full items-center justify-center gap-2 rounded-xl bg-brand-600 px-3 py-2 text-[13px] font-semibold text-white transition hover:bg-brand-700"
                        >
                            <Plus className="h-4 w-4" />
                            {t('inbox.new_conversation')}
                        </button>
                    </div>
                    <FilterSidebar
                        filters={filters}
                        labels={allLabels}
                        channelAccounts={channelAccounts}
                        counts={counts}
                        onFolder={(k) => navigateList({ folder: k, channel: undefined, label: undefined, account_id: undefined })}
                        onChannel={(ch) => navigateList({ channel: filters.channel === ch ? undefined : ch, account_id: undefined })}
                        onAccount={(id) => navigateList({ account_id: String(filters.account_id) === String(id) ? undefined : id, channel: undefined })}
                        onLabel={(id) => navigateList({ label: String(filters.label) === String(id) ? undefined : id })}
                    />
                </aside>

                {/* ── Conversation list ── */}
                <ConversationListPanel
                    conversations={conversations}
                    filters={filters}
                    counts={counts}
                    channelAccounts={channelAccounts}
                    activeUuid={conversation.uuid}
                    loading={listLoading}
                    userTz={userTz}
                    onNavigate={navigateList}
                    onRefresh={() => { setListLoading(true); router.reload(); }}
                    headerExtra={<SoundPrefsMenu />}
                />

                {/* ── Main thread ── */}
                <div className="flex-1 flex flex-col min-w-0 bg-neutral-50 dark:bg-neutral-950">

                    {/* Header */}
                    <div className="shrink-0 border-b border-neutral-200 bg-white px-4 py-3 dark:border-neutral-700 dark:bg-neutral-900">
                      <div className="flex items-center gap-3">
                        <div className="relative shrink-0">
                            <div className="h-9 w-9 rounded-full bg-brand-100 dark:bg-brand-900/30 flex items-center justify-center text-sm font-semibold text-brand-700 dark:text-brand-300">
                                {contactName[0]?.toUpperCase() ?? '?'}
                            </div>
                            <span className="absolute -bottom-0.5 -right-0.5 h-4 w-4 rounded-full bg-white dark:bg-neutral-900 flex items-center justify-center">
                                <ChannelBrandIcon channel={channel} className="h-3 w-3" />
                            </span>
                        </div>
                        <div className="flex-1 min-w-0">
                            <p className="truncate text-[15px] font-semibold text-neutral-900 dark:text-neutral-100">{contactName}</p>
                            {/* channel · number · since. The old third segment read
                                channel_account.name, a column that does not exist, so it
                                never rendered anything. */}
                            <p className="flex items-center gap-1.5 text-xs text-neutral-400">
                                <ChannelBrandIcon channel={channel} className="h-3 w-3 shrink-0" />
                                <span>{CHANNEL_LABELS[channel] ?? channel}</span>
                                {conversation.contact?.phone_e164 && (
                                    <><span className="text-neutral-300 dark:text-neutral-600">·</span>
                                    <span className="tabular-nums">{conversation.contact.phone_e164}</span></>
                                )}
                                {contactSince && (
                                    <><span className="text-neutral-300 dark:text-neutral-600">·</span>
                                    <span className="hidden lg:inline">{t('inbox.customer_since', { date: contactSince })}</span></>
                                )}
                            </p>
                        </div>

                        {/* Presence */}
                        {otherViewers.length > 0 && (
                            <div className="hidden items-center gap-1 text-xs text-neutral-400 xl:flex">
                                <Eye className="h-3.5 w-3.5" />
                                <span>{t('inbox.viewing', { names: otherViewers.map(v => v.name).join(', ') })}</span>
                            </div>
                        )}
                      </div>

                      {/* Actions on their own row, right-aligned. On one row with the
                          name they squeezed the contact's own details out of view the
                          moment a name ran long. */}
                      <div className="mt-2.5 flex flex-wrap items-center justify-end gap-2">
                        {/* Agent assign */}
                        <div className="relative">
                            <button
                                type="button"
                                onClick={() => setShowAgentDrop(v => !v)}
                                className="flex items-center gap-1.5 rounded-lg border border-neutral-200 dark:border-neutral-700 bg-white dark:bg-neutral-800 px-2.5 py-1.5 text-xs text-neutral-600 dark:text-neutral-400 hover:bg-neutral-50 dark:hover:bg-neutral-700 transition"
                            >
                                <UserCheck className="h-3.5 w-3.5 shrink-0" />
                                <span className="truncate max-w-[80px]">{assignedAgent?.name ?? t('inbox.assign')}</span>
                                <ChevronDown className="h-3 w-3 shrink-0" />
                            </button>
                            {showAgentDrop && (
                                <AgentDropdown
                                    teamMembers={teamMembers}
                                    currentUserId={authUser?.id}
                                    conversationId={conversation.uuid}
                                    onAssigned={(uid) => setAssignedUserId(uid)}
                                    onClose={() => setShowAgentDrop(false)}
                                />
                            )}
                        </div>

                        {/* Status */}
                        <select
                            defaultValue={conversation.status}
                            onChange={e => handleStatus(e.target.value)}
                            className={`cursor-pointer rounded-full border-0 px-3 py-1 text-xs font-medium focus:outline-none focus:ring-2 focus:ring-brand-500 ${STATUS_COLORS[conversation.status] ?? 'bg-neutral-100 text-neutral-600'}`}
                        >
                            {['open','pending','resolved','snoozed'].map(s => (
                                <option key={s} value={s} className="bg-white text-neutral-900 dark:bg-neutral-800">
                                    {t(`inbox.status_${s}`)}
                                </option>
                            ))}
                        </select>

                        {/* Resolve — the same endpoint as the dropdown, one click instead
                            of three. Hidden once it is already resolved. */}
                        {conversation.status !== 'resolved' && (
                            <button
                                type="button"
                                onClick={() => handleStatus('resolved')}
                                className="hidden items-center gap-1.5 rounded-lg bg-brand-600 px-3 py-1.5 text-xs font-semibold text-white transition hover:bg-brand-700 sm:inline-flex"
                            >
                                <Check className="h-3.5 w-3.5" />
                                {t('inbox.resolve')}
                            </button>
                        )}

                        <button
                            type="button"
                            onClick={() => setConfirmDelete(true)}
                            title={t('inbox.delete_conversation')}
                            aria-label={t('inbox.delete_conversation')}
                            className="rounded-lg border border-neutral-200 bg-white p-1.5 text-neutral-400 transition hover:border-coral-200 hover:bg-coral-50 hover:text-coral-600 dark:border-neutral-700 dark:bg-neutral-800 dark:hover:bg-neutral-700"
                        >
                            <Trash2 className="h-3.5 w-3.5" />
                        </button>
                      </div>

                      <Modal show={confirmDelete} onClose={() => setConfirmDelete(false)} maxWidth="md">
                        <div className="p-5">
                            <h3 className="text-base font-semibold text-neutral-900 dark:text-neutral-100">
                                {t('inbox.delete_confirm_title', { count: 1 })}
                            </h3>
                            <p className="mt-2 text-sm text-neutral-600 dark:text-neutral-400">
                                {t('inbox.delete_confirm_body')}
                            </p>
                            <div className="mt-5 flex justify-end gap-2">
                                <Button variant="ghost" onClick={() => setConfirmDelete(false)}>{t('common.cancel')}</Button>
                                <Button variant="danger" onClick={deleteConversation} disabled={deleting}>{t('common.delete')}</Button>
                            </div>
                        </div>
                      </Modal>
                    </div>

                    {/* Tab bar */}
                    <div className="flex border-b border-neutral-200 dark:border-neutral-700 bg-white dark:bg-neutral-900 shrink-0">
                        {[
                            { key: 'messages', label: t('inbox.tab_messages'), icon: null },
                            // notesCount comes from the server so the badge is right
                            // before the tab is ever opened; once it has been, the
                            // local list is the fresher of the two.
                            { key: 'notes',    label: t('inbox.tab_notes'),    icon: <StickyNote className="mr-1 -mt-0.5 inline h-3.5 w-3.5" />, count: notesLoaded ? notes.length : (conversation.internal_notes_count ?? 0) },
                            ...(hasEcommerceStore ? [{ key: 'orders', label: t('inbox.tab_orders'), icon: <ShoppingBag className="mr-1 -mt-0.5 inline h-3.5 w-3.5" />, count: ordersTotal }] : []),
                            { key: 'activity', label: t('inbox.tab_activity'), icon: <History className="mr-1 -mt-0.5 inline h-3.5 w-3.5" /> },
                        ].map(tab => (
                            <button key={tab.key} onClick={() => setActiveTab(tab.key)}
                                className={`border-b-2 px-5 py-2.5 text-sm font-medium transition ${
                                    activeTab === tab.key
                                        ? 'border-brand-600 text-brand-700 dark:text-brand-300'
                                        : 'border-transparent text-neutral-500 hover:text-neutral-700 dark:hover:text-neutral-300'
                                }`}>
                                {tab.icon}{tab.label}
                                {tab.count > 0 && (
                                    <span className="ml-1.5 text-xs font-semibold tabular-nums text-neutral-400">{tab.count}</span>
                                )}
                            </button>
                        ))}
                    </div>

                    {/* Messages tab */}
                    {activeTab === 'messages' && (
                        <div className="flex-1 overflow-y-auto p-4 space-y-1">
                            {withDayDividers(groupMessagesForRender(messages), userTz).map(item => (
                                item.kind === 'day'
                                    ? <DayDivider key={item.key} at={item.at} day={item.day} tz={userTz} nowMs={nowMs} />
                                    : item.kind === 'album'
                                        ? <ImageGallery key={item.key} messages={item.messages} conversationId={conversation.uuid} />
                                        : <MessageBubble key={item.key} msg={item.msg} conversationId={conversation.uuid} contactName={contactName} />
                            ))}
                            {messages.length === 0 && (
                                <div className="py-8"><EmptyState icon={<MessageSquare className="h-8 w-8" />} title={t('inbox.no_messages_yet')} description={t('inbox.no_messages_desc')} /></div>
                            )}
                            <div ref={bottomRef} />
                        </div>
                    )}

                    {/* Notes tab */}
                    {activeTab === 'notes' && (
                        <div className="flex-1 overflow-y-auto p-4 space-y-3">
                            <form onSubmit={postNote} className="flex gap-2 mb-3">
                                <textarea value={noteBody} onChange={e => setNoteBody(e.target.value)}
                                    placeholder={t('inbox.note_placeholder')}
                                    rows={2}
                                    className="flex-1 rounded-xl border border-neutral-300 dark:border-neutral-600 bg-white dark:bg-neutral-800 px-3 py-2 text-sm resize-none focus:outline-none focus:ring-2 focus:ring-brand-500"
                                />
                                <button type="submit" disabled={notePosting || !noteBody.trim()}
                                    className="self-end rounded-xl bg-amber-500 p-2.5 text-white hover:bg-amber-600 disabled:opacity-50 transition">
                                    <Send className="h-4 w-4" />
                                </button>
                            </form>
                            {notes.length === 0 ? (
                                <div className="py-6"><EmptyState icon={<StickyNote className="h-7 w-7" />} title={t('inbox.no_internal_notes')} description={t('inbox.no_internal_notes_desc')} /></div>
                            ) : notes.map(note => (
                                <div key={note.id} className="rounded-xl bg-amber-50 dark:bg-amber-900/20 border border-amber-200 dark:border-amber-800 p-3 text-sm">
                                    <div className="flex items-center gap-2 mb-1">
                                        <span className="font-semibold text-amber-800 dark:text-amber-300">{note.user?.name ?? t('inbox.you')}</span>
                                        <span className="text-xs text-neutral-400">{formatInTz(note.created_at, userTz)}</span>
                                    </div>
                                    <p className="text-neutral-700 dark:text-neutral-300 whitespace-pre-wrap">{note.body}</p>
                                </div>
                            ))}
                        </div>
                    )}

                    {/* Orders tab */}
                    {activeTab === 'orders' && (
                        <div className="flex-1 overflow-y-auto p-4 space-y-3">
                            {!ordersLoaded ? (
                                <p className="text-sm text-neutral-400 py-6 text-center">{t('inbox.loading_orders')}</p>
                            ) : orders.length === 0 ? (
                                <div className="py-6"><EmptyState icon={<ShoppingBag className="h-7 w-7" />} title={t('inbox.no_orders')} description={t('inbox.no_orders_desc')} /></div>
                            ) : orders.map((o, i) => (
                                <div key={i} className="rounded-xl border border-neutral-200 dark:border-neutral-700 bg-white dark:bg-neutral-900 p-3 text-sm">
                                    <div className="flex items-center justify-between mb-1">
                                        <span className="font-semibold text-neutral-800 dark:text-neutral-200">{o.number || '—'}</span>
                                        <span className="font-semibold text-neutral-900 dark:text-neutral-100">{o.currency} {o.total}</span>
                                    </div>
                                    <div className="flex items-center gap-2 flex-wrap text-xs text-neutral-500">
                                        {o.fulfillment_status && <span className="px-2 py-0.5 rounded-full bg-neutral-100 dark:bg-neutral-800">{o.fulfillment_status}</span>}
                                        {o.financial_status && <span className="px-2 py-0.5 rounded-full bg-neutral-100 dark:bg-neutral-800">{o.financial_status}</span>}
                                        {o.placed_at && <span>{formatInTz(o.placed_at, userTz)}</span>}
                                    </div>
                                    {o.tracking_url && (
                                        <a href={o.tracking_url} target="_blank" rel="noopener noreferrer" className="mt-1.5 inline-block text-xs text-brand-600 hover:underline">{t('inbox.track_shipment')}</a>
                                    )}
                                </div>
                            ))}
                        </div>
                    )}

                    {/* Activity tab */}
                    {activeTab === 'activity' && (
                        <div className="flex-1 overflow-y-auto p-4">
                            {!activitiesLoaded ? (
                                <p className="text-sm text-neutral-400 py-6 text-center">{t('inbox.loading_activity')}</p>
                            ) : activities.length === 0 ? (
                                <div className="py-6"><EmptyState icon={<History className="h-7 w-7" />} title={t('inbox.no_activity')} description={t('inbox.no_activity_desc')} /></div>
                            ) : (
                                <div>
                                    {activities.map((a, i) => (
                                        <ActivityRow key={a.id} activity={a} t={t} userTz={userTz} isLast={i === activities.length - 1} />
                                    ))}
                                </div>
                            )}
                        </div>
                    )}

                    {/* Contact tab */}
                    {/* Typing indicator */}
                    {typingUsers.length > 0 && activeTab === 'messages' && (
                        <div className="px-4 py-1.5 text-xs text-neutral-400 italic bg-neutral-50 dark:bg-neutral-950 shrink-0">
                            {typingUsers.length === 1
                                ? t('inbox.typing_one', { names: typingUsers.map(u => u.user_name).join(', ') })
                                : t('inbox.typing_many', { names: typingUsers.map(u => u.user_name).join(', ') })}
                        </div>
                    )}

                    {/* 24h window warning */}
                    {isWhatsApp && !isWindowOpen && activeTab === 'messages' && (
                        <div className="flex items-center gap-2 px-4 py-2 bg-amber-50 dark:bg-amber-900/30 border-t border-amber-200 dark:border-amber-700 text-amber-700 dark:text-amber-300 text-xs shrink-0">
                            <AlertTriangle className="h-4 w-4 shrink-0" />
                            {t('inbox.session_closed_warning')}
                        </div>
                    )}

                    {/* Reply box */}
                    {activeTab === 'messages' && (
                    <div className="border-t border-neutral-200 dark:border-neutral-700 bg-white dark:bg-neutral-900 p-3 shrink-0">
                        {flash.error && <p className="text-xs text-red-500 mb-2">{flash.error}</p>}
                        {sendError && (
                            <div className="flex items-start justify-between gap-2 mb-2 text-xs text-red-600 bg-red-50 dark:bg-red-900/20 dark:text-red-300 rounded-lg px-3 py-2">
                                <span className="flex-1">{sendError}</span>
                                <button type="button" onClick={() => setSendError(null)} className="text-red-400 hover:text-red-600">
                                    <X className="h-3.5 w-3.5" />
                                </button>
                            </div>
                        )}



                        {/* Attachments waiting to go */}
                        {attachments.length > 0 && (
                            <div className="mb-2 space-y-1.5">
                                {attachments.map(a => (
                                    <div key={a.id} className="flex items-center gap-2.5 rounded-xl border border-neutral-200 bg-neutral-50 p-2 dark:border-neutral-700 dark:bg-neutral-800">
                                        {a.type === 'image'
                                            ? <img src={a.url} alt="" className="h-9 w-9 rounded-lg object-cover" />
                                            : <AttachmentIcon file={{ name: a.file.name }} />}
                                        <div className="min-w-0 flex-1">
                                            <p className="truncate text-xs font-medium text-neutral-700 dark:text-neutral-300">{a.file.name}</p>
                                            <p className="text-[11px] tabular-nums text-neutral-400">{formatBytes(a.file.size)}</p>
                                        </div>
                                        <button type="button" onClick={() => removeAttachment(a.id)}
                                            aria-label={t('inbox.attachment_remove')}
                                            className="rounded-lg p-1 text-neutral-400 transition hover:bg-white hover:text-coral-600 dark:hover:bg-neutral-700">
                                            <X className="h-4 w-4" />
                                        </button>
                                    </div>
                                ))}
                                {isEmail && (
                                    <p className="px-1 text-[11px] tabular-nums text-neutral-400">
                                        {t('inbox.attachments_total', {
                                            count: attachments.length,
                                            size: formatBytes(attachments.reduce((sum, a) => sum + a.file.size, 0)),
                                        })}
                                    </p>
                                )}
                            </div>
                        )}

                        {/* Canned reply picker */}
                        {slashMenu.length > 0 && (
                            <div className="mb-2 rounded-xl border border-neutral-200 dark:border-neutral-700 bg-white dark:bg-neutral-900 shadow-lg max-h-48 overflow-y-auto">
                                {slashMenu.map(reply => (
                                    <button key={reply.id} type="button"
                                        onClick={() => { setData('body', renderCannedBody(reply.body, conversation.contact)); setSlashMenu([]); }}
                                        className="w-full text-left px-3 py-2 hover:bg-brand-50 dark:hover:bg-brand-900/20 transition border-b border-neutral-100 dark:border-neutral-800 last:border-0">
                                        <span className="font-mono text-xs font-semibold text-brand-600 dark:text-brand-400 mr-2">/{reply.shortcut}</span>
                                        <span className="text-xs text-neutral-500 line-clamp-1">{reply.body}</span>
                                    </button>
                                ))}
                            </div>
                        )}

                        {/* Canned replies as visible chips.
                            The list was already fetched on mount and already filtered by
                            a "/" prefix in the composer — a feature nobody discovers
                            unless they are told it exists. Same data, same click, just
                            shown. Hidden while the slash menu is open so the two do not
                            stack, and while the WhatsApp window is shut, when free text
                            cannot be sent at all. */}
                        {cannedReplies.length > 0 && slashMenu.length === 0 && !(isWhatsApp && !isWindowOpen) && (
                            <div className="mb-2 flex flex-wrap gap-1.5">
                                {cannedReplies.slice(0, 6).map(reply => (
                                    <button
                                        key={reply.id}
                                        type="button"
                                        title={reply.body}
                                        onClick={() => setData('body', renderCannedBody(reply.body, conversation.contact))}
                                        className="inline-flex max-w-[14rem] items-center gap-1 rounded-full border border-neutral-200 bg-white px-2.5 py-1 text-xs text-neutral-600 transition hover:border-brand-300 hover:bg-brand-50 hover:text-brand-700 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-300 dark:hover:bg-neutral-700"
                                    >
                                        <Zap className="h-3 w-3 shrink-0 text-brand-500" />
                                        <span className="truncate">{reply.shortcut}</span>
                                    </button>
                                ))}
                            </div>
                        )}

                        {/* Toolbar + textarea */}
                        <div className="relative">
                            {/* Emoji picker */}
                            {showEmoji && <EmojiPicker onPick={e => setData('body', (data.body ?? '') + e)} onClose={() => setShowEmoji(false)} />}
                            {/* Template picker */}
                            {showTemplates && (
                                <TemplatePicker
                                    conversationId={conversation.uuid}
                                    onSent={(msg) => {
                                        if (msg) setMessages(prev => prev.some(m => m.id === msg.id) ? prev : [...prev, msg]);
                                        setShowTemplates(false);
                                    }}
                                    onClose={() => setShowTemplates(false)}
                                />
                            )}
                            {/* Product picker (share a store product) */}
                            {showProducts && (
                                <ProductPicker
                                    conversationId={conversation.uuid}
                                    onSent={(msg, err) => {
                                        if (msg) setMessages(prev => prev.some(m => m.id === msg.id) ? prev : [...prev, msg]);
                                        if (err) setSendError(err);
                                        setShowProducts(false);
                                    }}
                                    onClose={() => setShowProducts(false)}
                                />
                            )}

                            <form onSubmit={handleSend} className="rounded-2xl border border-neutral-300 bg-white transition focus-within:border-brand-400 focus-within:ring-2 focus-within:ring-brand-500/20 dark:border-neutral-600 dark:bg-neutral-800">
                                {isEmail && (
                                    <input
                                        value={data.subject}
                                        onChange={e => setData('subject', e.target.value)}
                                        // A lone text input submits its form on Enter, which
                                        // here means sending the mail while still writing the
                                        // subject. Move to the body instead.
                                        onKeyDown={e => {
                                            if (e.key === 'Enter') {
                                                e.preventDefault();
                                                bodyRef.current?.focus();
                                            }
                                        }}
                                        placeholder={emailSubjectPlaceholder}
                                        className="w-full border-0 border-b border-neutral-200 bg-transparent px-4 pb-2 pt-3 text-sm font-medium placeholder-neutral-400 focus:outline-none focus:ring-0 dark:border-neutral-700"
                                    />
                                )}

                                {/* Text first, tools underneath — one field rather than a
                                    toolbar floating above a separate box. */}
                                {isEmail && previewingBody ? (
                                    <div className="min-h-[4.5rem] px-4 pb-1 pt-3 text-sm">
                                        {data.body?.trim()
                                            ? <MarkdownLite content={data.body} />
                                            : <p className="text-neutral-400">{t('inbox.format_preview_empty')}</p>}
                                    </div>
                                ) : (
                                <textarea
                                    ref={bodyRef}
                                    value={data.body}
                                    onChange={e => handleReplyChange(e.target.value)}
                                    onKeyDown={e => {
                                        // Ctrl+B / Ctrl+I before Enter: the browser's own
                                        // bold does nothing in a textarea, so the shortcut
                                        // would otherwise be dead.
                                        const shortcut = isEmail ? formatShortcut(e) : null;
                                        if (shortcut) {
                                            e.preventDefault();
                                            const el = e.currentTarget;
                                            const next = applyFormat(data.body ?? '', el.selectionStart, el.selectionEnd, shortcut);
                                            handleReplyChange(next.value);
                                            requestAnimationFrame(() => { el.focus(); el.setSelectionRange(next.start, next.end); });

                                            return;
                                        }
                                        if (e.key !== 'Enter') {
                                            return;
                                        }

                                        // An email is written in paragraphs, so Enter goes
                                        // to the next line and the button does the sending —
                                        // which is what the button is for. Ctrl+Enter still
                                        // sends, for the hands that expect it. Chat is the
                                        // other way round and stays that way: Enter has sent
                                        // the message there for as long as chat has existed.
                                        if (isEmail) {
                                            if (e.metaKey || e.ctrlKey) { e.preventDefault(); handleSend(e); }

                                            return;
                                        }

                                        if (!e.shiftKey) { e.preventDefault(); handleSend(e); }
                                    }}
                                    placeholder={
                                        isWhatsApp && !isWindowOpen
                                            ? t('inbox.session_closed_placeholder')
                                            : t('inbox.type_message_placeholder')
                                    }
                                    rows={2}
                                    disabled={isWhatsApp && !isWindowOpen && attachments.length === 0}
                                    className="min-h-[3.5rem] w-full resize-none border-0 bg-transparent px-4 pb-1 pt-3 text-sm focus:outline-none focus:ring-0 disabled:cursor-not-allowed disabled:opacity-60"
                                />
                                )}

                                {/* Toolbar */}
                                <div className="flex items-center gap-1 px-2 pb-2">
                                    {isEmail && (
                                        <ComposerFormatting
                                            textareaRef={bodyRef}
                                            value={data.body}
                                            onChange={handleReplyChange}
                                            previewing={previewingBody}
                                            onTogglePreview={() => setPreviewingBody(v => !v)}
                                        />
                                    )}
                                    {/* Emoji */}
                                    <button type="button" onClick={() => setShowEmoji(v => !v)}
                                        title={t('inbox.emoji')}
                                        className={`p-1.5 rounded-lg text-neutral-400 hover:text-neutral-600 hover:bg-neutral-100 dark:hover:bg-neutral-800 transition ${showEmoji ? 'bg-neutral-100 dark:bg-neutral-800 text-neutral-600' : ''}`}>
                                        <Smile className="h-4 w-4" />
                                    </button>
                                    {/* Attachment */}
                                    <button type="button" onClick={() => fileRef.current?.click()}
                                        title={t('inbox.attach_file')}
                                        className="p-1.5 rounded-lg text-neutral-400 hover:text-neutral-600 hover:bg-neutral-100 dark:hover:bg-neutral-800 transition">
                                        <Paperclip className="h-4 w-4" />
                                    </button>
                                    {/* Image */}
                                    <button type="button" onClick={() => { const i = document.createElement('input'); i.type='file'; i.accept='image/*'; i.multiple = isEmail; i.onchange=handleFileChange; i.click(); }}
                                        title={t('inbox.attach_image')}
                                        className="p-1.5 rounded-lg text-neutral-400 hover:text-neutral-600 hover:bg-neutral-100 dark:hover:bg-neutral-800 transition">
                                        <ImageIcon className="h-4 w-4" />
                                    </button>
                                    {/* Share product */}
                                    {hasEcommerceStore && (
                                        <button type="button" onClick={() => setShowProducts(v => !v)}
                                            title={isWhatsApp && !isWindowOpen ? t('inbox.session_closed_reengage') : t('inbox.share_a_product')}
                                            disabled={isWhatsApp && !isWindowOpen}
                                            className={`p-1.5 rounded-lg text-neutral-400 hover:text-neutral-600 hover:bg-neutral-100 dark:hover:bg-neutral-800 transition disabled:opacity-40 disabled:cursor-not-allowed ${showProducts ? 'bg-neutral-100 dark:bg-neutral-800 text-neutral-600' : ''}`}>
                                            <ShoppingBag className="h-4 w-4" />
                                        </button>
                                    )}
                                    {/* WA Template */}
                                    {isWhatsApp && (
                                        <button type="button" onClick={() => setShowTemplates(v => !v)}
                                            title={t('inbox.send_template')}
                                            className={`flex items-center gap-1 p-1.5 rounded-lg text-xs transition ${
                                                !isWindowOpen
                                                    ? 'bg-amber-50 text-amber-600 border border-amber-200 hover:bg-amber-100 dark:bg-amber-900/20 dark:text-amber-400 dark:border-amber-800'
                                                    : 'text-neutral-400 hover:text-neutral-600 hover:bg-neutral-100 dark:hover:bg-neutral-800'
                                            } ${showTemplates ? 'bg-neutral-100 dark:bg-neutral-800' : ''}`}>
                                            <LayoutTemplate className="h-4 w-4" />
                                            {!isWindowOpen && <span className="font-medium">{t('inbox.template')}</span>}
                                        </button>
                                    )}
                                    {/* Hidden file input */}
                                    <input ref={fileRef} type="file" multiple={isEmail} className="hidden" onChange={handleFileChange} />

                                    <button
                                        type="submit"
                                        disabled={sending || (!data.body.trim() && attachments.length === 0) || (isWhatsApp && !isWindowOpen && attachments.length === 0)}
                                        className="ml-auto inline-flex items-center gap-1.5 rounded-xl bg-brand-600 px-4 py-2 text-sm font-semibold text-white transition hover:bg-brand-700 disabled:opacity-50"
                                    >
                                        {sending ? <Loader2 className="h-4 w-4 animate-spin" /> : <><span>{t('inbox.send')}</span><Send className="h-4 w-4" /></>}
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>
                    )}
                </div>

                {/* ── Contact panel (right) ── */}
                <div className="hidden w-64 shrink-0 flex-col overflow-y-auto border-l border-neutral-200 bg-white dark:border-neutral-700 dark:bg-neutral-900 lg:flex">
                    {/* Contact summary */}
                    <div className="border-b border-neutral-100 p-4 text-center dark:border-neutral-800">
                        {/* contacts.avatar_url is appended on every serialisation and has
                            always reached this page — the panel just drew an initial. */}
                        {conversation.contact?.avatar_url ? (
                            <img src={conversation.contact.avatar_url} alt="" className="mx-auto h-14 w-14 rounded-full object-cover" />
                        ) : (
                            <div className="mx-auto flex h-14 w-14 items-center justify-center rounded-full bg-brand-100 text-lg font-bold text-brand-700 dark:bg-brand-900/30 dark:text-brand-300">
                                {contactName[0]?.toUpperCase() ?? '?'}
                            </div>
                        )}
                        <p className="mt-2.5 truncate text-sm font-semibold text-neutral-900 dark:text-neutral-100">{contactName}</p>
                        {conversation.contact?.phone_e164 && (
                            <p className="truncate text-xs tabular-nums text-neutral-400">{conversation.contact.phone_e164}</p>
                        )}

                        <Link
                            href={route('client.contacts.show', conversation.contact?.uuid ?? '')}
                            className="mt-3 inline-flex items-center justify-center gap-1.5 rounded-lg border border-neutral-200 px-3 py-1.5 text-xs font-medium text-neutral-600 transition hover:bg-neutral-50 dark:border-neutral-700 dark:text-neutral-300 dark:hover:bg-neutral-800"
                        >
                            <IdCard className="h-3.5 w-3.5" />
                            {t('inbox.contact_record')}
                        </Link>
                    </div>

                    {/* Details */}
                    <div className="border-b border-neutral-100 p-4 dark:border-neutral-800">
                        <p className="mb-2 text-[10px] font-bold uppercase tracking-wider text-neutral-400">{t('inbox.details')}</p>
                        <div className="space-y-1.5 text-xs">
                            {contactSince && (
                                <div className="flex items-center justify-between gap-2">
                                    <span className="text-neutral-500">{t('inbox.detail_since')}</span>
                                    <span className="truncate font-medium text-neutral-800 dark:text-neutral-200">{contactSince}</span>
                                </div>
                            )}
                            {conversation.contact?.email && (
                                <div className="flex items-center justify-between gap-2">
                                    <span className="text-neutral-500">{t('common.email')}</span>
                                    <span className="truncate font-medium text-neutral-800 dark:text-neutral-200" title={conversation.contact.email}>
                                        {conversation.contact.email}
                                    </span>
                                </div>
                            )}
                            <div className="flex items-center justify-between gap-2">
                                <span className="text-neutral-500">{t('inbox.messages')}</span>
                                <span className="font-medium tabular-nums text-neutral-800 dark:text-neutral-200">{messages.length}</span>
                            </div>
                        </div>
                    </div>

                    {/* Labels / Tags */}
                    {allLabels.length > 0 && (
                        <div className="p-4 border-b border-neutral-100 dark:border-neutral-800">
                            <p className="text-[10px] font-bold uppercase tracking-wider text-neutral-400 mb-2">{t('inbox.tags')}</p>
                            <div className="flex flex-wrap gap-1.5">
                                {allLabels.map(label => {
                                    const active = convLabels.some(l => l.id === label.id);
                                    return (
                                        <button key={label.id} type="button" onClick={() => toggleLabel(label)}
                                            title={active ? t('inbox.remove_label', { name: label.name }) : t('inbox.add_label', { name: label.name })}
                                            className={`inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-[11px] font-medium transition border ${
                                                active ? 'text-white border-transparent' : 'bg-transparent border-current opacity-40 hover:opacity-70'
                                            }`}
                                            style={{ backgroundColor: active ? label.color : undefined, color: active ? 'white' : label.color }}>
                                            {label.name}
                                        </button>
                                    );
                                })}
                            </div>
                        </div>
                    )}

                    {/* Opt-ins */}
                    <div className="p-4 border-b border-neutral-100 dark:border-neutral-800">
                        <p className="text-[10px] font-bold uppercase tracking-wider text-neutral-400 mb-2">{t('inbox.opt_ins')}</p>
                        {[
                            ['whatsapp', 'WhatsApp', conversation.contact?.opt_in_whatsapp],
                            ['sms', 'SMS', conversation.contact?.opt_in_sms],
                            ['email', t('common.email'), conversation.contact?.opt_in_email],
                        ].map(([chKey, k, v]) => (
                            <div key={k} className="flex items-center justify-between text-xs py-1">
                                <span className="flex items-center gap-1.5 text-neutral-500">
                                    <ChannelBrandIcon channel={chKey} className="h-3.5 w-3.5 shrink-0" />{k}
                                </span>
                                <span className={`font-semibold ${v ? 'text-green-500' : 'text-neutral-300 dark:text-neutral-600'}`}>{v ? t('common.yes') : t('common.no')}</span>
                            </div>
                        ))}
                    </div>

                    {/* AI Handover */}
                    <div className="p-4">
                        <p className="text-[10px] font-bold uppercase tracking-wider text-neutral-400 mb-2">{t('inbox.ai_handover')}</p>
                        <div className="flex items-center justify-between">
                            <span className={`inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-xs font-medium ${
                                assignedTo === 'human' ? 'bg-amber-100 text-amber-700 dark:bg-amber-900/30 dark:text-amber-300' : 'bg-green-100 text-green-700 dark:bg-green-900/30 dark:text-green-300'
                            }`}>
                                {assignedTo === 'human' ? <><User className="h-3 w-3" /> {t('inbox.human')}</> : <><Bot className="h-3 w-3" /> {t('inbox.bot')}</>}
                            </span>
                            {assignedTo === 'human'
                                ? <button onClick={() => switchHandover('bot')} className="text-xs text-brand-600 hover:underline">{t('inbox.back_to_bot')}</button>
                                : <button onClick={() => switchHandover('human')} className="text-xs text-amber-600 hover:underline">{t('inbox.take_over')}</button>
                            }
                        </div>
                    </div>
                </div>

            </div>
        </InboxLayout>
    );
}
