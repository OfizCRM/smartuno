import { render, screen } from '@testing-library/react';
import { describe, it, expect } from 'vitest';
import MarkdownLite from '@/Components/MarkdownLite';
import { applyFormat, formatShortcut } from '@/Components/Inbox/ComposerFormatting';
import { previewKindFor } from '@/Components/Inbox/AttachmentPreview';
import AttachmentIcon, { attachmentKindFor } from '@/Components/Inbox/AttachmentIcon';

/**
 * The composer stores Markdown. These are the cases where "wrap the selection"
 * stops being obvious: pressing the button twice, a block of lines, and where
 * the caret has to land for the next keystroke to be useful.
 */
describe('applyFormat', () => {
    it('wraps the selection and keeps it selected', () => {
        const r = applyFormat('coletul pleaca azi', 8, 14, 'bold');

        expect(r.value).toBe('coletul **pleaca** azi');
        expect(r.value.slice(r.start, r.end)).toBe('pleaca');
    });

    it('takes the marks off again rather than nesting a second pair', () => {
        const r = applyFormat('coletul **pleaca** azi', 10, 16, 'bold');

        expect(r.value).toBe('coletul pleaca azi');
        expect(r.value.slice(r.start, r.end)).toBe('pleaca');
    });

    it('leaves the caret between the marks when nothing is selected', () => {
        const r = applyFormat('scrie ', 6, 6, 'italic');

        expect(r.value).toBe('scrie **');
        expect(r.start).toBe(7);
        expect(r.start).toBe(r.end);
    });

    it('bullets every line the selection touches, not just the selected part', () => {
        const r = applyFormat('unu\ndoi\ntrei', 1, 5, 'list');

        expect(r.value).toBe('- unu\n- doi\ntrei');
    });

    it('unbullets a block that is already a list', () => {
        const r = applyFormat('- unu\n- doi', 0, 11, 'list');

        expect(r.value).toBe('unu\ndoi');
    });

    it('leaves blank lines alone inside a block', () => {
        const r = applyFormat('unu\n\ndoi', 0, 8, 'list');

        expect(r.value).toBe('- unu\n\n- doi');
    });

    it('puts the caret on the address of a new link, ready to be typed over', () => {
        const r = applyFormat('vezi aici', 5, 9, 'link');

        expect(r.value).toBe('vezi [aici](https://)');
        expect(r.value.slice(r.start, r.end)).toBe('https://');
    });
});

describe('formatShortcut', () => {
    it('recognises the two a writer already knows', () => {
        expect(formatShortcut({ ctrlKey: true, key: 'b' })).toBe('bold');
        expect(formatShortcut({ metaKey: true, key: 'I' })).toBe('italic');
    });

    it('ignores a plain keystroke, so typing "b" still types a b', () => {
        expect(formatShortcut({ key: 'b' })).toBeNull();
        expect(formatShortcut({ ctrlKey: true, altKey: true, key: 'b' })).toBeNull();
        expect(formatShortcut({ ctrlKey: true, key: 'k' })).toBeNull();
    });
});

/**
 * This list must stay in step with the server's, which decides the same thing
 * from the same extension. A type that reaches here but not there just fails to
 * load; a type that reaches there but not here is the dangerous direction, and
 * both lists exclude everything a browser executes.
 */
describe('previewKindFor', () => {
    it('names the three shapes it can show', () => {
        expect(previewKindFor('email-attachments/x.pdf')).toBe('pdf');
        expect(previewKindFor('email-attachments/x.png')).toBe('image');
        expect(previewKindFor('email-attachments/x.csv')).toBe('text');
    });

    it('refuses everything a browser would execute', () => {
        // Nothing is ever stored as .svg or .html — put() files an unknown type
        // as .bin — but the guard does not depend on that being true.
        expect(previewKindFor('email-attachments/x.svg')).toBeNull();
        expect(previewKindFor('email-attachments/x.html')).toBeNull();
        expect(previewKindFor('email-attachments/x.bin')).toBeNull();
        expect(previewKindFor('email-attachments/x.docx')).toBeNull();
        expect(previewKindFor(null)).toBeNull();
    });
});

/**
 * The inbox renders every email body through this, so the prop it is called
 * with is load-bearing: given the wrong name the component renders nothing at
 * all and the message silently disappears from the thread. That is exactly what
 * happened, and nothing caught it, because the tests only covered the pure
 * helpers and never the render path.
 */
describe('MarkdownLite, as the inbox calls it', () => {
    it('renders the body it is given', () => {
        render(<MarkdownLite content={'Bună ziua,\n\nVă trimit **oferta**.'} />);

        expect(screen.getByText(/Bună ziua/)).toBeInTheDocument();
        expect(screen.getByText('oferta').tagName).toBe('STRONG');
    });

    it('renders a bulleted list the composer produced', () => {
        const { container } = render(<MarkdownLite content={'- coletul pleacă azi\n- AWB pe email'} />);

        expect(container.querySelectorAll('li')).toHaveLength(2);
    });

    it('keeps the line breaks of a plain-text mail', () => {
        const { container } = render(<MarkdownLite content={'Ok,\nAm receptionat asta.'} />);

        expect(container.querySelector('p').className).toContain('whitespace-pre-wrap');
    });

    it('renders nothing when the prop is misnamed — the failure mode to remember', () => {
        const { container } = render(<MarkdownLite text="Bună ziua" />);

        expect(container).toBeEmptyDOMElement();
    });
});

describe('attachmentKindFor', () => {
    it('reads the extension this application stored the file under', () => {
        expect(attachmentKindFor({ path: 'email-attachments/x.pdf', name: 'factura.zip' })).toBe('pdf');
        expect(attachmentKindFor({ path: 'email-attachments/x.xlsx' })).toBe('sheet');
        expect(attachmentKindFor({ path: 'email-attachments/x.png' })).toBe('image');
    });

    it('falls back to the sender filename for a file that was never kept', () => {
        // Over the size cap: listed by name, no path.
        expect(attachmentKindFor({ path: null, name: 'film.zip' })).toBe('archive');
    });

    it('has a shape for anything at all', () => {
        expect(attachmentKindFor({ path: 'email-attachments/x.bin' })).toBe('other');
        expect(attachmentKindFor({})).toBe('other');
        expect(attachmentKindFor(null)).toBe('other');
    });

    it('is wider than the preview list, and does not widen it', () => {
        // A docx gets its own icon; it still must not be rendered in the page.
        expect(attachmentKindFor({ path: 'x.docx' })).toBe('doc');
        expect(previewKindFor('x.docx')).toBeNull();
        expect(attachmentKindFor({ path: 'x.svg' })).toBe('image');
        expect(previewKindFor('x.svg')).toBeNull();
    });
});

describe('AttachmentIcon', () => {
    it('actually renders a tile, tinted for the kind', () => {
        const { container } = render(<AttachmentIcon file={{ path: 'email-attachments/x.pdf' }} />);
        const tile = container.firstChild;

        expect(tile).not.toBeNull();
        expect(tile.className).toContain('coral');
        expect(tile.querySelector('svg')).not.toBeNull();
    });

    it('greys out a file that was not kept', () => {
        const { container } = render(<AttachmentIcon file={{ name: 'film.bin' }} muted />);

        expect(container.firstChild.className).toContain('grayscale');
    });
});
