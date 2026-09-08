<?php

namespace App\Modules\Email\Services;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Where an email's files live.
 *
 * Separate from the inbox's WhatsApp media path, which uploads to Meta's Media
 * API before it stores anything and serves by redirecting to a public URL. An
 * email attachment has no Meta side, and a public URL would mean anyone holding
 * the link can read a tenant's invoice.
 *
 * Deliberately NOT StorageManager, which is the rule everywhere else in this
 * application: it resolves to the `public` disk, and that disk is symlinked into
 * the web root and served with no authentication at all. Logos, favicons and
 * WhatsApp previews need exactly that; an invoice or a scanned ID arriving by
 * email is the one class of file that must never have a public URL. The
 * configured cloud disks are no better here — they are declared public-read.
 */
class AttachmentStore
{
    /**
     * `local` is storage/app/private: outside the web root, and reachable
     * through Laravel's own storage route only with a valid signature, which
     * nothing in this module ever mints. The single way in is
     * AttachmentController, which checks the workspace first.
     */
    private const DISK = 'local';

    /** Per file. Anything larger is recorded by name and not kept. */
    public const MAX_FILE_BYTES = 10 * 1024 * 1024;

    /** Per message, so one mail cannot fill the disk on its own. */
    public const MAX_MESSAGE_BYTES = 25 * 1024 * 1024;

    /**
     * Keep one file and describe it for messages.payload.
     *
     * The stored name is a uuid plus an extension derived from the declared MIME
     * — never from the sender's filename. That is the rule that stops
     * "factura.pdf.php" landing somewhere a web server would execute it. The
     * original name is kept as data, for display only.
     *
     * @return array{name: string, mime: string, size: int, path: string|null, stored: bool}
     */
    public function put(string $name, string $mime, string $contents, int $alreadyStored = 0): array
    {
        $size = strlen($contents);
        $name = $this->safeName($name);

        $tooBig = $size > self::MAX_FILE_BYTES
            || ($alreadyStored + $size) > self::MAX_MESSAGE_BYTES;

        if ($tooBig) {
            // Recorded so the thread still shows something arrived, without
            // pretending the file is available.
            return ['name' => $name, 'mime' => $mime, 'size' => $size, 'path' => null, 'stored' => false];
        }

        $path = 'email-attachments/'.Str::uuid().'.'.$this->extensionFor($mime);
        $this->disk()->put($path, $contents);

        return ['name' => $name, 'mime' => $mime, 'size' => $size, 'path' => $path, 'stored' => true];
    }

    public function contents(string $path): ?string
    {
        $disk = $this->disk();

        return $disk->exists($path) ? $disk->get($path) : null;
    }

    public function delete(?string $path): void
    {
        if ($path) {
            $this->disk()->delete($path);
        }
    }

    private function disk(): Filesystem
    {
        return Storage::disk(self::DISK);
    }

    /**
     * A filename fit to show and to put in a Content-Disposition header.
     *
     * Directory separators and control characters go; the rest is left alone so
     * a Romanian invoice keeps its diacritics.
     */
    private function safeName(string $name): string
    {
        $name = preg_replace('#[\x00-\x1F/\\\\]+#u', '', trim($name)) ?? '';
        $name = ltrim($name, '.');

        return $name !== '' ? Str::limit($name, 180, '') : 'atasament';
    }

    /**
     * The content type to serve a stored file as, or null if it must not be
     * shown in the browser at all.
     *
     * Keyed on the extension WE gave the file, never on the type the sender
     * declared: that is the whole reason put() derives its own. The list is
     * deliberately short — an invoice, a photo, a spreadsheet export. Anything a
     * browser executes, HTML and SVG above all, is absent and stays a download.
     * SVG cannot reach here anyway; extensionFor() files it as `bin`.
     */
    public function previewMimeFor(string $path): ?string
    {
        return match (strtolower(pathinfo($path, PATHINFO_EXTENSION))) {
            'pdf' => 'application/pdf',
            'png' => 'image/png',
            'jpg' => 'image/jpeg',
            'gif' => 'image/gif',
            'webp' => 'image/webp',
            'txt' => 'text/plain; charset=UTF-8',
            'csv' => 'text/plain; charset=UTF-8',
            default => null,
        };
    }

    private function extensionFor(string $mime): string
    {
        return match (strtolower($mime)) {
            'application/pdf' => 'pdf',
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/gif' => 'gif',
            'image/webp' => 'webp',
            'text/plain' => 'txt',
            'text/csv' => 'csv',
            'application/zip' => 'zip',
            'application/msword' => 'doc',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
            'application/vnd.ms-excel' => 'xls',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'xlsx',
            // Everything else keeps a neutral extension: the file is only ever
            // streamed back as a download, never interpreted.
            default => 'bin',
        };
    }
}
