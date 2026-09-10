import { useEffect, useMemo, useRef, useState } from 'react';
import { Link, router } from '@inertiajs/react';
import { useTranslation } from 'react-i18next';
import axios from 'axios';
import {
    AlertTriangle,
    ArrowLeft,
    Check,
    Copy,
    Download,
    ExternalLink,
    Layers,
    Loader2,
    Pencil,
    Plus,
    RefreshCw,
    Send,
    Sparkles,
    ThumbsDown,
    ThumbsUp,
} from 'lucide-react';
import {
    Badge,
    Button,
    Card,
    ContactPicker,
    DatePicker,
    ItemPicker,
    LineItemsEditor,
    NudgeBanner,
} from '@/Components/ui';
import ClientLayout from '@/Layouts/ClientLayout';
import { formatLei, normaliseLei } from '@/Utils/money';

/**
 * One offer: the lines, the totals, the client, and the PDF.
 *
 * An offer the agent drafted gains a third column on the left, and only that
 * kind of offer does — `source === 'ai'`. It carries the three things a person
 * needs before they put their name to a price somebody else's software chose:
 * what the customer actually wrote, what the agent took that to mean, and why
 * it picked these products. Everything to the right of it is identical either
 * way, because an AI draft is an ordinary offer and stops being an AI draft the
 * moment a person edits it.
 *
 * The one interaction in that column that matters is "Regenerează". It does not
 * re-read the customer's message — it re-runs from the interpretation as it
 * stands on screen, corrections included. That is what turns "it misunderstood
 * me" from a draft thrown away into a field retyped and five seconds' wait.
 *
 * The totals are never recomputed in this file. The server owns them: it holds
 * the VAT snapshot, the free-shipping threshold and the discount, and a second
 * copy of that arithmetic in JS would disagree with the PDF sooner or later. So
 * the figures shown are the last ones the server sent, and they are dimmed while
 * there are edits it has not seen yet.
 *
 * An offer stops being a draft the moment it leaves the building. From then on
 * it is read-only — a price that changes under a client who already has the PDF
 * is the one thing this screen must never allow.
 */

/** Line keys only need to be unique within this page's lifetime. */
let localLineSeq = 0;
const nextLineKey = () => `new-${++localLineSeq}`;

/**
 * A server line as the editor wants it.
 *
 * `quantity` arrives as a decimal string ("1.000") and the price as an integer
 * number of bani; both are normalised here so that comparing the edited rows
 * against the saved ones is a comparison of like with like.
 */
function toRow(item) {
    return {
        key: `srv-${item.id}`,
        id: item.id,
        catalog_item_id: item.catalog_item_id ?? null,
        name: item.name ?? '',
        unit: item.unit || 'buc',
        quantity: Number(item.quantity ?? 1),
        unit_price_cents: Number(item.unit_price_cents ?? 0),
        line_total_cents: Number(item.line_total_cents ?? 0),
        added_by: item.added_by ?? 'human',
    };
}

/**
 * The catalogue search hands back a decimal price string; the line stores bani.
 *
 * This is a snapshot for display only — the server re-reads the catalogue when
 * the offer is saved, and its number is the one that reaches the PDF.
 */
function centsFromCataloguePrice(price) {
    const raw = normaliseLei(price);
    const value = raw === null ? 0 : Number(raw);

    return Number.isFinite(value) ? Math.round(value * 100) : 0;
}

/**
 * What the editor would put in the total cell of a row that has just appeared.
 *
 * The same thousandths-times-bani arithmetic LineItemsEditor and OfferTotals
 * use, rather than quantity * price in floats: a component that comes in at
 * 0,705 of a unit must not show one figure until somebody touches the row and a
 * different one afterwards. Display only — the server recomputes every line.
 */
function lineTotalCents(quantity, unitPriceCents) {
    const milli = Math.round((Number(quantity) || 0) * 1000);
    const cents = Number(unitPriceCents) || 0;

    return Math.floor((milli * cents + 500) / 1000);
}

/** What the server is being asked to store, so an unchanged offer looks unchanged. */
function signature(rows, contact, validUntil, notes) {
    return JSON.stringify({
        contact: contact ? (contact.id ?? contact.uuid ?? null) : null,
        validUntil: validUntil || '',
        notes: notes || '',
        rows: rows.map((row) => [
            row.catalog_item_id ?? null,
            row.name ?? '',
            row.unit || 'buc',
            Number(row.quantity ?? 0),
            Number(row.unit_price_cents ?? 0),
        ]),
    });
}

const asDateInput = (value) => (value ? String(value).slice(0, 10) : '');

/**
 * A named route, or null when this build does not know it.
 *
 * Ziggy throws for a name it has never heard of, and a throw during render is a
 * blank screen — here, a blank screen on the offer somebody was about to send.
 * The two routes the agent panel posts to belong to the drafting back end; if a
 * deploy ever has this page without them, the panel still shows the customer's
 * message and what was understood, with its buttons off, instead of taking the
 * whole editor down with it. `addBundle` below guards its route the same way.
 */
function safeRoute(name, parameter) {
    try {
        return route(name, parameter);
    } catch {
        return null;
    }
}

/**
 * What the agent understood, as five editable fields.
 *
 * The order is the order a person checks them in: what they want first, then
 * the two numbers that decide whether the offer is even relevant, then the
 * detail. `rows` is how much room each one usually needs, not a limit.
 */
const AI_FIELDS = [
    // Romanian keys, because those are the ones the model answers with and the
    // ones the controller validates — OfferDrafter::INTERPRETATION_FIELDS. Under
    // English names the panel showed five em-dashes, every correction was
    // discarded with a success message on top of it, and Regenerate spent a
    // provider call reproducing the same wrong draft.
    { key: 'cere', label: 'offers.ai_wants', rows: 2 },
    { key: 'buget', label: 'offers.ai_budget', rows: 1 },
    { key: 'termen', label: 'offers.ai_deadline', rows: 1 },
    { key: 'cerinte', label: 'offers.ai_requirements', rows: 3 },
    { key: 'pentru', label: 'offers.ai_for_whom', rows: 2 },
];

/**
 * One interpretation field as text a person can edit.
 *
 * The model is asked for strings, but "cerințe" is the kind of field it returns
 * as a list about a third of the time, and a field that renders "[object
 * Object]" on the screen where somebody is checking a price is worse than one
 * that renders nothing. A list becomes one line each; anything else that is not
 * a scalar is dropped rather than shown.
 */
function asText(value) {
    if (value === null || value === undefined) return '';

    if (Array.isArray(value)) {
        return value
            .map((entry) => (entry === null || typeof entry === 'object' ? '' : String(entry).trim()))
            .filter((entry) => entry !== '')
            .join('\n');
    }

    return typeof value === 'object' ? '' : String(value);
}

/** A textarea back into the list the server keeps. Blank lines are not requirements. */
function toLines(value) {
    return String(value ?? '')
        .split('\n')
        .map((line) => line.trim())
        .filter((line) => line !== '');
}

/** The five fields off an offer, whatever shape the payload arrived in. */
function readInterpretation(offer) {
    const source = offer?.ai?.interpretation ?? {};

    return AI_FIELDS.reduce((acc, field) => ({ ...acc, [field.key]: asText(source[field.key]) }), {});
}

/**
 * One justification line.
 *
 * A bare sentence is used as it is. The other shape the drafter files is one
 * entry per line — the product it chose and the reason it chose it — and that
 * reads better as "Scaun ergonomic — se potrivește bugetului" than as either
 * half on its own.
 */
function reasonText(entry) {
    if (entry === null || entry === undefined) return '';
    if (typeof entry !== 'object') return String(entry).trim();

    const reason = asText(entry.reason ?? entry.text ?? entry.why ?? '');
    const item = asText(entry.item ?? entry.name ?? '');

    if (reason === '') return item;

    return item === '' ? reason : `${item} — ${reason}`;
}

const AI_FIELD_INPUT = 'w-full rounded-lg border border-neutral-300 bg-white px-2.5 py-1.5 text-[13px] text-neutral-900 transition focus:border-brand-500 focus:outline-none focus:ring-2 focus:ring-brand-500/20 dark:border-neutral-600 dark:bg-neutral-800 dark:text-neutral-100';

/** One line of the totals block. */
function TotalRow({ label, value, strong = false, hint = null }) {
    return (
        <div className="flex items-baseline justify-between gap-4 py-1.5">
            <span
                className={
                    strong
                        ? 'text-sm font-semibold text-neutral-900 dark:text-neutral-100'
                        : 'text-sm text-neutral-500 dark:text-neutral-400'
                }
            >
                {label}
                {hint && (
                    <span className="ml-2 text-[11px] font-medium text-brand-600 dark:text-brand-400">{hint}</span>
                )}
            </span>
            <span
                className={`tabular-nums ${
                    strong
                        ? 'text-base font-semibold text-neutral-900 dark:text-neutral-100'
                        : 'text-sm text-neutral-700 dark:text-neutral-300'
                }`}
            >
                {value}
            </span>
        </div>
    );
}

/** A labelled block in the right rail. */
function RailField({ label, children }) {
    return (
        <div>
            <span className="text-xs font-medium text-neutral-500 dark:text-neutral-400">{label}</span>
            {children}
        </div>
    );
}

export default function Show({ offer, settings, seller }) {
    const { t, i18n } = useTranslation();

    // A draft is editable; anything that has been sent, accepted, refused or has
    // expired is a record of what the client was told.
    const readOnly = offer.status !== 'draft';

    const [items, setItems] = useState(() => (offer.items ?? []).map(toRow));
    const [contact, setContact] = useState(offer.contact ?? null);
    const [validUntil, setValidUntil] = useState(asDateInput(offer.valid_until));
    const [notes, setNotes] = useState(offer.notes ?? '');
    const [picking, setPicking] = useState(false);
    const [pickingBundle, setPickingBundle] = useState(false);
    const [bundleLoading, setBundleLoading] = useState(false);
    const [bundleNotice, setBundleNotice] = useState('');
    const [saving, setSaving] = useState(false);
    const [savedAt, setSavedAt] = useState(0);
    const [linkCopiedAt, setLinkCopiedAt] = useState(0);

    // The link the client opens. The server signs it and only puts it on the
    // prop for an offer a guest is allowed to see, so its absence — not a rule
    // restated here — is what hides the action. The status check beside it is
    // this screen's own belt: a draft's rail must never grow a share button,
    // whatever a future payload decides to carry.
    const publicUrl = offer.status === 'sent' ? (offer.public_url ?? '') : '';

    // Everything the agent left behind. An offer somebody built by hand has no
    // `ai` block at all, and every branch below is off for it.
    const ai = offer.source === 'ai' ? (offer.ai ?? {}) : null;
    const isAiDraft = ai !== null;

    const [interpretation, setInterpretation] = useState(() => readInterpretation(offer));
    const [editingInterpretation, setEditingInterpretation] = useState(false);
    const [savingInterpretation, setSavingInterpretation] = useState(false);
    const [interpretationError, setInterpretationError] = useState('');
    const [regenerating, setRegenerating] = useState(false);

    // The server is the last word on every field here, so a fresh version of the
    // offer replaces what is on screen rather than being merged into it. React's
    // own "adjust state when a prop changes" pattern: during render rather than
    // in an effect, so the previous version is never painted first.
    const [syncedOffer, setSyncedOffer] = useState(offer);
    if (syncedOffer !== offer) {
        setSyncedOffer(offer);
        setItems((offer.items ?? []).map(toRow));
        setContact(offer.contact ?? null);
        setValidUntil(asDateInput(offer.valid_until));
        setNotes(offer.notes ?? '');

        // The one exception. While a draft is being regenerated this page polls,
        // and a poll that landed mid-sentence used to wipe the correction the
        // person was in the middle of typing — the correction the regeneration
        // is waiting on. Their words stay theirs until they save or cancel.
        if (!editingInterpretation) {
            setInterpretation(readInterpretation(offer));
        }
    }

    useEffect(() => {
        if (savedAt === 0) return undefined;
        const timer = setTimeout(() => setSavedAt(0), 4000);

        return () => clearTimeout(timer);
    }, [savedAt]);

    useEffect(() => {
        if (linkCopiedAt === 0) return undefined;
        const timer = setTimeout(() => setLinkCopiedAt(0), 3000);

        return () => clearTimeout(timer);
    }, [linkCopiedAt]);

    /**
     * Copy the public link.
     *
     * The confirmation waits for the write to resolve. A browser that refuses
     * the clipboard — an insecure origin, a permission denied — must not be
     * told the link is on it, because the seller's next action is to paste into
     * a chat with a client and send whatever comes out.
     */
    const copyPublicUrl = () => {
        const clipboard = navigator.clipboard;
        if (publicUrl === '' || !clipboard) return;

        clipboard.writeText(publicUrl).then(
            () => setLinkCopiedAt(Date.now()),
            () => undefined,
        );
    };

    const savedSignature = useMemo(
        () => signature((offer.items ?? []).map(toRow), offer.contact ?? null, asDateInput(offer.valid_until), offer.notes ?? ''),
        [offer],
    );
    const dirty = !readOnly && signature(items, contact, validUntil, notes) !== savedSignature;

    // The totals on screen describe the offer the server last saw. While there
    // are unsaved edits they describe something else, and they say so.
    const stale = dirty || saving;

    const formatDate = (value) => {
        if (!value) return '';
        const parsed = new Date(value);

        return Number.isNaN(parsed.getTime())
            ? ''
            : parsed.toLocaleDateString(i18n.language === 'ro' ? 'ro-RO' : undefined, {
                day: '2-digit',
                month: 'short',
                year: 'numeric',
            });
    };

    /** A message needs its hour as well as its day: three of them arrive minutes apart. */
    const formatDateTime = (value) => {
        if (!value) return '';
        const parsed = new Date(value);

        return Number.isNaN(parsed.getTime())
            ? ''
            : parsed.toLocaleString(i18n.language === 'ro' ? 'ro-RO' : undefined, {
                day: '2-digit',
                month: 'short',
                hour: '2-digit',
                minute: '2-digit',
            });
    };

    // ── The agent's column ───────────────────────────────────────────────────
    const messages = Array.isArray(ai?.messages) ? ai.messages : [];
    const reasons = (Array.isArray(ai?.reasons) ? ai.reasons : []).map(reasonText).filter((line) => line !== '');

    /**
     * What the agent threw away, and what it wants checked.
     *
     * The server has been building both of these all along and nothing drew
     * them: a hallucinated product was dropped and the person was not told, and
     * an out-of-stock line was flagged in the data and shown on screen as an
     * ordinary line. This is the first question somebody approving an offer
     * asks, so it goes above the reasoning, not below it.
     */
    const notice = (entry) => {
        const label = entry?.key ? t(entry.key) : '';
        const name = String(entry?.name ?? '').trim();

        return [label, name].filter((part) => part !== '').join(' — ');
    };

    const dropped = (Array.isArray(ai?.dropped) ? ai.dropped : []).map(notice).filter((line) => line !== '');
    const warnings = (Array.isArray(ai?.warnings) ? ai.warnings : []).map(notice).filter((line) => line !== '');
    const attempt = ai?.attempt ?? null;
    const attemptStatus = attempt?.status ?? null;

    // The drafting job runs on the 'ai' queue, so "Regenerează" returns long
    // before there is anything new to look at. Queued is the honest state.
    const drafting = regenerating || attemptStatus === 'queued';

    // `reason` is a translation key the drafter chose, never a provider's own
    // words. A key this build has no Romanian for says nothing rather than
    // printing "offers.ai_reason_no_catalog" at somebody.
    const failureReason = attempt?.reason ? t(attempt.reason, { defaultValue: '' }) : '';

    // Which lines the agent chose. `added_by` is written once and never rewritten,
    // so a line whose price a person has since corrected still says where it came
    // from — that is the honest answer to "did it pick this, or did I?".
    const aiLineNames = isAiDraft
        ? items.filter((row) => row.added_by === 'ai').map((row) => (row.name ?? '').trim()).filter((name) => name !== '')
        : [];

    const inboxUrl = ai?.conversation_uuid ? safeRoute('client.inbox.show', ai.conversation_uuid) : null;
    const regenerateUrl = isAiDraft ? safeRoute('client.offers.regenerate', offer.uuid) : null;
    const interpretationUrl = isAiDraft ? safeRoute('client.offers.interpretation', offer.uuid) : null;

    // While the job is out, ask for the offer again every few seconds — the same
    // shape the campaign screen uses while a send is in flight.
    //
    // Capped at three minutes, and the count is a ref rather than state because
    // nothing on the screen shows it: a job that died leaves its row queued for
    // ever, and a tab left open on it would otherwise poll the server until
    // somebody closed it, which is exactly the sort of thing nobody notices.
    const pollsRef = useRef(0);
    useEffect(() => {
        if (attemptStatus !== 'queued') {
            pollsRef.current = 0;

            return undefined;
        }

        const timer = setInterval(() => {
            pollsRef.current += 1;

            if (pollsRef.current > 36) {
                clearInterval(timer);

                return;
            }

            router.reload({ only: ['offer'], preserveScroll: true, preserveState: true });
        }, 5000);

        return () => clearInterval(timer);
    }, [attemptStatus]);

    const editInterpretation = () => {
        setInterpretation(readInterpretation(offer));
        setInterpretationError('');
        setEditingInterpretation(true);
    };

    const cancelInterpretation = () => {
        setInterpretation(readInterpretation(offer));
        setInterpretationError('');
        setEditingInterpretation(false);
    };

    const patchInterpretation = (key, value) => setInterpretation((prev) => ({ ...prev, [key]: value }));

    /** The first thing the server objected to, in its own words — they are already Romanian. */
    const firstError = (bag) => {
        const first = Object.values(bag ?? {})[0];

        return Array.isArray(first) ? first[0] : first;
    };

    const saveInterpretation = () => {
        if (!interpretationUrl || savingInterpretation) return;
        setSavingInterpretation(true);
        setInterpretationError('');
        router.put(
            interpretationUrl,
            // `cerinte` is a list on the server and a textarea here, so the
            // lines go back the way they came. Sent as one string it fails the
            // array rule and is dropped — with a success message on top of it.
            { interpretation: { ...interpretation, cerinte: toLines(interpretation.cerinte) } },
            {
                preserveScroll: true,
                onSuccess: () => setEditingInterpretation(false),
                onError: (bag) => setInterpretationError(firstError(bag) ?? t('offers.ai_failed')),
                onFinish: () => setSavingInterpretation(false),
            },
        );
    };

    /**
     * Draft it again, from the interpretation on the screen.
     *
     * The corrected fields go with the request rather than being saved first and
     * read back, so there is no version of this where somebody fixes "buget" and
     * the agent quotes against the old one because a save was forgotten. The
     * server persists what it is sent and drafts from that.
     */
    const regenerate = () => {
        if (readOnly || !regenerateUrl || drafting) return;
        setRegenerating(true);
        setInterpretationError('');
        router.post(
            regenerateUrl,
            { interpretation },
            {
                preserveScroll: true,
                onSuccess: () => setEditingInterpretation(false),
                onError: (bag) => setInterpretationError(firstError(bag) ?? t('offers.ai_failed')),
                onFinish: () => setRegenerating(false),
            },
        );
    };

    const addFreeLine = () => {
        if (readOnly) return;
        setItems((prev) => [
            ...prev,
            {
                key: nextLineKey(),
                id: null,
                catalog_item_id: null,
                name: '',
                unit: 'buc',
                quantity: 1,
                unit_price_cents: 0,
                line_total_cents: 0,
                added_by: 'human',
            },
        ]);
    };

    // Name, unit and price are copied onto the line, not referenced: a catalogue
    // price that changes tomorrow must leave today's offer alone.
    const addFromCatalogue = (row) => {
        if (readOnly || !row) return;
        const unitPrice = centsFromCataloguePrice(row.price);
        setItems((prev) => [
            ...prev,
            {
                key: nextLineKey(),
                id: null,
                catalog_item_id: row.id ?? null,
                name: row.name ?? '',
                unit: row.unit || 'buc',
                quantity: 1,
                unit_price_cents: unitPrice,
                line_total_cents: unitPrice,
                added_by: 'human',
            },
        ]);
        setPicking(false);
    };

    /**
     * An assembly, unpacked onto the offer.
     *
     * A bundle never becomes a line of its own — it has no price to quote, and
     * the server refuses one that tries. What the customer reads has to be what
     * the customer receives, so the composition is fetched and each component is
     * appended as its own row, at the quantity the bundle says: two chairs in
     * the set is one line of two, not two lines of one.
     *
     * The failure is shown rather than swallowed. A composition that could not
     * be read must leave the person looking at a message, not at an offer that
     * silently gained nothing.
     */
    const addBundle = (row) => {
        if (readOnly || !row || bundleLoading) return;

        let url;
        try {
            // The route binds the catalogue item by uuid; the picker row carries
            // its id as well, so whichever the endpoint was given is sent.
            url = route('client.catalog.components', row.uuid ?? row.id);
        } catch {
            setBundleNotice(t('offers.bundle_empty'));

            return;
        }

        setBundleNotice('');
        setBundleLoading(true);

        axios.get(url)
            .then((response) => {
                const components = Array.isArray(response.data) ? response.data : [];

                if (components.length === 0) {
                    setBundleNotice(t('offers.bundle_empty'));

                    return;
                }

                setItems((prev) => [
                    ...prev,
                    ...components.map((component) => {
                        const unitPrice = centsFromCataloguePrice(component.price);
                        const quantity = Number(component.quantity) > 0 ? Number(component.quantity) : 1;

                        return {
                            key: nextLineKey(),
                            id: null,
                            catalog_item_id: component.id ?? null,
                            name: component.name ?? '',
                            unit: component.unit || 'buc',
                            quantity,
                            unit_price_cents: unitPrice,
                            line_total_cents: lineTotalCents(quantity, unitPrice),
                            added_by: 'human',
                        };
                    }),
                ]);
                setPickingBundle(false);
            })
            .catch((error) => {
                // The server's own sentence when it sent one — it is already in
                // the person's language — and the plain notice otherwise.
                const message = error?.response?.data?.message;
                setBundleNotice(typeof message === 'string' && message !== '' ? message : t('offers.bundle_empty'));
            })
            .finally(() => setBundleLoading(false));
    };

    const [errors, setErrors] = useState({});

    // One explicit save of the whole offer. No autosave: an offer is a number a
    // person stands behind, and it is saved when they say it is.
    const save = () => {
        if (readOnly || saving) return;
        setSaving(true);
        router.put(
            route('client.offers.update', offer.uuid),
            {
                // A contact picked from the search carries its id; one that came
                // back on this page carries only its uuid. Send whichever exists.
                contact_id: contact?.id ?? null,
                contact_uuid: contact?.uuid ?? null,
                valid_until: validUntil || null,
                notes,
                items: items.map((row, index) => ({
                    id: typeof row.id === 'number' ? row.id : null,
                    catalog_item_id: row.catalog_item_id ?? null,
                    name: row.name ?? '',
                    unit: row.unit || 'buc',
                    quantity: row.quantity,
                    unit_price_cents: row.unit_price_cents,
                    position: index,
                    added_by: row.added_by ?? 'human',
                })),
            },
            {
                preserveScroll: true,
                onSuccess: () => { setSavedAt(Date.now()); setErrors({}); },
                // Without this the save simply did nothing: a line with no name,
                // or a quantity the arithmetic cannot hold, was refused by the
                // server and the page said nothing at all — and every other edit
                // in the same payload went with it.
                onError: (bag) => setErrors(bag ?? {}),
                onFinish: () => setSaving(false),
            },
        );
    };

    const [message, setMessage] = useState('');
    const [attachPdf, setAttachPdf] = useState(true);
    const [sending, setSending] = useState(false);

    // Deliberately blocked while there are unsaved edits: sending renders the
    // PDF from what is stored, so an offer sent with a pending change would put
    // one set of prices on screen and another in the customer's hands.
    const sendOffer = () => {
        if (sending) return;
        setSending(true);
        router.post(
            route('client.offers.send', offer.uuid),
            { message, attach_pdf: attachPdf },
            {
                preserveScroll: true,
                onError: (bag) => setErrors(bag ?? {}),
                onFinish: () => setSending(false),
            },
        );
    };

    const decide = (decision) => {
        router.post(route('client.offers.decision', offer.uuid), { decision }, { preserveScroll: true });
    };

    // Measured the way the server measures it: after the discount, not before.
    // A 10% discount on 310 lei drops the basket back under a 300 lei threshold,
    // and the server charged the delivery while this hint still promised it free.
    const freeShippingApplied =
        Number(settings?.free_shipping_cents ?? 0) > 0 &&
        Number(offer.shipping_cents ?? 0) === 0 &&
        (Number(offer.subtotal_cents ?? 0) - Number(offer.discount_cents ?? 0))
            >= Number(settings?.free_shipping_cents ?? 0);

    const vatRate = offer.vat_rate === null || offer.vat_rate === undefined ? null : Number(offer.vat_rate);
    const vatLabel = vatRate ? `${t('offers.vat')} (${vatRate}%)` : t('offers.vat');

    const canDecide = readOnly && !offer.decision;

    return (
        <ClientLayout title={offer.number}>
            <div className="space-y-4">
                {/* ── Header ─────────────────────────────────────────────── */}
                <div className="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <Link
                            href={route('client.offers.index')}
                            className="inline-flex items-center gap-1 text-xs font-medium text-neutral-500 transition hover:text-neutral-800 dark:text-neutral-400 dark:hover:text-neutral-200"
                        >
                            <ArrowLeft className="h-3.5 w-3.5" />
                            {t('offers.back')}
                        </Link>
                        <p className="mt-2 text-[11px] font-medium uppercase tracking-wide text-neutral-400 dark:text-neutral-500">
                            {t('offers.number')}
                        </p>
                        <h1 className="text-xl font-semibold tabular-nums text-neutral-900 dark:text-neutral-100">
                            {offer.number}
                        </h1>
                        {offer.created_at && (
                            <p className="mt-0.5 text-xs text-neutral-500 dark:text-neutral-400">
                                {t('offers.created_on', { date: formatDate(offer.created_at) })}
                            </p>
                        )}
                    </div>

                    <div className="flex flex-wrap items-center gap-2">
                        {/* Said once, at the top, next to the number: this price was
                            proposed by software and is nobody's word until it is sent. */}
                        {isAiDraft && (
                            <Badge variant="brand" className="gap-1">
                                <Sparkles className="h-3 w-3" aria-hidden />
                                {t('offers.ai_draft_badge')}
                            </Badge>
                        )}
                        {offer.decision === 'accepted' && (
                            <Badge variant="success">{t('offers.decision_accepted')}</Badge>
                        )}
                        {offer.decision === 'refused' && <Badge variant="danger">{t('offers.decision_refused')}</Badge>}
                        {savedAt > 0 && (
                            <span className="inline-flex items-center gap-1 text-xs font-medium text-brand-600 dark:text-brand-400">
                                <Check className="h-3.5 w-3.5" />
                                {t('offers.saved')}
                            </span>
                        )}
                        {!readOnly && (
                            <Button onClick={save} disabled={saving || !dirty}>
                                {saving ? t('offers.saving') : t('offers.save')}
                            </Button>
                        )}
                    </div>
                </div>

                {readOnly && <NudgeBanner tone="accent" title={t('offers.read_only')} />}

                {/* Three columns for a draft the agent wrote, two for one a person
                    wrote, and one column on anything narrower than a desktop —
                    the left column is context for a decision, so it belongs above
                    the offer when it cannot sit beside it. `minmax(0, 1fr)` on the
                    middle track so the lines table scrolls inside its own card
                    instead of pushing the rails off the screen. */}
                <div
                    className={`grid grid-cols-1 gap-6 ${
                        isAiDraft
                            ? 'xl:grid-cols-[20rem_minmax(0,1fr)_22rem]'
                            : 'xl:grid-cols-[1fr_22rem]'
                    }`}
                >
                    {/* ── Left: what was asked, what was understood, why these ── */}
                    {isAiDraft && (
                        <div className="space-y-4">
                            {/* An attempt that produced nothing. There is no offer to
                                carry this, which is why it is kept on its own row —
                                and the customer was sent nothing at all. */}
                            {attemptStatus === 'failed' && (
                                <div className="rounded-xl border border-coral-200 bg-coral-50 px-4 py-3 dark:border-coral-800/50 dark:bg-coral-900/20">
                                    <p className="flex items-start gap-2 text-sm font-semibold text-coral-900 dark:text-coral-200">
                                        <AlertTriangle className="mt-0.5 h-4 w-4 shrink-0" aria-hidden />
                                        {t('offers.ai_failed')}
                                    </p>
                                    {failureReason !== '' && (
                                        <p className="mt-1 pl-6 text-[13px] text-coral-800/80 dark:text-coral-200/70">
                                            {failureReason}
                                        </p>
                                    )}
                                </div>
                            )}

                            {drafting && (
                                <div className="flex items-center gap-2 rounded-xl border border-brand-200 bg-brand-50 px-4 py-3 text-[13px] font-semibold text-brand-800 dark:border-brand-800/50 dark:bg-brand-900/20 dark:text-brand-200">
                                    <Loader2 className="h-4 w-4 shrink-0 animate-spin" aria-hidden />
                                    {t('offers.regenerating')}
                                </div>
                            )}

                            {/* Verbatim, in the order they arrived, with the hour on
                                each one. Not a summary: the summary is the block
                                underneath, and the whole point of having both is
                                being able to check the second against the first. */}
                            {(messages.length > 0 || inboxUrl) && (
                                <Card>
                                    <Card.Header
                                        title={t('offers.ai_customer_request')}
                                        action={inboxUrl ? (
                                            <Link
                                                href={inboxUrl}
                                                className="inline-flex shrink-0 items-center gap-1 text-xs font-medium text-brand-700 transition hover:text-brand-800 hover:underline dark:text-brand-300 dark:hover:text-brand-200"
                                            >
                                                <ExternalLink className="h-3.5 w-3.5" aria-hidden />
                                                {t('offers.open_in_inbox')}
                                            </Link>
                                        ) : null}
                                    />

                                    {messages.length > 0 && (
                                        <ol className="space-y-3 border-l-2 border-warm-border pl-3 dark:border-neutral-700">
                                            {messages.map((entry, index) => (
                                                <li key={entry.id ?? index}>
                                                    {/* Plain text. What a stranger typed into
                                                        WhatsApp is never markup here. */}
                                                    <p className="whitespace-pre-wrap break-words text-[13px] leading-relaxed text-neutral-800 dark:text-neutral-200">
                                                        {entry.body}
                                                    </p>
                                                    {entry.at && (
                                                        <p className="mt-1 text-[11px] tabular-nums text-neutral-400 dark:text-neutral-500">
                                                            {formatDateTime(entry.at)}
                                                        </p>
                                                    )}
                                                </li>
                                            ))}
                                        </ol>
                                    )}
                                </Card>
                            )}

                            {/* The correctable part. Five fields, because those are the
                                five things that decide which products and how many —
                                and because a person who can see a wrong one can fix it
                                faster than they can explain it. */}
                            <Card>
                                <Card.Header
                                    title={t('offers.ai_understood')}
                                    action={!readOnly && !editingInterpretation ? (
                                        <button
                                            type="button"
                                            onClick={editInterpretation}
                                            className="inline-flex shrink-0 items-center gap-1 text-xs font-medium text-brand-700 transition hover:text-brand-800 hover:underline dark:text-brand-300 dark:hover:text-brand-200"
                                        >
                                            <Pencil className="h-3.5 w-3.5" aria-hidden />
                                            {t('offers.ai_correct_it')}
                                        </button>
                                    ) : null}
                                />

                                <dl className="space-y-3">
                                    {AI_FIELDS.map((field) => (
                                        <div key={field.key}>
                                            <dt className="text-[11px] font-semibold uppercase tracking-wide text-neutral-400 dark:text-neutral-500">
                                                {t(field.label)}
                                            </dt>
                                            <dd className="mt-1">
                                                {editingInterpretation ? (
                                                    <textarea
                                                        aria-label={t(field.label)}
                                                        rows={field.rows}
                                                        value={interpretation[field.key] ?? ''}
                                                        onChange={(e) => patchInterpretation(field.key, e.target.value)}
                                                        className={AI_FIELD_INPUT}
                                                    />
                                                ) : (
                                                    <p className="whitespace-pre-wrap break-words text-[13px] leading-relaxed text-neutral-800 dark:text-neutral-200">
                                                        {interpretation[field.key] || '—'}
                                                    </p>
                                                )}
                                            </dd>
                                        </div>
                                    ))}
                                </dl>

                                {editingInterpretation && (
                                    <div className="mt-4 flex flex-wrap items-center gap-2">
                                        <Button
                                            size="sm"
                                            onClick={saveInterpretation}
                                            disabled={savingInterpretation || !interpretationUrl}
                                        >
                                            {savingInterpretation ? t('offers.saving') : t('offers.save_interpretation')}
                                        </Button>
                                        <Button
                                            size="sm"
                                            variant="ghost"
                                            onClick={cancelInterpretation}
                                            disabled={savingInterpretation}
                                        >
                                            {t('common.cancel')}
                                        </Button>
                                    </div>
                                )}

                                {interpretationError !== '' && (
                                    <p className="mt-2 text-xs font-medium text-coral-700 dark:text-coral-300">
                                        {interpretationError}
                                    </p>
                                )}
                            </Card>

                            {(dropped.length > 0 || warnings.length > 0) && (
                                <Card>
                                    <Card.Header title={t('offers.ai_check_this')} />
                                    <ul className="space-y-1.5 text-[13px] leading-relaxed">
                                        {dropped.map((line, index) => (
                                            <li key={`d${index}`} className="flex gap-2 text-coral-800 dark:text-coral-300">
                                                <span aria-hidden>✕</span>
                                                <span className="break-words">{line}</span>
                                            </li>
                                        ))}
                                        {warnings.map((line, index) => (
                                            <li key={`w${index}`} className="flex gap-2 text-accent-800 dark:text-accent-300">
                                                <span aria-hidden>!</span>
                                                <span className="break-words">{line}</span>
                                            </li>
                                        ))}
                                    </ul>
                                </Card>
                            )}

                            {reasons.length > 0 && (
                                <Card>
                                    <Card.Header title={t('offers.ai_why_these')} />
                                    <ul className="list-outside list-disc space-y-1.5 pl-4 text-[13px] leading-relaxed text-neutral-700 dark:text-neutral-300">
                                        {reasons.map((line, index) => (
                                            <li key={index} className="break-words">{line}</li>
                                        ))}
                                    </ul>
                                </Card>
                            )}

                            {/* Under the interpretation on purpose: by the time somebody
                                reaches this button they have read what it understood,
                                and pressing it re-runs from that, not from the message. */}
                            {!readOnly && (
                                <Button
                                    variant="secondary"
                                    className="w-full"
                                    onClick={regenerate}
                                    disabled={drafting || !regenerateUrl}
                                >
                                    {drafting ? (
                                        <Loader2 className="mr-2 h-4 w-4 animate-spin" aria-hidden />
                                    ) : (
                                        <RefreshCw className="mr-2 h-4 w-4" aria-hidden />
                                    )}
                                    {drafting ? t('offers.regenerating') : t('offers.regenerate')}
                                </Button>
                            )}
                        </div>
                    )}

                    {/* ── Main: the lines and what they add up to ────────── */}
                    <Card padding={false}>
                        <div className="flex flex-wrap items-center justify-between gap-3 border-b border-warm-border px-5 py-4 dark:border-neutral-800">
                            <h2 className="text-sm font-semibold text-neutral-900 dark:text-neutral-100">
                                {t('offers.items_in_offer')}
                            </h2>

                            {!readOnly && (
                                <div className="flex items-center gap-2">
                                    <Button
                                        size="sm"
                                        variant="secondary"
                                        onClick={() => { setPickingBundle(false); setPicking((open) => !open); }}
                                    >
                                        <Plus className="mr-1 h-3.5 w-3.5" />
                                        {t('offers.add_item')}
                                    </Button>
                                    {/* One assembly at a time, and only one search box
                                        open at a time: two identical fields side by side
                                        asking different questions is how the wrong thing
                                        gets typed into the wrong one. */}
                                    <Button
                                        size="sm"
                                        variant="secondary"
                                        onClick={() => { setPicking(false); setBundleNotice(''); setPickingBundle((open) => !open); }}
                                    >
                                        <Layers className="mr-1 h-3.5 w-3.5" />
                                        {t('offers.add_bundle')}
                                    </Button>
                                    <Button size="sm" variant="ghost" onClick={addFreeLine}>
                                        {t('offers.add_free_line')}
                                    </Button>
                                </div>
                            )}
                        </div>

                        {/* The picker is a search field, not a menu: it opens in the
                            page rather than over it, and closes once a line is added. */}
                        {!readOnly && picking && (
                            <div className="border-b border-warm-border px-5 py-3 dark:border-neutral-800">
                                <ItemPicker endpoint="client.catalog.search" onPick={addFromCatalogue} />
                            </div>
                        )}

                        {/* The same field, asking the catalogue for assemblies only.
                            What it appends is the composition, never the assembly. */}
                        {!readOnly && pickingBundle && (
                            <div className="border-b border-warm-border px-5 py-3 dark:border-neutral-800">
                                <ItemPicker
                                    endpoint="client.catalog.search"
                                    type="bundle"
                                    placeholder={t('offers.search_bundle')}
                                    onPick={addBundle}
                                />
                                {bundleLoading && (
                                    <p className="mt-2 text-xs text-neutral-500 dark:text-neutral-400">{t('common.loading')}</p>
                                )}
                                {!bundleLoading && bundleNotice !== '' && (
                                    <p className="mt-2 text-xs font-medium text-coral-700 dark:text-coral-300">{bundleNotice}</p>
                                )}
                            </div>
                        )}

                        <div className="px-5 py-4">
                            {/* No `onAdd`: both ways of adding a line live in the
                                header above, next to each other, rather than one
                                there and an identical one at the foot of the table. */}
                            {Object.keys(errors).length > 0 && (
                            <div className="mb-3 rounded-xl border border-coral-200 bg-coral-50 px-4 py-3 text-sm text-coral-800 dark:border-coral-900/40 dark:bg-coral-900/20 dark:text-coral-300">
                                <ul className="list-inside list-disc space-y-1">
                                    {Object.entries(errors).map(([field, message]) => (
                                        <li key={field}>{Array.isArray(message) ? message[0] : message}</li>
                                    ))}
                                </ul>
                            </div>
                        )}

                        <LineItemsEditor
                                items={items}
                                onChange={setItems}
                                currency={offer.currency}
                                readOnly={readOnly}
                            />

                            {/* Which of the lines above the agent put there, named.
                                The table itself has no column for provenance, and a
                                sixth column on a table a person edits prices in would
                                cost more than it tells them — so it is said once,
                                underneath, where it is read as a caption rather than
                                as data. */}
                            {aiLineNames.length > 0 && (
                                <p className="mt-3 flex flex-wrap items-center gap-x-2 gap-y-1 text-xs text-neutral-500 dark:text-neutral-400">
                                    <Badge variant="brand" size="sm" className="gap-1">
                                        <Sparkles className="h-3 w-3" aria-hidden />
                                        {t('offers.proposed_by_ai')}
                                    </Badge>
                                    <span className="min-w-0 break-words">{aiLineNames.join(' · ')}</span>
                                </p>
                            )}
                        </div>

                        <div className="border-t border-warm-border px-5 py-4 dark:border-neutral-800">
                            <div
                                aria-busy={saving}
                                className={`ml-auto w-full max-w-xs divide-y divide-neutral-100 transition-opacity dark:divide-neutral-800 ${
                                    stale ? 'opacity-50' : ''
                                }`}
                            >
                                <TotalRow label={t('offers.subtotal')} value={formatLei(offer.subtotal_cents)} />

                                {Number(offer.discount_cents ?? 0) > 0 && (
                                    <TotalRow
                                        label={offer.discount_label || t('offers.discount')}
                                        value={`- ${formatLei(offer.discount_cents)}`}
                                    />
                                )}

                                {(Number(offer.shipping_cents ?? 0) > 0 || freeShippingApplied) && (
                                    <TotalRow
                                        label={t('offers.shipping')}
                                        hint={freeShippingApplied ? t('offers.free_shipping_hint') : null}
                                        value={formatLei(offer.shipping_cents)}
                                    />
                                )}

                                {(Number(offer.vat_cents ?? 0) > 0 || vatRate !== null) && (
                                    <TotalRow label={vatLabel} value={formatLei(offer.vat_cents)} />
                                )}

                                <TotalRow label={t('offers.total')} value={formatLei(offer.total_cents)} strong />
                            </div>
                        </div>
                    </Card>

                    {/* ── Rail: who it is for, until when, and the PDF ───── */}
                    <div className="space-y-4">
                        <Card>
                            <Card.Header title={t('offers.client')} />

                            {!readOnly && (
                                <ContactPicker
                                    contact={
                                        contact
                                            ? {
                                                // The picker labels a contact from `company` and the
                                                // name parts; the offer prop carries one joined name.
                                                ...contact,
                                                first_name: contact.first_name ?? contact.name ?? '',
                                            }
                                            : null
                                    }
                                    onPick={setContact}
                                />
                            )}

                            {contact && (
                                <div className="mt-3 space-y-1 text-sm">
                                    {readOnly && (
                                        <p className="font-medium text-neutral-900 dark:text-neutral-100">
                                            {contact.name || contact.company}
                                        </p>
                                    )}
                                    {contact.company && (
                                        <p className="text-neutral-600 dark:text-neutral-300">{contact.company}</p>
                                    )}
                                    {contact.email && (
                                        <p className="truncate text-neutral-500 dark:text-neutral-400">{contact.email}</p>
                                    )}
                                    {contact.phone_e164 && (
                                        <p className="tabular-nums text-neutral-500 dark:text-neutral-400">
                                            {contact.phone_e164}
                                        </p>
                                    )}
                                </div>
                            )}
                        </Card>

                        <Card className="space-y-4">
                            <RailField label={t('offers.valid_until')}>
                                <DatePicker
                                    value={validUntil}
                                    onChange={setValidUntil}
                                    disabled={readOnly}
                                    className="mt-1 w-full"
                                />
                            </RailField>

                            <RailField label={t('offers.notes')}>
                                <textarea
                                    value={notes}
                                    onChange={(e) => setNotes(e.target.value)}
                                    disabled={readOnly}
                                    rows={4}
                                    className="mt-1 w-full rounded-lg border border-neutral-300 bg-white px-3 py-2 text-sm focus:border-brand-500 focus:outline-none focus:ring-1 focus:ring-brand-500 disabled:opacity-60 dark:border-neutral-600 dark:bg-neutral-800 dark:text-neutral-100"
                                />
                                <p className="mt-1 text-xs text-neutral-500 dark:text-neutral-400">
                                    {t('offers.notes_hint')}
                                </p>
                            </RailField>
                        </Card>

                        <Card className="space-y-2">
                            <a
                                href={route('client.offers.pdf', offer.uuid)}
                                target="_blank"
                                rel="noopener noreferrer"
                                className="inline-flex w-full items-center justify-center gap-2 rounded-xl border border-warm-border px-4 py-2 text-[13px] font-semibold text-warm-gray-900 transition-colors hover:bg-warm-gray-50 dark:border-neutral-600 dark:text-neutral-200 dark:hover:bg-neutral-800"
                            >
                                <Download className="h-4 w-4" />
                                {t('offers.download_pdf')}
                            </a>

                            {/* The same offer as a web page, for the client who cannot open
                                a PDF on a phone. Two actions and not one: the seller looks
                                at what the client will see, then pastes the link into the
                                conversation they are already having with them. */}
                            {publicUrl !== '' && (
                                <div className="space-y-2">
                                    <a
                                        href={publicUrl}
                                        target="_blank"
                                        rel="noopener noreferrer"
                                        className="inline-flex w-full items-center justify-center gap-2 rounded-xl border border-warm-border px-4 py-2 text-[13px] font-semibold text-warm-gray-900 transition-colors hover:bg-warm-gray-50 dark:border-neutral-600 dark:text-neutral-200 dark:hover:bg-neutral-800"
                                    >
                                        <ExternalLink className="h-4 w-4" />
                                        {t('offers.view_as_client')}
                                    </a>

                                    <Button variant="ghost" size="sm" className="w-full" onClick={copyPublicUrl}>
                                        {linkCopiedAt > 0 ? (
                                            <Check className="mr-1.5 h-3.5 w-3.5 text-brand-600 dark:text-brand-400" />
                                        ) : (
                                            <Copy className="mr-1.5 h-3.5 w-3.5" />
                                        )}
                                        {linkCopiedAt > 0 ? t('offers.link_copied') : t('offers.copy_link')}
                                    </Button>

                                    {/* Said before the paste, not after: a link handed on has
                                        no second gate behind it, and a group chat forwards. */}
                                    <p className="text-[11px] text-neutral-500 dark:text-neutral-400">
                                        {t('offers.link_is_public')}
                                    </p>
                                </div>
                            )}

                            {/* Sending. A draft with a client goes out on the thread they
                                wrote from; the message is written here because the seller is
                                the one who signs it, not a template. */}
                            {!readOnly && offer.contact && (
                                <div className="mt-3 space-y-2 rounded-xl border border-brand-200 bg-brand-50/60 p-3 dark:border-brand-900/40 dark:bg-brand-900/10">
                                    <label htmlFor="offer-message" className="block text-xs font-semibold text-brand-800 dark:text-brand-300">
                                        {t('offers.message_to_client')}
                                    </label>
                                    <textarea
                                        id="offer-message"
                                        rows={4}
                                        value={message}
                                        onChange={(e) => setMessage(e.target.value)}
                                        placeholder={t('offers.message_placeholder')}
                                        className="w-full rounded-lg border border-neutral-300 px-3 py-2 text-sm dark:border-neutral-600 dark:bg-neutral-800"
                                    />
                                    <label className="flex items-center gap-2 text-xs text-neutral-600 dark:text-neutral-400">
                                        <input
                                            type="checkbox"
                                            checked={attachPdf}
                                            onChange={(e) => setAttachPdf(e.target.checked)}
                                            className="rounded border-neutral-300 text-brand-600"
                                        />
                                        {t('offers.attach_pdf')}
                                    </label>
                                    <Button
                                        size="sm"
                                        className="w-full"
                                        disabled={sending || dirty || !message.trim()}
                                        onClick={sendOffer}
                                    >
                                        <Send className="mr-1 h-3.5 w-3.5" />
                                        {sending ? t('offers.sending') : t('offers.approve_and_send')}
                                    </Button>
                                    {dirty && (
                                        <p className="text-[11px] text-accent-700 dark:text-accent-400">
                                            {t('offers.save_before_sending')}
                                        </p>
                                    )}
                                </div>
                            )}

                            {canDecide && (
                                <div className="flex gap-2 pt-1">
                                    <Button
                                        size="sm"
                                        variant="secondary"
                                        className="flex-1"
                                        onClick={() => decide('accepted')}
                                    >
                                        <ThumbsUp className="mr-1 h-3.5 w-3.5" />
                                        {t('offers.mark_accepted')}
                                    </Button>
                                    <Button
                                        size="sm"
                                        variant="outline"
                                        className="flex-1"
                                        onClick={() => decide('refused')}
                                    >
                                        <ThumbsDown className="mr-1 h-3.5 w-3.5" />
                                        {t('offers.mark_refused')}
                                    </Button>
                                </div>
                            )}
                        </Card>

                        {/* Who the offer is from. Without a company profile the PDF
                            carries no fiscal identity, which is worth saying once,
                            here, rather than letting the file come out anonymous. */}
                        {seller ? (
                            <Card>
                                <Card.Header title={t('offers.seller')} />
                                <div className="space-y-1 text-sm text-neutral-600 dark:text-neutral-300">
                                    <p className="font-medium text-neutral-900 dark:text-neutral-100">
                                        {seller.legal_name}
                                    </p>
                                    {seller.cui && <p className="tabular-nums">{seller.cui}</p>}
                                    {seller.trade_register_no && <p className="tabular-nums">{seller.trade_register_no}</p>}
                                    {seller.address && <p>{seller.address}</p>}
                                    {seller.iban && (
                                        <p className="tabular-nums">
                                            {seller.iban}
                                            {seller.bank_name ? ` · ${seller.bank_name}` : ''}
                                        </p>
                                    )}
                                    {seller.vat_rate !== null && seller.vat_rate !== undefined && (
                                        <p>
                                            {t('offers.vat')} {Number(seller.vat_rate)}%
                                        </p>
                                    )}
                                </div>
                            </Card>
                        ) : (
                            <NudgeBanner
                                tone="accent"
                                title={t('offers.seller')}
                                description={t('offers.no_seller_profile')}
                                action={{
                                    label: t('offers.complete_profile'),
                                    href: route('client.offers.settings'),
                                }}
                            />
                        )}
                    </div>
                </div>
            </div>
        </ClientLayout>
    );
}
