<?php

namespace App\Modules\Documents\Services;

use App\Modules\Documents\Models\Document;
use App\Modules\Shared\Models\Conversation;
use App\Modules\Shared\Models\Message;
use App\Modules\Shared\Services\PrivateFileStore;

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
     * @return Document|null null when there is no room left
     */
    public function fromAttachment(
        Conversation $conversation,
        Message $message,
        array $attachment,
        ?int $folderId,
        ?int $userId,
    ): ?Document {
        $workspaceId = (int) $conversation->getAttribute('workspace_id');
        $contents = $this->files->contents((string) $attachment['path']);

        if ($contents === null) {
            return null;
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
     * @return array{name: string, mime: string, size: int, path: string, stored: bool, document_id: int}|null
     */
    public function toAttachment(Document $document, int $alreadyStored, int $capBytes): ?array
    {
        $contents = $this->files->contents($document->path);

        if ($contents === null || ($alreadyStored + strlen($contents)) > $capBytes) {
            return null;
        }

        $entry = $this->files->put('email-attachments', $document->name, $document->mime, $contents);

        return $entry + ['stored' => true, 'document_id' => $document->id];
    }
}
