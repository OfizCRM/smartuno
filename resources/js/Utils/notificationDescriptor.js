import {
    AlertTriangle,
    ArrowRightLeft,
    AtSign,
    Bell,
    CalendarX,
    CheckCircle2,
    Clock,
    CreditCard,
    Download,
    Headset,
    MessageCircle,
    PartyPopper,
    RefreshCw,
    Send,
    UserPlus,
    XCircle,
} from 'lucide-react';

/**
 * One description of a notification, shared by every surface that renders one.
 *
 * There were three: the bell dropdown printed the raw `type` slug, the full
 * page printed the PHP class name, and the live toast knew six of the fifteen
 * types. They disagreed because each carried its own half-finished mapping.
 * This is the only mapping now — add a type here and all three pick it up.
 *
 * Titles and bodies are rebuilt from the payload's structured fields rather
 * than read from `data.message`, which the backend writes in English at send
 * time. That also means rows already in the database render in Romanian
 * without a migration.
 */

/** Colour role, resolved to classes by the component that draws the row. */
export const TONE = {
    BRAND: 'brand',
    ACCENT: 'accent',
    DANGER: 'danger',
    NEUTRAL: 'neutral',
};

/**
 * Trim customer-written text (message bodies, note snippets) to one line.
 * It stays a plain string and is rendered as text — never as HTML.
 */
function snippet(value, max = 90) {
    const text = String(value ?? '').replace(/\s+/g, ' ').trim();

    if (! text) {
        return '';
    }

    return text.length > max ? `${text.slice(0, max - 1)}…` : text;
}

/** "1.234,00 lei" style amount, falling back to the raw value. */
function money(amount, currency, locale) {
    const value = Number(amount);

    if (! Number.isFinite(value)) {
        return [amount, currency].filter(Boolean).join(' ');
    }

    try {
        return new Intl.NumberFormat(locale, {
            style: 'currency',
            currency: String(currency || 'RON').toUpperCase(),
        }).format(value);
    } catch {
        return `${value} ${currency ?? ''}`.trim();
    }
}

/**
 * type -> icon, colour role, and how to build its two lines.
 *
 * `body` receives the stored payload plus { t, locale } and returns a plain
 * string, or '' when the payload has nothing worth a second line.
 */
const DESCRIPTORS = {
    new_message: {
        icon: MessageCircle,
        tone: TONE.BRAND,
        title: (d, { t }) => t('notif.new_message_title'),
        body: (d) => (d.contact_name ? `${d.contact_name}: ${snippet(d.snippet)}` : snippet(d.snippet)),
    },
    mention: {
        icon: AtSign,
        tone: TONE.BRAND,
        title: (d, { t }) => t('notif.mention_title'),
        body: (d) => (d.mentioned_by ? `${d.mentioned_by}: ${snippet(d.snippet)}` : snippet(d.snippet)),
    },
    conversation_assigned: {
        icon: UserPlus,
        tone: TONE.BRAND,
        title: (d, { t }) => t('notif.conversation_assigned_title'),
        body: (d, { t }) => t('notif.conversation_assigned_body', {
            by: d.assigned_by ?? t('notif.someone'),
            contact: d.contact_name ?? t('notif.unknown_contact'),
        }),
    },
    handover: {
        icon: Headset,
        tone: TONE.ACCENT,
        title: (d, { t }) => t('notif.handover_title'),
        body: (d, { t }) => t('notif.handover_body', { contact: d.contact_name ?? t('notif.unknown_contact') }),
    },
    campaign_completed: {
        icon: Send,
        tone: TONE.BRAND,
        title: (d, { t }) => t('notif.campaign_completed_title'),
        body: (d, { t }) => t('notif.campaign_completed_body', {
            name: d.name ?? '',
            sent: Number(d.sent ?? 0),
            failed: Number(d.failed ?? 0),
        }),
    },
    automation_failed: {
        icon: AlertTriangle,
        tone: TONE.DANGER,
        title: (d, { t }) => t('notif.automation_failed_title'),
        body: (d) => (d.automation ? `${d.automation}: ${snippet(d.error)}` : snippet(d.error)),
    },
    subscription_started: {
        icon: CheckCircle2,
        tone: TONE.BRAND,
        title: (d, { t }) => t('notif.subscription_started_title'),
        body: (d, { t }) => t('notif.subscription_started_body', { plan: d.plan_name ?? '' }),
    },
    subscription_renewed: {
        icon: RefreshCw,
        tone: TONE.BRAND,
        title: (d, { t }) => t('notif.subscription_renewed_title'),
        body: (d, { t, locale }) => (d.amount
            ? t('notif.subscription_renewed_body_amount', { plan: d.plan_name ?? '', amount: money(d.amount, d.currency, locale) })
            : t('notif.subscription_renewed_body', { plan: d.plan_name ?? '' })),
    },
    subscription_cancelled: {
        icon: XCircle,
        tone: TONE.NEUTRAL,
        title: (d, { t }) => t('notif.subscription_cancelled_title'),
        body: (d, { t }) => t('notif.subscription_cancelled_body', { plan: d.plan_name ?? '' }),
    },
    subscription_expired: {
        icon: CalendarX,
        tone: TONE.DANGER,
        title: (d, { t }) => t('notif.subscription_expired_title'),
        body: (d, { t }) => t('notif.subscription_expired_body', { plan: d.plan_name ?? '' }),
    },
    trial_ending: {
        icon: Clock,
        tone: TONE.ACCENT,
        title: (d, { t }) => t('notif.trial_ending_title'),
        body: (d, { t }) => t('notif.trial_ending_body', {
            plan: d.plan_name ?? '',
            count: Number(d.days_remaining ?? 0),
        }),
    },
    plan_changed: {
        icon: ArrowRightLeft,
        tone: TONE.BRAND,
        title: (d, { t }) => t('notif.plan_changed_title'),
        body: (d, { t }) => t('notif.plan_changed_body', {
            from: d.old_plan_name ?? '',
            to: d.new_plan_name ?? '',
        }),
    },
    billing_failed: {
        icon: CreditCard,
        tone: TONE.DANGER,
        title: (d, { t }) => t('notif.billing_failed_title'),
        body: (d, { t, locale }) => t('notif.billing_failed_body', { amount: money(d.amount, d.currency, locale) }),
    },
    user_welcome: {
        icon: PartyPopper,
        tone: TONE.BRAND,
        title: (d, { t }) => t('notif.user_welcome_title'),
        body: (d, { t }) => t('notif.user_welcome_body'),
    },
    workspace_export_ready: {
        icon: Download,
        tone: TONE.BRAND,
        title: (d, { t }) => t('notif.workspace_export_ready_title'),
        body: (d, { t }) => t('notif.workspace_export_ready_body'),
    },
};

/** Every type this module knows how to render. */
export const KNOWN_NOTIFICATION_TYPES = Object.keys(DESCRIPTORS);

/**
 * Turn a stored notification payload into everything needed to draw a row.
 *
 * An unknown type — a notification added later, or an old row from before a
 * rename — still renders as a readable generic entry rather than a slug.
 */
export function describeNotification(data, t, locale = 'ro') {
    const payload = data ?? {};
    const descriptor = DESCRIPTORS[payload.type];

    if (! descriptor) {
        return {
            title: t('notif.generic_title'),
            body: snippet(payload.message ?? ''),
            icon: Bell,
            tone: TONE.NEUTRAL,
            url: payload.url ?? payload.download_url ?? null,
            known: false,
        };
    }

    const context = { t, locale };

    return {
        title: descriptor.title(payload, context),
        body: descriptor.body(payload, context),
        icon: descriptor.icon,
        tone: descriptor.tone,
        url: payload.url ?? payload.download_url ?? null,
        known: true,
    };
}
