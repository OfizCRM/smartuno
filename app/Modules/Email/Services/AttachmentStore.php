<?php

namespace App\Modules\Email\Services;

use App\Modules\Shared\Services\PrivateFileStore;

/**
 * Where an email's files live, and how big they are allowed to be.
 *
 * The writing itself is PrivateFileStore's job, shared with the document
 * library: same private disk, same uuid names, same extension derived from the
 * declared MIME rather than from the sender's filename. What stays here is the
 * part that is only true of mail — a cap per file and a cap per message, so one
 * correspondent forwarding a photo album cannot fill a disk every tenant shares.
 *
 * Separate from the inbox's WhatsApp media path, which uploads to Meta's Media
 * API before it stores anything and serves by redirecting to a public URL. An
 * email attachment has no Meta side, and a public URL would mean anyone holding
 * the link can read a tenant's invoice.
 */
class AttachmentStore
{
    /** Where in the private disk an email's files go. */
    private const DIRECTORY = 'email-attachments';

    /** Per file. Anything larger is recorded by name and not kept. */
    public const MAX_FILE_BYTES = 10 * 1024 * 1024;

    /** Per message, so one mail cannot fill the disk on its own. */
    public const MAX_MESSAGE_BYTES = 25 * 1024 * 1024;

    public function __construct(private readonly PrivateFileStore $files) {}

    /**
     * Keep one file and describe it for messages.payload.
     *
     * @param  int  $alreadyStored  bytes already kept for this message
     * @return array{name: string, mime: string, size: int, path: string|null, stored: bool}
     */
    public function put(string $name, string $mime, string $contents, int $alreadyStored = 0): array
    {
        $size = strlen($contents);

        $tooBig = $size > self::MAX_FILE_BYTES
            || ($alreadyStored + $size) > self::MAX_MESSAGE_BYTES;

        if ($tooBig) {
            // Recorded so the thread still shows something arrived, without
            // pretending the file is available. Nothing is written to the disk.
            return [
                'name' => $this->files->safeName($name),
                'mime' => $mime,
                'size' => $size,
                'path' => null,
                'stored' => false,
            ];
        }

        return $this->files->put(self::DIRECTORY, $name, $mime, $contents) + ['stored' => true];
    }

    public function contents(string $path): ?string
    {
        return $this->files->contents($path);
    }

    public function delete(?string $path): void
    {
        $this->files->delete($path);
    }

    public function previewMimeFor(string $path): ?string
    {
        return $this->files->previewMimeFor($path);
    }
}
