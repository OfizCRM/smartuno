<?php

namespace App\Modules\Inbox\Services;

use App\Modules\Shared\Services\PrivateFileStore;
use App\Services\PrivateStorageManager;
use Illuminate\Http\UploadedFile;

/**
 * Where a conversation's photos, videos, voice notes and files live.
 *
 * The sibling of AttachmentStore, and deliberately the same shape: a directory
 * constant, a thin wrapper over PrivateFileStore for the writing, and the one
 * or two rules that are only true of this feature. PrivateFileStore does the
 * parts that are the same everywhere — uuid names, an extension from the
 * declared MIME rather than from anything a sender chose, the disk recorded
 * beside the path.
 *
 * What is only true here is the workspace segment. A message has no
 * workspace_id of its own — `messages` carries only conversation_id, and ids
 * are one global sequence — so a chat file has nothing on its own row that says
 * whose it is. Putting the firm in the KEY gives the read side something to
 * check, which is what stops a stale or tampered payload from addressing the
 * firm next door's picture through a route that has already agreed the MESSAGE
 * belongs to the caller.
 *
 * That segment is a correctness check, not the privacy control. Privacy comes
 * from the disk: these files are on the private one and reach a browser only
 * through a controller that resolves the workspace first. Until stage 5 they
 * sat on the public disk and every one of them was readable by anyone holding
 * the URL — which is what this class exists to end.
 */
class MessageMediaStore
{
    /** Where in the private disk a conversation's files go. */
    public const DIRECTORY = 'message-media';

    public function __construct(private readonly PrivateFileStore $files) {}

    /**
     * Keep bytes we already hold — media downloaded from the Graph API.
     *
     * @return array{name: string, mime: string, size: int, path: string, disk: string}
     */
    public function put(int $workspaceId, string $name, string $mime, string $contents): array
    {
        return $this->files->put($this->directoryFor($workspaceId), $name, $mime, $contents);
    }

    /**
     * Keep a file somebody uploaded in the composer.
     *
     * The MIME is the DETECTED one, never the browser's Content-Type header and
     * never the filename: getMimeType() reads the file itself. The original name
     * is carried only as a label for the thread; it never reaches the key.
     *
     * @return array{name: string, mime: string, size: int, path: string, disk: string}
     */
    public function putUpload(int $workspaceId, UploadedFile $file): array
    {
        return $this->put(
            $workspaceId,
            (string) $file->getClientOriginalName(),
            $file->getMimeType() ?? 'application/octet-stream',
            (string) $file->get(),
        );
    }

    /**
     * Whether a recorded path is one this workspace could have written.
     *
     * Answered from the key alone, deliberately: the caller reaches this before
     * any read, so nothing has touched the file yet when the question is asked.
     */
    public function addressableBy(?string $path, int $workspaceId): bool
    {
        if (! is_string($path) || $path === '') {
            return false;
        }

        $prefix = $this->directoryFor($workspaceId).'/';

        if (! str_starts_with($path, $prefix)) {
            return false;
        }

        // What is left must be ONE file name — no separator of any kind, and no
        // character that could become one.
        //
        // Stated as a shape rather than as a list of things to reject, because a
        // deny-list on paths is the pattern that keeps failing. Rejecting '..'
        // alone let 'message-media/1/%2e%2e/2/x.jpg' through: harmless in fact,
        // since Flysystem does not decode and the key is one WE wrote, but the
        // guard read as though it had checked something it had not. A traversal
        // needs a separator to be a traversal, so refusing separators refuses
        // all of them at once — encoded, doubled, backslashed or unicode.
        //
        // Deliberately NOT a check that the name is a uuid, though put() only
        // ever writes those. Everything inside this workspace's directory is
        // this workspace's, so matching the naming scheme would add nothing to
        // the guarantee and would silently start refusing files the day the
        // scheme changed.
        //
        // The trailing slash on the prefix is load-bearing too: without it
        // workspace 1 matches workspace 11's directory, which is the same
        // unpadded-prefix fault stage 4 removed from serveMedia.
        return (bool) preg_match('/^[A-Za-z0-9._-]{1,120}$/', substr($path, strlen($prefix)));
    }

    /**
     * The bytes, from the disk the entry says they are on.
     *
     * A null disk means the entry predates the key. PrivateFileStore applies its
     * own fallback for that rather than this class repeating it.
     */
    public function contents(string $path, ?string $disk = null): ?string
    {
        return $this->files->contents($path, $disk);
    }

    /**
     * The content type to serve a stored file as, or null when there is not a
     * safe one — in which case the caller sends it as a download instead.
     */
    public function mimeFor(string $path): ?string
    {
        return $this->files->previewMimeFor($path);
    }

    /**
     * Whether a recorded entry sits on a disk private files live on.
     *
     * Rows written before stage 5 name a key on the PUBLIC disk, and those are
     * still readable — from there, through StorageManager, exactly as they
     * always were. This is how a reader tells the two apart without guessing
     * from the shape of the path, which is the same question, asked the same
     * way, as AttachmentController::addressable(): membership of the private
     * map, not a comparison against whatever the public disk is called today.
     * The public one is 'public' on a fresh install and 'r2' or 's3' once a
     * provider is chosen, so a comparison would quietly stop being true.
     */
    public function isPrivate(?string $disk): bool
    {
        return is_string($disk) && in_array($disk, PrivateStorageManager::PRIVATE_DISK_MAP, true);
    }

    private function directoryFor(int $workspaceId): string
    {
        return self::DIRECTORY.'/'.$workspaceId;
    }
}
