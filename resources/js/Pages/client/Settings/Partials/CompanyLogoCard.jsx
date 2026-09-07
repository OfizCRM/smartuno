import { useRef, useState } from 'react';
import { router, usePage } from '@inertiajs/react';
import { useTranslation } from 'react-i18next';
import { Building2, Trash2, Upload } from 'lucide-react';
import { Button, Card } from '@/Components/ui';

/**
 * The logo posts to its own endpoint instead of riding along with the main form.
 *
 * A file turns the whole submit into multipart, which Inertia can only send as a
 * POST with a method override — so folding it in would cost the company form its
 * plain PUT for the sake of a field people touch once.
 */
export default function CompanyLogoCard({ logoUrl }) {
    const { t } = useTranslation();
    const { errors = {} } = usePage().props;
    const fileRef = useRef(null);
    const [preview, setPreview] = useState(null);
    const [busy, setBusy] = useState(false);

    const handleFile = (file) => {
        if (!file) return;
        setPreview(URL.createObjectURL(file));
        const payload = new FormData();
        payload.append('logo', file);
        setBusy(true);
        router.post(route('client.settings.company.logo.store'), payload, {
            forceFormData: true,
            preserveScroll: true,
            // Without this the visit remounts the page and throws away whatever
            // the user had already typed into the company form below.
            preserveState: true,
            onFinish: () => {
                setBusy(false);
                setPreview(null);
                if (fileRef.current) fileRef.current.value = '';
            },
        });
    };

    const handleDelete = () => {
        setBusy(true);
        router.delete(route('client.settings.company.logo.destroy'), {
            preserveScroll: true,
            preserveState: true,
            onFinish: () => setBusy(false),
        });
    };

    const shown = preview || logoUrl;

    return (
        <Card>
            <Card.Header title={t('company.section_logo')} />
            <Card.Body className="flex flex-wrap items-start gap-4">
                <div className="flex h-24 w-24 shrink-0 items-center justify-center overflow-hidden rounded-2xl border border-dashed border-neutral-300 bg-neutral-50 dark:border-neutral-600 dark:bg-neutral-800/50">
                    {shown ? (
                        <img src={shown} alt={t('company.logo_alt')} className="h-full w-full object-contain" />
                    ) : (
                        <Building2 className="h-8 w-8 text-neutral-300 dark:text-neutral-600" />
                    )}
                </div>

                <div className="min-w-0 flex-1 space-y-2">
                    <p className="text-sm text-ink-muted dark:text-neutral-400">{t('company.logo_hint')}</p>

                    <div className="flex flex-wrap gap-2">
                        <Button variant="secondary" size="sm" disabled={busy} onClick={() => fileRef.current?.click()}>
                            <Upload className="mr-1.5 h-4 w-4" />
                            {t('company.logo_upload')}
                        </Button>
                        {logoUrl && (
                            <Button variant="ghost" size="sm" disabled={busy} onClick={handleDelete}>
                                <Trash2 className="mr-1.5 h-4 w-4" />
                                {t('company.logo_remove')}
                            </Button>
                        )}
                    </div>

                    {errors.logo && <p className="text-xs text-coral-600">{errors.logo}</p>}

                    <input
                        ref={fileRef}
                        type="file"
                        accept="image/png,image/jpeg,image/gif,image/webp,image/svg+xml"
                        className="hidden"
                        onChange={(e) => handleFile(e.target.files?.[0])}
                    />
                </div>
            </Card.Body>
        </Card>
    );
}
