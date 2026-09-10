import { Download, Loader2, X } from 'lucide-react';
import { useEffect, useState } from 'react';
import { useTranslation } from 'react-i18next';

/**
 * How a stored attachment may be shown, decided from the extension THIS
 * application gave the file — never from the type the sender declared. It is
 * the same rule the server applies before it will serve one inline, kept in step
 * on purpose: anything the browser executes is absent from both lists and stays
 * a download.
 */
/**
 * Which of the three preview containers a file belongs in, from its extension.
 *
 * Takes anything ending in one — a file name, a bare extension, once a stored
 * path. Never a path any more: where an attachment sits on disk is not
 * something the browser is told, and the name carries the same extension.
 */
export function previewKindFor(nameOrExtension) {
    const ext = String(nameOrExtension ?? '').split('.').pop()?.toLowerCase();

    if (ext === 'pdf') return 'pdf';
    if (['png', 'jpg', 'gif', 'webp'].includes(ext)) return 'image';
    if (['txt', 'csv'].includes(ext)) return 'text';

    return null;
}

/**
 * Shows one attachment without leaving the thread.
 *
 * Not the image lightbox above: that one is wired to WhatsApp media and its
 * proxy route, and a PDF is not an image. This is its own dialog because the
 * three things it can show — a page, a picture, a block of text — need three
 * different containers.
 */
export default function AttachmentPreview({ file, url, onClose, kind: forced = null }) {
    const { t } = useTranslation();
    // `forced` is for a file the server converts on the way out — a Word
    // document comes back as a rendered PDF, and its own extension would say
    // otherwise.
    const kind = forced ?? previewKindFor(file?.name);
    const previewUrl = `${url}?preview=1`;
    const [text, setText] = useState(null);
    const [failed, setFailed] = useState(false);

    useEffect(() => {
        if (kind !== 'text') {
            return undefined;
        }

        let cancelled = false;
        fetch(previewUrl, { credentials: 'same-origin' })
            .then(r => (r.ok ? r.text() : Promise.reject(new Error(String(r.status)))))
            // Enough to read, not enough to lock the tab up on a 10 MB csv.
            .then(body => { if (!cancelled) setText(body.slice(0, 200000)); })
            .catch(() => { if (!cancelled) setFailed(true); });

        return () => { cancelled = true; };
    }, [kind, previewUrl]);

    useEffect(() => {
        const onKey = (e) => { if (e.key === 'Escape') onClose(); };
        window.addEventListener('keydown', onKey);

        return () => window.removeEventListener('keydown', onKey);
    }, [onClose]);

    if (!file || !kind) {
        return null;
    }

    return (
        <div
            role="dialog"
            aria-modal="true"
            aria-label={file.name}
            className="fixed inset-0 z-50 flex flex-col bg-black/70 p-4 backdrop-blur-sm sm:p-8"
            onClick={onClose}
        >
            <div className="mx-auto flex w-full max-w-4xl items-center gap-3 pb-3 text-white">
                <span className="min-w-0 flex-1 truncate text-sm font-medium">{file.name}</span>
                <a
                    href={url}
                    onClick={e => e.stopPropagation()}
                    className="inline-flex items-center gap-1.5 rounded-lg bg-white/10 px-3 py-1.5 text-xs font-medium transition hover:bg-white/20"
                >
                    <Download className="h-3.5 w-3.5" />
                    {t('inbox.attachment_download')}
                </a>
                <button type="button" onClick={onClose} aria-label={t('common.close')}
                    className="rounded-lg p-1.5 transition hover:bg-white/20">
                    <X className="h-4 w-4" />
                </button>
            </div>

            <div
                onClick={e => e.stopPropagation()}
                className="mx-auto flex w-full max-w-4xl flex-1 items-center justify-center overflow-hidden rounded-xl bg-white dark:bg-neutral-900"
            >
                {kind === 'image' && (
                    <img src={previewUrl} alt={file.name} className="max-h-full max-w-full object-contain" />
                )}

                {/* The server sends this with a sandbox policy, so nothing inside
                    the document runs. */}
                {kind === 'pdf' && (
                    <iframe src={previewUrl} title={file.name} className="h-full w-full border-0" />
                )}

                {kind === 'text' && (
                    <div className="h-full w-full overflow-auto p-4">
                        {failed
                            ? <p className="text-sm text-neutral-500">{t('inbox.attachment_preview_failed')}</p>
                            : text === null
                                ? <Loader2 className="mx-auto h-5 w-5 animate-spin text-neutral-400" />
                                : <pre className="whitespace-pre-wrap break-words font-mono text-xs text-neutral-800 dark:text-neutral-200">{text}</pre>}
                    </div>
                )}
            </div>
        </div>
    );
}
