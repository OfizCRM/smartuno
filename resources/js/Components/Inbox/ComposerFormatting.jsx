import { Bold, Eye, Italic, Link2, List } from 'lucide-react';
import { useTranslation } from 'react-i18next';

/**
 * Formatting for an email reply, as Markdown.
 *
 * Markdown rather than a rich-text editor on purpose. The body of a message is
 * stored as plain text and read back by five other screens; making it HTML would
 * mean either rendering a stranger's markup with dangerouslySetInnerHTML, which
 * this project forbids, or carrying a parser and a sanitiser to avoid it. What
 * is typed here is already the plain-text version of the mail, MarkdownLite
 * renders it in the thread, and the server turns the same text into the HTML
 * part when it sends.
 */

/**
 * Apply one format to the selection. Pure, so the awkward cases — a toggle back
 * off, a bulleted block, where the caret lands afterwards — can be tested
 * without a browser.
 *
 * @returns {{value: string, start: number, end: number}}
 */
export function applyFormat(value, start, end, kind) {
    const selected = value.slice(start, end);

    if (kind === 'bold' || kind === 'italic') {
        const marks = kind === 'bold' ? '**' : '*';
        const before = value.slice(Math.max(0, start - marks.length), start);
        const after = value.slice(end, end + marks.length);

        // Pressing it again on text that already carries the marks takes them
        // off, rather than nesting a second pair that renders as neither.
        if (before === marks && after === marks) {
            return {
                value: value.slice(0, start - marks.length) + selected + value.slice(end + marks.length),
                start: start - marks.length,
                end: end - marks.length,
            };
        }

        return {
            value: value.slice(0, start) + marks + selected + marks + value.slice(end),
            start: start + marks.length,
            end: start + marks.length + selected.length,
        };
    }

    if (kind === 'list') {
        // Whole lines, not the selection: half a bullet is not a bullet.
        const lineStart = value.lastIndexOf('\n', Math.max(0, start - 1)) + 1;
        const nextBreak = value.indexOf('\n', end);
        const lineEnd = nextBreak === -1 ? value.length : nextBreak;
        const lines = value.slice(lineStart, lineEnd).split('\n');
        const allBulleted = lines.every(line => line.trim() === '' || line.startsWith('- '));
        const rewritten = lines
            .map(line => (line.trim() === '' ? line : allBulleted ? line.replace(/^- /, '') : `- ${line}`))
            .join('\n');

        return {
            value: value.slice(0, lineStart) + rewritten + value.slice(lineEnd),
            start: lineStart,
            end: lineStart + rewritten.length,
        };
    }

    if (kind === 'link') {
        const placeholder = 'https://';
        const inserted = `[${selected}](${placeholder})`;
        // Caret on the address, which is the part that always has to be typed.
        const urlStart = start + selected.length + 3;

        return {
            value: value.slice(0, start) + inserted + value.slice(end),
            start: urlStart,
            end: urlStart + placeholder.length,
        };
    }

    return { value, start, end };
}

/** True for the shortcut a person's fingers already know. */
export function formatShortcut(event) {
    if (!(event.metaKey || event.ctrlKey) || event.altKey) {
        return null;
    }

    const key = event.key.toLowerCase();

    return key === 'b' ? 'bold' : key === 'i' ? 'italic' : null;
}

export default function ComposerFormatting({ textareaRef, value, onChange, previewing, onTogglePreview }) {
    const { t } = useTranslation();

    const apply = (kind) => {
        const el = textareaRef?.current;
        if (!el) {
            return;
        }

        const next = applyFormat(value ?? '', el.selectionStart, el.selectionEnd, kind);
        onChange(next.value);
        // After React has written the new value, or the range is set against the
        // old one and the caret jumps to the end.
        requestAnimationFrame(() => {
            el.focus();
            el.setSelectionRange(next.start, next.end);
        });
    };

    const buttonClass = 'rounded-lg p-1.5 text-neutral-400 transition hover:bg-neutral-100 hover:text-neutral-600 dark:hover:bg-neutral-800';

    return (
        <>
            <button type="button" onClick={() => apply('bold')} title={`${t('inbox.format_bold')} (Ctrl+B)`} className={buttonClass}>
                <Bold className="h-4 w-4" />
            </button>
            <button type="button" onClick={() => apply('italic')} title={`${t('inbox.format_italic')} (Ctrl+I)`} className={buttonClass}>
                <Italic className="h-4 w-4" />
            </button>
            <button type="button" onClick={() => apply('list')} title={t('inbox.format_list')} className={buttonClass}>
                <List className="h-4 w-4" />
            </button>
            <button type="button" onClick={() => apply('link')} title={t('inbox.format_link')} className={buttonClass}>
                <Link2 className="h-4 w-4" />
            </button>
            <button
                type="button"
                onClick={onTogglePreview}
                title={t('inbox.format_preview')}
                aria-pressed={previewing}
                className={`${buttonClass} ${previewing ? 'bg-neutral-100 text-neutral-600 dark:bg-neutral-800' : ''}`}
            >
                <Eye className="h-4 w-4" />
            </button>
            <span className="mx-1 h-4 w-px bg-neutral-200 dark:bg-neutral-700" />
        </>
    );
}
