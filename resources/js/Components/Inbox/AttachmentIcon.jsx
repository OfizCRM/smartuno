import { File, FileArchive, FileCode, FileImage, FileSpreadsheet, FileText } from 'lucide-react';

/**
 * A file's kind, for the badge beside its name.
 *
 * Deliberately NOT the same table as previewKindFor in AttachmentPreview: that
 * one mirrors the server's allow-list of what may be rendered in the page and
 * has to stay narrow, this one is cosmetic and can name anything. Merging them
 * would mean every new icon quietly widened what the browser is asked to
 * display, which is the wrong direction for a mistake to travel.
 *
 * Read from the extension we stored the file under, falling back to the sender's
 * filename for the oversized ones, which were never kept and so have no path.
 */
export function attachmentKindFor(file) {
    const ext = String(file?.path || file?.name || '').split('.').pop()?.toLowerCase();

    if (ext === 'pdf') return 'pdf';
    if (['png', 'jpg', 'jpeg', 'gif', 'webp', 'bmp', 'heic', 'svg'].includes(ext)) return 'image';
    if (['xls', 'xlsx', 'csv', 'ods'].includes(ext)) return 'sheet';
    if (['doc', 'docx', 'odt', 'rtf'].includes(ext)) return 'doc';
    if (['zip', 'rar', '7z', 'gz', 'tar'].includes(ext)) return 'archive';
    if (['txt', 'md', 'log'].includes(ext)) return 'text';
    if (['xml', 'json', 'html', 'mobileconfig'].includes(ext)) return 'code';

    return 'other';
}

/**
 * One tile per kind. The colours are the ones people already read as file types
 * — a red PDF, a green spreadsheet, a blue document — rather than the brand
 * green on everything, which is what made the list unreadable at a glance.
 */
const KINDS = {
    pdf: { Icon: FileText, tone: 'bg-coral-100 text-coral-600 dark:bg-coral-500/15 dark:text-coral-400' },
    image: { Icon: FileImage, tone: 'bg-violet-100 text-violet-600 dark:bg-violet-500/15 dark:text-violet-400' },
    sheet: { Icon: FileSpreadsheet, tone: 'bg-brand-100 text-brand-700 dark:bg-brand-500/15 dark:text-brand-300' },
    doc: { Icon: FileText, tone: 'bg-blue-100 text-blue-600 dark:bg-blue-500/15 dark:text-blue-400' },
    archive: { Icon: FileArchive, tone: 'bg-accent-100 text-accent-700 dark:bg-accent-500/15 dark:text-accent-400' },
    text: { Icon: FileText, tone: 'bg-neutral-200 text-neutral-600 dark:bg-neutral-700 dark:text-neutral-300' },
    code: { Icon: FileCode, tone: 'bg-secondary-100 text-secondary-700 dark:bg-secondary-500/15 dark:text-secondary-300' },
    other: { Icon: File, tone: 'bg-neutral-200 text-neutral-600 dark:bg-neutral-700 dark:text-neutral-300' },
};

export default function AttachmentIcon({ file, muted = false }) {
    const { Icon, tone } = KINDS[attachmentKindFor(file)] ?? KINDS.other;

    return (
        <span
            aria-hidden
            className={`flex h-7 w-7 shrink-0 items-center justify-center rounded-lg ${tone} ${muted ? 'opacity-50 grayscale' : ''}`}
        >
            <Icon className="h-4 w-4" />
        </span>
    );
}
