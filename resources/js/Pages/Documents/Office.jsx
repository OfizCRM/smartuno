import { Head, router } from '@inertiajs/react';
import { AlertTriangle, ArrowLeft, Loader2, Maximize2, Minimize2 } from 'lucide-react';
import { useCallback, useEffect, useRef, useState } from 'react';
import { useTranslation } from 'react-i18next';
import ClientLayout from '@/Layouts/ClientLayout';

/**
 * Safari still ships only the prefixed Fullscreen API, and Safari on a Mac is
 * exactly who is going to open an 18-page contract here.
 */
function fullscreenElement() {
    return window.document.fullscreenElement ?? window.document.webkitFullscreenElement ?? null;
}

/**
 * The document, open in ONLYOFFICE.
 *
 * The editor is not a React component — it is a script served by the Document
 * Server that takes over a div. So this page loads that script, hands it the
 * configuration the server signed, and otherwise keeps out of its way.
 *
 * The failure worth designing for is the script never arriving: the Document
 * Server is a separate container, and when it is down or the URL is wrong the
 * page would otherwise sit blank for ever. It says so instead, and offers the
 * download that has always worked.
 */
export default function Office({ document: doc, serverUrl, config }) {
    const editable = config?.editorConfig?.mode === 'edit';
    const { t } = useTranslation();
    const holder = useRef(null);
    const editor = useRef(null);
    const shell = useRef(null);
    const [state, setState] = useState('loading');
    const [expanded, setExpanded] = useState(false);

    useEffect(() => {
        // Escape and F11 leave fullscreen without going through the button, so
        // the label has to follow the browser rather than the last click.
        const sync = () => setExpanded(fullscreenElement() === shell.current);

        window.document.addEventListener('fullscreenchange', sync);
        window.document.addEventListener('webkitfullscreenchange', sync);

        return () => {
            window.document.removeEventListener('fullscreenchange', sync);
            window.document.removeEventListener('webkitfullscreenchange', sync);
        };
    }, []);

    /**
     * The whole panel goes fullscreen, not just the editor — the way out has to
     * stay on the screen, and the sidebar and the top bar are most of what is
     * being reclaimed anyway.
     */
    const toggleExpanded = useCallback(() => {
        const el = shell.current;

        if (!el) {
            return;
        }

        if (fullscreenElement()) {
            const exit = window.document.exitFullscreen ?? window.document.webkitExitFullscreen;
            Promise.resolve(exit?.call(window.document)).catch(() => {});

            return;
        }

        const request = el.requestFullscreen ?? el.webkitRequestFullscreen;
        // Refused — a browser setting, or a policy on an embedded page. Leave
        // the page exactly as it was rather than half-changed.
        Promise.resolve(request?.call(el)).catch(() => {});
    }, []);

    useEffect(() => {
        let cancelled = false;
        const scriptUrl = `${serverUrl}/web-apps/apps/api/documents/api.js`;

        const start = () => {
            if (cancelled || !window.DocsAPI || !holder.current) {
                return;
            }
            try {
                editor.current = new window.DocsAPI.DocEditor(holder.current.id, {
                    ...config,
                    width: '100%',
                    height: '100%',
                    events: {
                        onAppReady: () => !cancelled && setState('ready'),
                        onError: () => !cancelled && setState('failed'),
                    },
                });
            } catch {
                setState('failed');
            }
        };

        // The script registers a global; loading it twice would replace it under
        // an editor that is already running.
        const existing = window.document.querySelector(`script[src="${scriptUrl}"]`);

        if (window.DocsAPI) {
            start();
        } else if (existing) {
            existing.addEventListener('load', start);
            existing.addEventListener('error', () => setState('failed'));
        } else {
            const script = window.document.createElement('script');
            script.src = scriptUrl;
            script.async = true;
            script.onload = start;
            script.onerror = () => !cancelled && setState('failed');
            window.document.body.appendChild(script);
        }

        return () => {
            cancelled = true;
            try {
                editor.current?.destroyEditor?.();
            } catch {
                // Already gone with the page.
            }
        };
    }, [serverUrl, config]);

    return (
        <ClientLayout title={doc.name}>
            <Head title={doc.name} />

            <div ref={shell} className={`flex flex-col gap-3 ${
                expanded ? 'h-screen bg-white p-4 dark:bg-neutral-900' : 'h-[calc(100vh-8rem)]'
            }`}>
                <div className="flex items-center gap-3">
                    <button type="button" onClick={() => router.visit(route('client.documents.index'))}
                        aria-label={t('common.back')}
                        className="rounded-lg p-1.5 text-neutral-400 transition hover:bg-neutral-100 dark:hover:bg-neutral-800">
                        <ArrowLeft className="h-4 w-4" />
                    </button>
                    <h1 className="min-w-0 flex-1 truncate text-lg font-semibold text-neutral-900 dark:text-white">{doc.name}</h1>
                    <button type="button" onClick={toggleExpanded}
                        title={expanded ? t('office.exit_fullscreen') : t('office.fullscreen')}
                        aria-label={expanded ? t('office.exit_fullscreen') : t('office.fullscreen')}
                        className="shrink-0 rounded-lg border border-neutral-300 p-1.5 text-neutral-600 transition hover:bg-neutral-50 hover:text-brand-600 dark:border-neutral-600 dark:text-neutral-300 dark:hover:bg-neutral-800">
                        {expanded ? <Minimize2 className="h-4 w-4" /> : <Maximize2 className="h-4 w-4" />}
                    </button>
                    <a href={route('client.documents.file', doc.uuid)}
                        className="shrink-0 rounded-lg border border-neutral-300 px-3 py-1.5 text-xs text-neutral-700 transition hover:bg-neutral-50 dark:border-neutral-600 dark:text-neutral-300 dark:hover:bg-neutral-800">
                        {t('documents.download')}
                    </a>
                </div>

                <div className="relative flex-1 overflow-hidden rounded-xl border border-neutral-200 bg-white dark:border-neutral-700 dark:bg-neutral-900">
                    {state === 'loading' && (
                        <div className="absolute inset-0 z-10 flex flex-col items-center justify-center gap-2 bg-white dark:bg-neutral-900">
                            <Loader2 className="h-5 w-5 animate-spin text-neutral-400" />
                            <p className="text-sm text-neutral-400">{t('office.loading')}</p>
                        </div>
                    )}

                    {state === 'failed' && (
                        <div className="absolute inset-0 z-10 flex flex-col items-center justify-center gap-3 bg-white p-6 text-center dark:bg-neutral-900">
                            <AlertTriangle className="h-6 w-6 text-coral-500" />
                            <p className="max-w-sm text-sm text-neutral-600 dark:text-neutral-400">{t('office.unreachable')}</p>
                            <a href={route('client.documents.file', doc.uuid)}
                                className="rounded-xl bg-brand-600 px-4 py-2 text-sm font-semibold text-white transition hover:bg-brand-700">
                                {t('documents.download')}
                            </a>
                        </div>
                    )}

                    <div id="onlyoffice-surface" ref={holder} className="h-full w-full" />
                </div>

                <p className="px-1 text-xs text-neutral-800 dark:text-neutral-200">
                    {editable ? t('office.saving_note') : t('office.read_only_note')}
                </p>
            </div>
        </ClientLayout>
    );
}
