import { describe, it, expect, vi } from 'vitest';
import { render, screen, fireEvent } from '@testing-library/react';
import ro from '@/locales/ro.json';
import en from '@/locales/en.json';
import {
    describeNotification,
    KNOWN_NOTIFICATION_TYPES,
} from '@/Utils/notificationDescriptor';
import { relativeTime, dayBucket } from '@/Utils/relativeTime';

/**
 * A translator backed by the real locale file, so a missing or misspelt key
 * fails the test instead of silently rendering the key itself — which is how
 * the bell dropdown ended up printing "subscription started" in production.
 */
function translator(bundle, locale) {
    const plural = new Intl.PluralRules(locale);

    return (key, params = {}) => {
        const lookup = (k) => k.split('.').reduce((node, part) => (node == null ? undefined : node[part]), bundle);

        let value = lookup(key);

        if (value === undefined && params.count !== undefined) {
            value = lookup(`${key}_${plural.select(params.count)}`);
        }

        if (typeof value !== 'string') {
            throw new Error(`cheie de traducere lipsă: ${key}`);
        }

        return value.replace(/\{\{(\w+)\}\}/g, (_, name) => String(params[name] ?? ''));
    };
}

/** One realistic payload per type, matching what the PHP toArray() stores. */
const PAYLOADS = {
    new_message: { type: 'new_message', contact_name: 'Ana Popescu', snippet: 'Bună ziua, aveți loc joi?', url: '/app/inbox/12' },
    mention: { type: 'mention', mentioned_by: 'Radu', snippet: 'poți să preiei tu conversația?', url: '/app/inbox/12' },
    conversation_assigned: { type: 'conversation_assigned', assigned_by: 'Radu', contact_name: 'Ana Popescu', url: '/app/inbox/12' },
    handover: { type: 'handover', contact_name: 'Ana Popescu', reason: 'user_request', url: '/app/inbox/12' },
    campaign_completed: { type: 'campaign_completed', name: 'Reduceri de toamnă', sent: 412, failed: 3, url: '/app/broadcasts/campaigns/9' },
    automation_failed: { type: 'automation_failed', automation: 'Bun venit clienți noi', error: 'Timeout la pasul 3' },
    subscription_started: { type: 'subscription_started', plan_name: 'Pro', billing_cycle: 'month', url: '/app/billing' },
    subscription_renewed: { type: 'subscription_renewed', plan_name: 'Pro', amount: '149.00', currency: 'RON', url: '/app/billing' },
    subscription_cancelled: { type: 'subscription_cancelled', plan_name: 'Pro', ends_at: '2026-10-01', url: '/app/billing' },
    subscription_expired: { type: 'subscription_expired', plan_name: 'Pro', url: '/app/billing' },
    trial_ending: { type: 'trial_ending', plan_name: 'Pro', days_remaining: 3, url: '/app/billing' },
    plan_changed: { type: 'plan_changed', old_plan_name: 'Start', new_plan_name: 'Pro', url: '/app/billing' },
    billing_failed: { type: 'billing_failed', amount: '149.00', currency: 'RON', url: '/app/billing' },
    user_welcome: { type: 'user_welcome', url: '/app/dashboard' },
    workspace_export_ready: { type: 'workspace_export_ready', download_url: '/exports/9.zip' },
};

describe('descriptorul de notificări', () => {
    it('acoperă exact tipurile trimise de backend', () => {
        expect(new Set(Object.keys(PAYLOADS))).toEqual(new Set(KNOWN_NOTIFICATION_TYPES));
    });

    it.each(KNOWN_NOTIFICATION_TYPES)('%s primește titlu propriu, nu unul generic', (type) => {
        const t = translator(ro, 'ro');
        const { title, known } = describeNotification(PAYLOADS[type], t, 'ro');

        expect(known).toBe(true);
        expect(title).toBeTruthy();
        expect(title).not.toBe(t('notif.generic_title'));
        // The old bug: the raw slug leaking into the UI.
        expect(title).not.toContain('_');
        expect(title.toLowerCase()).not.toBe(type.replace(/_/g, ' '));
    });

    it.each(KNOWN_NOTIFICATION_TYPES)('%s se traduce și în engleză', (type) => {
        const t = translator(en, 'en');
        expect(() => describeNotification(PAYLOADS[type], t, 'en')).not.toThrow();
    });

    it('scrie a doua linie pentru notificările de abonament, care erau goale', () => {
        const t = translator(ro, 'ro');

        expect(describeNotification(PAYLOADS.subscription_started, t, 'ro').body).toContain('Pro');
        expect(describeNotification(PAYLOADS.user_welcome, t, 'ro').body).toBeTruthy();
    });

    it('folosește forma de plural corectă în română', () => {
        const t = translator(ro, 'ro');
        const body = (days) => describeNotification({ ...PAYLOADS.trial_ending, days_remaining: days }, t, 'ro').body;

        expect(body(1)).toContain('o zi');
        expect(body(3)).toContain('3 zile');
        expect(body(25)).toContain('25 de zile');
    });

    it('scoate linkul de deschidere, inclusiv pentru export', () => {
        const t = translator(ro, 'ro');

        expect(describeNotification(PAYLOADS.new_message, t, 'ro').url).toBe('/app/inbox/12');
        expect(describeNotification(PAYLOADS.workspace_export_ready, t, 'ro').url).toBe('/exports/9.zip');
        expect(describeNotification(PAYLOADS.automation_failed, t, 'ro').url).toBeNull();
    });

    it('rezistă la un tip necunoscut și la un payload gol', () => {
        const t = translator(ro, 'ro');

        const unknown = describeNotification({ type: 'ceva_nou', message: 'Text' }, t, 'ro');
        expect(unknown.known).toBe(false);
        expect(unknown.title).toBe(t('notif.generic_title'));

        expect(() => describeNotification(undefined, t, 'ro')).not.toThrow();
        expect(() => describeNotification({ type: 'new_message' }, t, 'ro')).not.toThrow();
    });

    it('taie textul scris de client la o singură linie', () => {
        const t = translator(ro, 'ro');
        const long = 'a'.repeat(400);
        const { body } = describeNotification({ type: 'new_message', contact_name: 'Ana', snippet: long }, t, 'ro');

        expect(body.length).toBeLessThan(120);
    });
});

describe('timp relativ', () => {
    const now = Date.parse('2026-09-08T12:00:00Z');

    it('formatează în română', () => {
        expect(relativeTime('2026-09-08T11:57:00Z', 'ro', now)).toBe('acum 3 minute');
        expect(relativeTime('2026-09-08T10:00:00Z', 'ro', now)).toBe('acum 2 ore');
    });

    it('renunță peste o săptămână, ca să se poată afișa data', () => {
        expect(relativeTime('2026-08-19T12:00:00Z', 'ro', now)).toBeNull();
    });

    it('nu se sparge la o dată invalidă', () => {
        expect(relativeTime('nu-i o dată', 'ro', now)).toBeNull();
    });

    it('grupează pe zile', () => {
        expect(dayBucket(new Date(now).toISOString(), now)).toBe('today');
        expect(dayBucket('2026-08-01T12:00:00Z', now)).toBe('earlier');
    });
});

// ─── the rendered row ───────────────────────────────────────────────────────
// Mocked here rather than in setup.jsx so the descriptor suites above keep
// passing their own translator explicitly.
vi.mock('react-i18next', () => ({
    useTranslation: () => ({
        t: (key, params = {}) => {
            const value = key.split('.').reduce((n, part) => (n == null ? undefined : n[part]), roBundle);
            const resolved = typeof value === 'string'
                ? value
                : roBundle.notif[`${key.split('.')[1]}_${new Intl.PluralRules('ro').select(params.count ?? 1)}`];
            if (typeof resolved !== 'string') throw new Error(`cheie lipsă: ${key}`);
            return resolved.replace(/\{\{(\w+)\}\}/g, (_, n) => String(params[n] ?? ''));
        },
        i18n: { language: 'ro' },
    }),
}));

const roBundle = ro;

const { default: NotificationItem } = await import('@/Components/NotificationItem');

describe('rândul de notificare', () => {
    const base = {
        id: 'abc',
        data: PAYLOADS.subscription_started,
        read_at: null,
        created_at: new Date(Date.now() - 3 * 60 * 1000).toISOString(),
    };

    it('afișează titlul în română, nu slugul', () => {
        render(<NotificationItem notification={base} />);

        expect(screen.getByText('Abonament activat')).toBeInTheDocument();
        expect(screen.queryByText(/subscription/i)).not.toBeInTheDocument();
    });

    it('afișează a doua linie și timpul relativ', () => {
        render(<NotificationItem notification={base} />);

        expect(screen.getByText('Abonamentul Pro este activ.')).toBeInTheDocument();
        expect(screen.getByText('acum 3 minute')).toBeInTheDocument();
    });

    it('deschide linkul și marchează citit la click', () => {
        const onOpen = vi.fn();
        const onMarkRead = vi.fn();
        render(<NotificationItem notification={base} onOpen={onOpen} onMarkRead={onMarkRead} />);

        fireEvent.click(screen.getByRole('button', { name: 'Abonament activat' }));

        expect(onMarkRead).toHaveBeenCalledWith('abc');
        expect(onOpen).toHaveBeenCalledWith('/app/billing');
    });

    it('o notificare citită fără link nu mai e interactivă', () => {
        render(<NotificationItem notification={{
            ...base,
            read_at: new Date().toISOString(),
            data: PAYLOADS.automation_failed,
        }} />);

        expect(screen.queryByRole('button')).not.toBeInTheDocument();
    });

    it('acțiunile nu sunt imbricate în zona clicabilă', () => {
        render(<NotificationItem notification={base} onOpen={vi.fn()} onMarkRead={vi.fn()} onDelete={vi.fn()} />);

        const rowButton = screen.getByRole('button', { name: 'Abonament activat' });
        expect(rowButton.querySelector('button')).toBeNull();
        expect(screen.getByRole('button', { name: 'Marchează ca citit' })).toBeInTheDocument();
        expect(screen.getByRole('button', { name: 'Șterge notificarea' })).toBeInTheDocument();
    });

    it('cade pe data absolută pentru ceva mai vechi de o săptămână', () => {
        render(<NotificationItem
            notification={{ ...base, created_at: '2026-01-15T09:30:00Z' }}
            timezone="Europe/Bucharest"
        />);

        expect(screen.queryByText(/acum/)).not.toBeInTheDocument();
        expect(screen.getByText(/2026/)).toBeInTheDocument();
    });
});
