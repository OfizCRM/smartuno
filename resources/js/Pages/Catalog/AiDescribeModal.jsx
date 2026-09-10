import { router } from '@inertiajs/react';
import { AlertCircle, Ban, Check, CheckSquare, Loader2, Sparkles, Square } from 'lucide-react';
import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { toast } from 'sonner';
import { Button, Modal, Skeleton } from '@/Components/ui';

/**
 * "Completează cu AI" — the model drafts, the human decides.
 *
 * The rule this screen exists to enforce: nothing the model wrote reaches the
 * catalogue until somebody ticked it. So the modal is two halves — one call out
 * to `catalog.ai.describe`, which writes nothing and returns proposals, and then
 * a second call to `catalog.ai.apply` carrying only the cards that are still
 * ticked and only the tags that are still kept. Closing the modal at any point
 * leaves the catalogue exactly as it was.
 *
 * The vocabulary is the AI post planner's — the tinted approved card, the
 * checkbox, the select-all, the count on the confirm button — because that is
 * the one place in the product where a person already reviews AI output, and a
 * second dialect of the same idea is a second thing to learn. Its two-step
 * wizard layout does not transfer: there is no brief to write here. The nudge
 * banner was the brief, so the call starts the moment the modal opens.
 *
 * Failure is shown, never swallowed. One LLM call for ten products is not
 * instant and it is not free, so a provider error becomes the server's own
 * Romanian sentence on screen with a retry beside it, rather than an empty list
 * that looks like "the AI had no ideas".
 */

/** catalog_item_tags.label is string(64); anything longer is a sentence, not a tag. */
const TAG_MAX_LENGTH = 64;

/** Six per colour is already more than a person will read on a chip row. */
const TAGS_PER_KIND = 6;

/** One or two sentences, per the prompt. The cap is a guard, not a target. */
const DESCRIPTION_MAX = 600;

/**
 * The proposals arrive as JSON that a language model shaped, so they are
 * re-checked here even though the server hardened them first: a duplicate label
 * would be refused by the unique index on (catalog_item_id, kind, label), and an
 * over-long one truncated by the column. Better to show the person what will
 * actually be saved than to let the row decide silently.
 */
function normaliseTags(value) {
    const seen = new Set();

    return (Array.isArray(value) ? value : [])
        .map(entry => (typeof entry === 'string' ? entry.trim().slice(0, TAG_MAX_LENGTH) : ''))
        .filter(label => {
            if (label === '') return false;
            const key = label.toLocaleLowerCase('ro');
            if (seen.has(key)) return false;
            seen.add(key);

            return true;
        })
        .slice(0, TAGS_PER_KIND)
        .map(label => ({ label, keep: true }));
}

function normaliseProposal(raw) {
    const itemId = Number(raw?.item_id);
    if (! Number.isInteger(itemId) || itemId <= 0) return null;

    const description = typeof raw?.description === 'string'
        ? raw.description.trim().slice(0, DESCRIPTION_MAX)
        : '';
    const fits = normaliseTags(raw?.fits);
    const excludes = normaliseTags(raw?.excludes);

    return {
        item_id: itemId,
        name: typeof raw?.name === 'string' ? raw.name : '',
        description,
        fits,
        excludes,
        // An item the model refused to invent facts about starts unticked. It is
        // still shown — the person may want to type the sentence themselves —
        // but it will not ride along on a select-all and save a blank.
        approved: description !== '' || fits.length > 0 || excludes.length > 0,
    };
}

/** What is left of a card once the edits and the dropped chips are taken out. */
function hasContent(draft) {
    return draft.description.trim() !== ''
        || draft.fits.some(tag => tag.keep)
        || draft.excludes.some(tag => tag.keep);
}

/**
 * The sentence to put on screen when a call fails.
 *
 * The server's own message wins, because it is the one that knows whether the
 * provider timed out, the key is missing or the batch was too large — and it is
 * already Romanian. The generic key is the last resort, not the first.
 */
function serverMessage(payload, fallback) {
    if (typeof payload?.error === 'string' && payload.error.trim() !== '') return payload.error;
    if (typeof payload?.message === 'string' && payload.message.trim() !== '') return payload.message;

    const firstField = payload?.errors && Object.values(payload.errors)[0];
    if (Array.isArray(firstField) && typeof firstField[0] === 'string') return firstField[0];

    return fallback;
}

/**
 * One suggested tag.
 *
 * Green is "propose it to these people", coral is "do not propose it to these" —
 * the same two colours the item page uses for the same two sets, because they
 * are one concept with opposite sign. Clicking keeps or drops the tag; a dropped
 * one stays visible and struck through so the person can put it back without
 * running the call again.
 */
function TagChip({ label, kind, keep, onToggle }) {
    const kept = kind === 'fits'
        ? 'border-brand-300 bg-brand-50 text-brand-800 dark:border-brand-700 dark:bg-brand-900/30 dark:text-brand-200'
        : 'border-coral-300 bg-coral-50 text-coral-800 dark:border-coral-800 dark:bg-coral-900/25 dark:text-coral-200';
    const dropped = 'border-neutral-200 bg-white text-neutral-400 line-through dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-500';
    const Icon = kind === 'fits' ? Check : Ban;

    return (
        <button
            type="button"
            aria-pressed={keep}
            onClick={onToggle}
            className={`inline-flex max-w-full items-center gap-1 rounded-full border px-2.5 py-1 text-xs font-medium transition ${keep ? kept : dropped}`}
        >
            <Icon className="h-3 w-3 shrink-0" aria-hidden />
            <span className="truncate">{label}</span>
        </button>
    );
}

/** One product's proposal: tick it, edit the sentence, drop the chips you disagree with. */
function ProposalCard({ draft, onApprove, onDescribe, onToggleTag }) {
    const { t } = useTranslation();
    const tags = [
        ...draft.fits.map((tag, index) => ({ ...tag, kind: 'fits', index })),
        ...draft.excludes.map((tag, index) => ({ ...tag, kind: 'excludes', index })),
    ];

    return (
        <div
            className={`rounded-xl border p-4 transition ${
                draft.approved
                    ? 'border-brand-400 bg-brand-50/50 dark:border-brand-600 dark:bg-brand-900/10'
                    : 'border-neutral-200 bg-white opacity-60 dark:border-neutral-700 dark:bg-neutral-900'
            }`}
        >
            <div className="flex items-start gap-3">
                <button
                    type="button"
                    role="checkbox"
                    aria-checked={draft.approved}
                    aria-label={draft.name}
                    onClick={onApprove}
                    className="mt-0.5 shrink-0 text-brand-600 dark:text-brand-400"
                >
                    {draft.approved
                        ? <CheckSquare className="h-4 w-4" aria-hidden />
                        : <Square className="h-4 w-4 text-neutral-400" aria-hidden />}
                </button>

                <div className="min-w-0 flex-1 space-y-3">
                    <p className="truncate text-sm font-semibold text-neutral-900 dark:text-neutral-100">
                        {draft.name}
                    </p>

                    <textarea
                        value={draft.description}
                        onChange={e => onDescribe(e.target.value)}
                        rows={3}
                        maxLength={DESCRIPTION_MAX}
                        className="w-full resize-none rounded-lg border border-neutral-200 bg-white px-3 py-2 text-sm text-neutral-900 focus:outline-none focus:ring-2 focus:ring-brand-500 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-100"
                    />

                    {tags.length > 0 && (
                        <div>
                            <p className="mb-1.5 text-[11px] font-semibold uppercase tracking-wider text-neutral-400">
                                {t('catalog.ai_suggested')}
                            </p>
                            <div className="flex flex-wrap gap-1.5">
                                {tags.map(tag => (
                                    <TagChip
                                        key={`${tag.kind}-${tag.index}`}
                                        label={tag.label}
                                        kind={tag.kind}
                                        keep={tag.keep}
                                        onToggle={() => onToggleTag(tag.kind, tag.index)}
                                    />
                                ))}
                            </div>
                        </div>
                    )}
                </div>
            </div>
        </div>
    );
}

export default function AiDescribeModal({ itemIds = [], onClose }) {
    const { t } = useTranslation();

    const [drafting, setDrafting] = useState(true);
    const [saving, setSaving] = useState(false);
    const [error, setError] = useState('');
    const [drafts, setDrafts] = useState([]);

    // Two guards around one expensive call: `started` keeps React's development
    // double-mount from buying a second batch of tokens, and the abort controller
    // stops a closed modal from setting state on a component that is gone.
    const started = useRef(false);
    const aborter = useRef(null);

    const headers = () => ({
        'Content-Type': 'application/json',
        Accept: 'application/json',
        'X-Requested-With': 'XMLHttpRequest',
        // Read at call time, not at mount: the token is refreshed on every
        // Inertia navigation and a stale one is a 419 on the expensive call.
        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content ?? '',
    });

    const describe = useCallback(async () => {
        aborter.current?.abort();
        const controller = new AbortController();
        aborter.current = controller;

        setError('');
        setDrafts([]);
        setDrafting(true);

        try {
            const response = await fetch(route('client.catalog.ai.describe'), {
                method: 'POST',
                headers: headers(),
                // The ids come from the rows on screen. When the page could not
                // name any — the list is on another page, or the column is not
                // in the prop — the key is left off entirely rather than sent
                // empty, so the server picks the batch itself if it can and
                // answers with its own validation message if it cannot.
                body: JSON.stringify(itemIds.length > 0 ? { item_ids: itemIds } : {}),
                signal: controller.signal,
            });

            const payload = await response.json().catch(() => null);

            if (! response.ok) {
                setError(serverMessage(payload, t('catalog.ai_failed')));

                return;
            }

            const proposals = Array.isArray(payload?.proposals) ? payload.proposals : [];
            setDrafts(proposals.map(normaliseProposal).filter(Boolean));
        } catch (failure) {
            if (failure?.name === 'AbortError') return;
            setError(t('catalog.ai_failed'));
        } finally {
            if (! controller.signal.aborted) setDrafting(false);
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [itemIds]);

    useEffect(() => {
        if (! started.current) {
            started.current = true;
            describe();
        }

        return () => aborter.current?.abort();
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, []);

    const update = (itemId, patch) => {
        setDrafts(current => current.map(draft => (
            draft.item_id === itemId ? { ...draft, ...patch } : draft
        )));
    };

    const toggleTag = (itemId, kind, index) => {
        setDrafts(current => current.map(draft => (
            draft.item_id === itemId
                ? {
                    ...draft,
                    [kind]: draft[kind].map((tag, i) => (i === index ? { ...tag, keep: ! tag.keep } : tag)),
                }
                : draft
        )));
    };

    // Ticked and still carrying something. A card whose sentence was emptied and
    // whose chips were all dropped has nothing to write, so it does not count
    // towards the button and it is not sent.
    const approved = useMemo(
        () => drafts.filter(draft => draft.approved && hasContent(draft)),
        [drafts],
    );

    const allApproved = drafts.length > 0 && drafts.every(draft => draft.approved);

    // Only ever ticks. There is one word for this control — "selectează tot" —
    // and a button that says it while doing the opposite is worse than no
    // button; unticking is what the checkbox on each card is for.
    const selectAll = () => setDrafts(current => current.map(draft => ({ ...draft, approved: true })));

    const apply = async () => {
        if (approved.length === 0) return;

        setError('');
        setSaving(true);

        try {
            const response = await fetch(route('client.catalog.ai.apply'), {
                method: 'POST',
                headers: headers(),
                body: JSON.stringify({
                    proposals: approved.map(draft => ({
                        item_id: draft.item_id,
                        description: draft.description.trim(),
                        fits: draft.fits.filter(tag => tag.keep).map(tag => tag.label),
                        excludes: draft.excludes.filter(tag => tag.keep).map(tag => tag.label),
                    })),
                }),
            });

            const payload = await response.json().catch(() => null);

            if (! response.ok) {
                setError(serverMessage(payload, t('catalog.ai_failed')));

                return;
            }

            toast.success(t('catalog.saved'));
            onClose();
            // The nudge counts what is still missing and the rows now carry a
            // description, so both have to come back from the server. Nothing
            // else on the page changed, so nothing else is refetched.
            router.reload({ only: ['items', 'counts', 'stats'], preserveScroll: true });
        } catch {
            setError(t('catalog.ai_failed'));
        } finally {
            setSaving(false);
        }
    };

    const busy = drafting || saving;

    return (
        <Modal show onClose={busy ? () => {} : onClose} closeable={! busy} maxWidth="2xl">
            <Modal.Header
                title={t('catalog.ai_modal_title')}
                onClose={onClose}
                showClose={! busy}
            />

            <Modal.Body className="max-h-[65vh] space-y-4 overflow-y-auto">
                <p className="text-sm text-neutral-600 dark:text-neutral-400">
                    {t('catalog.ai_modal_hint')}
                </p>

                {error && (
                    <div className="flex items-start gap-2 rounded-xl border border-coral-200 bg-coral-50 px-4 py-3 text-sm text-coral-800 dark:border-coral-900/40 dark:bg-coral-900/20 dark:text-coral-300">
                        <AlertCircle className="mt-0.5 h-4 w-4 shrink-0" aria-hidden />
                        <span className="min-w-0 flex-1">{error}</span>
                        {! busy && (
                            <button
                                type="button"
                                onClick={describe}
                                className="shrink-0 font-semibold underline underline-offset-2"
                            >
                                {t('catalog.fill_with_ai')}
                            </button>
                        )}
                    </div>
                )}

                {/* One call for ten products takes as long as it takes. The bar
                    moves and the cards are outlined so the wait looks like work
                    in progress rather than a dialog that has stopped. */}
                {drafting && (
                    <div className="space-y-3" aria-live="polite" aria-busy="true">
                        <div className="flex items-center gap-2 text-sm font-medium text-brand-700 dark:text-brand-300">
                            <Loader2 className="h-4 w-4 animate-spin" aria-hidden />
                            {t('catalog.ai_drafting', { count: itemIds.length })}
                        </div>
                        <div className="h-1 overflow-hidden rounded-full bg-brand-100 dark:bg-brand-900/40">
                            <div className="h-full w-1/3 animate-pulse rounded-full bg-brand-500" />
                        </div>
                        {Array.from({ length: Math.min(Math.max(itemIds.length, 1), 3) }).map((_, index) => (
                            <div
                                key={index}
                                className="space-y-2 rounded-xl border border-neutral-200 p-4 dark:border-neutral-700"
                            >
                                <Skeleton className="h-4 w-40" />
                                <Skeleton variant="text" lines={2} />
                            </div>
                        ))}
                    </div>
                )}

                {! drafting && ! error && drafts.length === 0 && (
                    <div className="rounded-xl border border-dashed border-neutral-300 px-4 py-8 text-center dark:border-neutral-700">
                        <Sparkles className="mx-auto h-6 w-6 text-neutral-300 dark:text-neutral-600" aria-hidden />
                        <p className="mt-2 text-sm text-neutral-500 dark:text-neutral-400">
                            {t('catalog.ai_nothing_to_do')}
                        </p>
                    </div>
                )}

                {! drafting && drafts.length > 0 && (
                    <>
                        {! allApproved && (
                            <div className="flex justify-end">
                                <button
                                    type="button"
                                    onClick={selectAll}
                                    className="text-xs font-medium text-brand-600 hover:underline dark:text-brand-400"
                                >
                                    {t('catalog.select_all')}
                                </button>
                            </div>
                        )}

                        {drafts.map(draft => (
                            <ProposalCard
                                key={draft.item_id}
                                draft={draft}
                                onApprove={() => update(draft.item_id, { approved: ! draft.approved })}
                                onDescribe={value => update(draft.item_id, { description: value })}
                                onToggleTag={(kind, index) => toggleTag(draft.item_id, kind, index)}
                            />
                        ))}
                    </>
                )}
            </Modal.Body>

            <Modal.Footer>
                <Button variant="ghost" onClick={onClose} disabled={busy}>
                    {t('catalog.cancel')}
                </Button>
                <Button
                    className="ai-glow gap-2"
                    onClick={apply}
                    disabled={busy || approved.length === 0}
                >
                    {saving
                        ? <Loader2 className="h-4 w-4 animate-spin" aria-hidden />
                        : <Sparkles className="h-4 w-4" aria-hidden />}
                    {approved.length > 0
                        ? t('catalog.ai_apply_count', { count: approved.length })
                        : t('catalog.ai_apply')}
                </Button>
            </Modal.Footer>
        </Modal>
    );
}
