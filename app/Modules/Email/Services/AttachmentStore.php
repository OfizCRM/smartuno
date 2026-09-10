<?php

namespace App\Modules\Email\Services;

use App\Modules\Shared\Services\PrivateFileStore;

/**
 * Where an email's files live, and how big they are allowed to be.
 *
 * The writing itself is PrivateFileStore's job, shared with the document
 * library: whichever private disk that class is writing to, same uuid names,
 * same extension derived from the declared MIME rather than from the sender's
 * filename — and the entry it hands back now names that disk. What stays here is the
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
    public const DIRECTORY = 'email-attachments';

    /** Per file. Anything larger is recorded by name and not kept. */
    public const MAX_FILE_BYTES = 10 * 1024 * 1024;

    /** Per message, so one mail cannot fill the disk on its own. */
    public const MAX_MESSAGE_BYTES = 25 * 1024 * 1024;

    public function __construct(private readonly PrivateFileStore $files) {}

    /**
     * Keep one file and describe it for messages.payload.
     *
     * The entry carries `disk` beside `path`. An attachment has no row of its
     * own — it lives inside the message's payload JSON, so there is no column to
     * add and no table to migrate; the key goes in the array or it does not exist
     * at all. Every read defaults a missing key to 'local', because entries
     * written before this key existed have no record of a disk anywhere and
     * `local` is the only thing "no disk" can mean.
     *
     * The over-cap branch carries the key too, with the same value, even though
     * it wrote nothing and its path is null. One shape for every entry: a reader
     * that has to ask which kind of entry it is holding before it knows which
     * keys are safe to touch is a reader that will one day guess wrong. `stored`
     * is already the flag that says whether there are bytes; `disk` says where
     * they would be, and answering that consistently costs nothing.
     *
     * @param  int  $alreadyStored  bytes already kept for this message
     * @return array{name: string, mime: string, size: int, path: string|null, disk: string, stored: bool}
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
                // Where it would have gone. Nothing is there to read, and the
                // shape stays the same as a stored entry's.
                'disk' => $this->files->diskName(),
                'stored' => false,
            ];
        }

        // put() already returns `disk` — the one it actually wrote to, not a
        // second guess at it — so the union only has to add `stored`.
        return $this->files->put(self::DIRECTORY, $name, $mime, $contents) + ['stored' => true];
    }

    /**
     * The bytes of one attachment, from the disk its entry says it is on.
     *
     * Null disk is the ordinary case for anything received before this key
     * existed, and PrivateFileStore reads those from `local`. Callers pass
     * `$attachment['disk'] ?? null` and do not have to know that.
     */
    public function contents(string $path, ?string $disk = null): ?string
    {
        return $this->files->contents($path, $disk);
    }

    public function delete(?string $path, ?string $disk = null): void
    {
        $this->files->delete($path, $disk);
    }

    /** The disk an attachment stored right now would go to. */
    public function diskName(): string
    {
        return $this->files->diskName();
    }

    public function previewMimeFor(string $path): ?string
    {
        return $this->files->previewMimeFor($path);
    }
}
