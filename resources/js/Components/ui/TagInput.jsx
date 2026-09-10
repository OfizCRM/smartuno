import { X } from 'lucide-react';
import { useId, useState } from 'react';
import { useTranslation } from 'react-i18next';

/**
 * A short list of words, typed one at a time.
 *
 * This is the chip-and-datalist box the contact page has had since the segments
 * work: chips with a cross, a dashed input after them, Enter or blur commits,
 * the workspace's existing words offered as you type. The catalogue's "pentru
 * cine este" and "nu îl propune dacă" are its second and third consumers, and
 * three copies of a keyboard-driven input is how they end up behaving
 * differently — so it moved here rather than being pasted twice more.
 *
 * The two lists are one concept with opposite sign, which is why this takes a
 * tone rather than being written twice: `positive` is the brand green ("this
 * fits"), `negative` the coral red ("do not offer it"). Nothing else about the
 * two differs, and nothing else should.
 *
 * Props:
 *   value       – string[]. The chips, in order. Controlled: this component
 *                 holds only the half-typed word.
 *   onChange    – called with the whole next array.
 *   tone        – 'positive' (default) | 'negative'.
 *   suggestions – words already used elsewhere in the workspace, offered as a
 *                 datalist. Optional; ones already chosen are not re-offered.
 *   placeholder – already-translated placeholder for the empty box.
 *   label       – already-translated label above the chips. Optional.
 */

const TONES = {
    positive: {
        chip: 'border-brand-200 bg-brand-50 text-brand-700 dark:border-brand-800/60 dark:bg-brand-900/30 dark:text-brand-200',
        remove: 'text-brand-500 hover:text-brand-800 dark:text-brand-300 dark:hover:text-brand-100',
        field: 'border-brand-300 focus:border-brand-500 focus:ring-brand-500/20 dark:border-brand-700/70',
    },
    negative: {
        chip: 'border-coral-200 bg-coral-50 text-coral-700 dark:border-coral-800/60 dark:bg-coral-900/30 dark:text-coral-200',
        remove: 'text-coral-500 hover:text-coral-800 dark:text-coral-300 dark:hover:text-coral-100',
        field: 'border-coral-300 focus:border-coral-500 focus:ring-coral-500/20 dark:border-coral-700/70',
    },
};

/** The column is string(64). A longer word is cut here rather than refused by the server. */
const MAX_LENGTH = 64;

export default function TagInput({
    value = [],
    onChange,
    tone = 'positive',
    suggestions = [],
    placeholder,
    label,
}) {
    const { t } = useTranslation();
    const fieldId = useId();
    const listId = `${fieldId}-options`;
    const [draft, setDraft] = useState('');

    const palette = TONES[tone] ?? TONES.positive;
    const tags = Array.isArray(value) ? value : [];

    const commit = () => {
        // Whatever was pasted in, collapsed to one line of one-spaced words:
        // "  familii   mari " and "familii mari" are the same tag, and the
        // unique index would happily keep both.
        const next = draft.trim().replace(/\s+/g, ' ').slice(0, MAX_LENGTH);
        setDraft('');

        if (next === '') return;

        // Case-insensitively, because "Familii" and "familii" are one word to
        // the person typing them and two rows to (catalog_item_id, kind, label).
        if (tags.some(tag => tag.toLowerCase() === next.toLowerCase())) return;

        onChange?.([...tags, next]);
    };

    const drop = (tag) => onChange?.(tags.filter(x => x !== tag));

    const onKeyDown = (e) => {
        // Enter never reaches an enclosing form from here: in a box that adds
        // words one at a time it means "that is the word", not "save the page".
        // The comma does the same, because a list of words is what a person
        // types with commas in it.
        if (e.key === 'Enter' || e.key === ',') {
            e.preventDefault();
            commit();

            return;
        }

        // Backspace in an empty box takes the last chip back. Without it the
        // only way to undo a mistyped word is to reach for the mouse.
        if (e.key === 'Backspace' && draft === '' && tags.length > 0) {
            e.preventDefault();
            drop(tags[tags.length - 1]);
        }
    };

    const offered = suggestions.filter(
        word => ! tags.some(tag => tag.toLowerCase() === String(word).toLowerCase()),
    );

    return (
        <div>
            {label && (
                <label htmlFor={fieldId} className="mb-1.5 block text-sm font-medium text-neutral-700 dark:text-neutral-300">
                    {label}
                </label>
            )}

            <div className="flex flex-wrap items-center gap-2">
                {tags.map(tag => (
                    <span
                        key={tag}
                        className={`inline-flex items-center gap-1 rounded-full border px-2.5 py-1 text-xs font-medium ${palette.chip}`}
                    >
                        {tag}
                        <button
                            type="button"
                            onClick={() => drop(tag)}
                            aria-label={`${t('common.remove')} — ${tag}`}
                            className={`transition ${palette.remove}`}
                        >
                            <X className="h-3 w-3" aria-hidden />
                        </button>
                    </span>
                ))}

                <input
                    id={fieldId}
                    value={draft}
                    onChange={e => setDraft(e.target.value)}
                    onKeyDown={onKeyDown}
                    onBlur={commit}
                    list={offered.length > 0 ? listId : undefined}
                    maxLength={MAX_LENGTH}
                    placeholder={placeholder}
                    className={`w-44 rounded-full border border-dashed bg-transparent px-3 py-1 text-xs text-neutral-800 transition focus:outline-none focus:ring-2 dark:text-neutral-100 ${palette.field}`}
                />
                {offered.length > 0 && (
                    <datalist id={listId}>
                        {offered.map(word => <option key={word} value={word} />)}
                    </datalist>
                )}
            </div>
        </div>
    );
}
