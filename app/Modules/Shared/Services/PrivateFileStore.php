<?php

namespace App\Modules\Shared\Services;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Files that must never have a public URL.
 *
 * Deliberately NOT StorageManager, which is the rule everywhere else in this
 * application: it resolves to the `public` disk, and that disk is symlinked into
 * the web root and served with no authentication at all. Logos, favicons and
 * WhatsApp previews need exactly that. An invoice, a scanned ID or a signed
 * contract is the class of file that must not have one — and the configured
 * cloud disks are no better here, they are declared public-read.
 *
 * This is the mechanism only. How large a file may be, and who is allowed to
 * read one back, belong to the module that owns it: the email module caps a
 * message at 25 MB, the document library caps a workspace at its plan's storage
 * allowance, and those are not the same rule.
 */
class PrivateFileStore
{
    /**
     * `local` is storage/app/private: outside the web root, and reachable
     * through Laravel's own storage route only with a valid signature, which
     * nothing in this application ever mints. Every way in goes through a
     * controller that checks the workspace first.
     */
    private const DISK = 'local';

    /**
     * Write one file and describe it.
     *
     * The stored name is a uuid plus an extension derived from the declared MIME
     * — never from the name the file arrived under. That is the rule that stops
     * "factura.pdf.php" landing somewhere a web server would execute it. The
     * original name is kept as data, for display only.
     *
     * @return array{name: string, mime: string, size: int, path: string}
     */
    public function put(string $directory, string $name, string $mime, string $contents): array
    {
        $path = trim($directory, '/').'/'.Str::uuid().'.'.$this->extensionFor($mime);
        $this->disk()->put($path, $contents);

        return [
            'name' => $this->safeName($name),
            'mime' => $mime,
            'size' => strlen($contents),
            'path' => $path,
        ];
    }

    /**
     * Write at an exact path.
     *
     * For derived files — a rendered preview — where the caller needs to be able
     * to find the same one again. Everything a person uploaded goes through
     * put(), which chooses the name itself.
     */
    public function putRaw(string $path, string $contents): void
    {
        $this->disk()->put($path, $contents);
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

    /**
     * A filename fit to show and to put in a Content-Disposition header.
     *
     * Directory separators and control characters go; the rest is left alone so
     * a Romanian invoice keeps its diacritics.
     */
    public function safeName(string $name): string
    {
        $name = preg_replace('#[\x00-\x1F/\\\\]+#u', '', trim($name)) ?? '';
        $name = ltrim($name, '.');

        return $name !== '' ? Str::limit($name, 180, '') : 'fisier';
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

    public function extensionFor(string $mime): string
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
            'application/vnd.ms-powerpoint' => 'ppt',
            'application/vnd.openxmlformats-officedocument.presentationml.presentation' => 'pptx',
            // Everything else keeps a neutral extension: the file is only ever
            // streamed back as a download, never interpreted.
            default => 'bin',
        };
    }

    private function disk(): Filesystem
    {
        return Storage::disk(self::DISK);
    }
}
