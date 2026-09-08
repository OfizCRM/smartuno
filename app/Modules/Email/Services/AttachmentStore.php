<?php

namespace App\Modules\Email\Services;

use App\Services\StorageManager;
use Illuminate\Support\Str;

/**
 * Where an email's files live.
 *
 * Separate from the inbox's WhatsApp media path, which uploads to Meta's Media
 * API before it stores anything and serves by redirecting to a public URL. An
 * email attachment has no Meta side, and a public URL would mean anyone holding
 * the link can read a tenant's invoice.
 */
class AttachmentStore
{
    /** Per file. Anything larger is recorded by name and not kept. */
    public const MAX_FILE_BYTES = 10 * 1024 * 1024;

    /** Per message, so one mail cannot fill the disk on its own. */
    public const MAX_MESSAGE_BYTES = 25 * 1024 * 1024;

    public function __construct(private readonly StorageManager $storage) {}

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

        $path = $this->storage->prefixedPath('email-attachments/'.Str::uuid().'.'.$this->extensionFor($mime));
        $this->storage->disk()->put($path, $contents);

        return ['name' => $name, 'mime' => $mime, 'size' => $size, 'path' => $path, 'stored' => true];
    }

    public function contents(string $path): ?string
    {
        $disk = $this->storage->disk();

        return $disk->exists($path) ? $disk->get($path) : null;
    }

    public function delete(?string $path): void
    {
        if ($path) {
            $this->storage->disk()->delete($path);
        }
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
