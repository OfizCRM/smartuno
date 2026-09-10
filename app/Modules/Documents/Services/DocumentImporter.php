<?php

namespace App\Modules\Documents\Services;

use App\Modules\Documents\Models\Document;
use App\Modules\Shared\Models\Conversation;
use App\Modules\Shared\Models\Message;
use App\Modules\Shared\Services\PrivateFileStore;
use Illuminate\Support\Facades\Log;

/**
 * Moves files between the inbox and the library.
 *
 * Always a copy, never a shared path. A document deleted next spring must not
 * empty an email sent last autumn, and an attachment purged from a thread must
 * not take the filed contract with it. The two rows remember each other — the
 * document knows which message it came from — but they own their own bytes.
 */
class DocumentImporter
{
    private const DIRECTORY = 'documents';

    public function __construct(
        private readonly PrivateFileStore $files,
        private readonly DocumentQuota $quota,
    ) {}

    /**
     * File one of a message's attachments into the library.
     *
     * @param  array<string, mixed>  $attachment  an entry from messages.payload
     * @return Document|null null when there is no room left; a file that has
     *                       gone from the disk aborts with a 404 instead
     */
    public function fromAttachment(
        Conversation $conversation,
        Message $message,
        array $attachment,
        ?int $folderId,
        ?int $userId,
    ): ?Document {
        $workspaceId = (int) $conversation->getAttribute('workspace_id');
        // An entry written before the disk key existed simply has none, and
        // there is nothing to backfill it from — null is handed straight to
        // PrivateFileStore, whose own fallback is the literal 'local' those
        // files are on. The cast is only to satisfy the signature; it must not
        // become a `?? 'local'` here, or the fallback would live in two places
        // and drift the day one of them changes.
        $disk = isset($attachment['disk']) ? (string) $attachment['disk'] : null;
        $contents = $this->files->contents((string) $attachment['path'], $disk);

        if ($contents === null) {
            // Not the same thing as "no room left", which is all a null return
            // means to the caller: the bytes this attachment points at are gone.
            // Somebody pressed a button and is waiting for a document to appear
            // in the library, so they are told — rather than being shown a quota
            // message about a file that was never read, or nothing at all.
            Log::warning('Message attachment file is missing from storage', [
                'feature' => 'documents.import_from_message',
                'workspace_id' => $workspaceId,
                'message_id' => $message->id,
                'path' => (string) $attachment['path'],
            ]);

            abort(404, __('This attachment is no longer stored, so it could not be saved to documents.'));
        }

        if (! $this->quota->fits($workspaceId, strlen($contents))) {
            return null;
        }

        $entry = $this->files->put(
            self::DIRECTORY,
            (string) ($attachment['name'] ?? 'fisier'),
            (string) ($attachment['mime'] ?? 'application/octet-stream'),
            $contents,
        );

        $document = Document::create([
            'workspace_id' => $workspaceId,
            'folder_id' => $folderId,
            // The thread already says who this is about, so the link comes for
            // free — which is the whole point of filing it from here.
            'contact_id' => $conversation->getAttribute('contact_id'),
            'name' => $entry['name'],
            'path' => $entry['path'],
            // The copy's disk, which is not necessarily the attachment's: this
            // is a fresh write, and mid-migration the two differ.
            'disk' => $entry['disk'],
            'mime' => $entry['mime'],
            'extension' => pathinfo($entry['path'], PATHINFO_EXTENSION),
            'size_bytes' => $entry['size'],
            'source' => 'conversation',
            'source_message_id' => $message->id,
            'created_by' => $userId,
        ]);

        $this->quota->forget($workspaceId);

        return $document;
    }

    /**
     * The payload entry for sending a filed document back out on a thread.
     *
     * Same shape as an uploaded attachment, plus the document it was copied
     * from, so a person reading the thread later can find the original.
     *
     * @return array{name: string, mime: string, size: int, path: string, disk: string, stored: bool, document_id: int}|null
     */
    public function toAttachment(Document $document, int $alreadyStored, int $capBytes): ?array
    {
        $contents = $this->files->contents($document->path, $document->disk);

        if ($contents === null) {
            // Both callers already refuse the send on a null and tell the person
            // — but what they tell them is "too large", which is the one thing
            // this is not, and an investigation would start in the wrong place.
            // The truth goes in the log. Still a null: returning it is what lets
            // them delete the attachments already copied for this message, and
            // an abort from inside their loop would leave those bytes behind.
            Log::warning('Document file is missing from storage', [
                'feature' => 'documents.attach_to_message',
                'workspace_id' => (int) $document->workspace_id,
                'document_id' => $document->id,
                'path' => $document->path,
            ]);

            return null;
        }

        if (($alreadyStored + strlen($contents)) > $capBytes) {
            return null;
        }

        // put() puts `disk` in the entry, so the payload the thread stores
        // names the disk this copy went to — which is the document's disk only
        // by coincidence, and stops being it the moment a move is under way.
        $entry = $this->files->put('email-attachments', $document->name, $document->mime, $contents);

        return $entry + ['stored' => true, 'document_id' => $document->id];
    }
}
