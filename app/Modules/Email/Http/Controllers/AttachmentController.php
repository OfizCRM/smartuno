<?php

namespace App\Modules\Email\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Email\Services\AttachmentStore;
use App\Modules\Shared\Models\Conversation;
use App\Modules\Shared\Models\Message;
use App\Services\PrivateStorageManager;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;

/**
 * Hands one email attachment back to the person who owns it.
 *
 * Streamed through the application rather than redirected to a storage URL: the
 * files live on the same disk for every tenant, and a public link is a link
 * anybody can forward. The workspace is checked on the conversation, and the
 * message is checked against that conversation, before anything is read.
 */
class AttachmentController extends Controller
{
    public function __construct(private readonly AttachmentStore $store) {}

    public function show(Request $request, Conversation $conversation, Message $message, int $index): Response
    {
        abort_unless((int) $conversation->getAttribute('workspace_id') === (int) $request->user()->workspace_id, 403);
        abort_unless((int) $message->getAttribute('conversation_id') === (int) $conversation->id, 404);

        $attachment = (($message->getAttribute('payload') ?? [])['attachments'] ?? [])[$index] ?? null;
        abort_unless(is_array($attachment) && ! empty($attachment['path']), 404);

        // Belt and braces, for rows written before the caller stopped being
        // able to set these keys. The workspace check above says this
        // conversation is mine; it says nothing about which file the payload
        // names, and both halves of that address were once attacker-controlled.
        //
        // The path must be under the directory this controller serves, and the
        // disk must be one that holds private files. Anything else is answered
        // 404 rather than read — a 403 would confirm the file exists.
        abort_unless($this->addressable((string) $attachment['path'], $attachment['disk'] ?? null), 404);

        // No disk key means the entry predates the column, and there is
        // nothing anywhere that could say otherwise — null lets
        // PrivateFileStore apply its own fallback rather than repeating it here.
        $contents = $this->store->contents(
            $attachment['path'],
            isset($attachment['disk']) ? (string) $attachment['disk'] : null,
        );
        abort_if($contents === null, 404);

        $name = $attachment['name'] ?? 'atasament';

        return $request->boolean('preview')
            ? $this->inline($attachment['path'], $name, $contents)
            : $this->download($name, $contents);
    }

    /**
     * The default: a download, never rendered.
     *
     * An HTML or SVG attachment displayed inline would be running a stranger's
     * markup on this application's own origin, against the session of the person
     * who opened it — which is why the type is not even consulted here.
     */
    private function download(string $name, string $contents): Response
    {
        return response($contents, 200, [
            'Content-Type' => 'application/octet-stream',
            'Content-Length' => (string) strlen($contents),
            'X-Content-Type-Options' => 'nosniff',
            'Content-Disposition' => (new ResponseHeaderBag)->makeDisposition(
                ResponseHeaderBag::DISPOSITION_ATTACHMENT,
                $name,
                'atasament',
            ),
        ]);
    }

    /**
     * Shown in the page, for the few types where that is safe.
     *
     * Three things make it safe, and all three are needed. The content type comes
     * from the extension this application chose when it stored the file, never
     * from what the sender declared. The list of types it can produce excludes
     * everything a browser executes. And the response carries the same sandbox
     * policy Laravel puts on its own storage route, which is what stops script
     * inside a PDF from running.
     *
     * A type outside that list is not quietly downloaded instead: the caller
     * asked to display something that must not be displayed, and answering 404
     * says so rather than looking like it worked.
     */
    private function inline(string $path, string $name, string $contents): Response
    {
        $mime = $this->store->previewMimeFor($path);
        abort_if($mime === null, 404);

        return response($contents, 200, [
            'Content-Type' => $mime,
            'Content-Length' => (string) strlen($contents),
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; style-src 'unsafe-inline'; sandbox",
            'Content-Disposition' => (new ResponseHeaderBag)->makeDisposition(
                ResponseHeaderBag::DISPOSITION_INLINE,
                $name,
                'atasament',
            ),
        ]);
    }

    /**
     * Whether this entry names a file this controller is allowed to serve.
     *
     * Two independent conditions, both required. The directory is the one
     * AttachmentStore writes to and nothing else; a path that walks out of it,
     * or names the document library, or names anything on the public disk, is
     * not an email attachment however it got into the payload. And the disk has
     * to be one that holds private files — the map is the same one
     * PrivateStorageManager writes into the column, so a name outside it was
     * never written by us.
     */
    private function addressable(string $path, mixed $disk): bool
    {
        if (! str_starts_with($path, AttachmentStore::DIRECTORY.'/') || str_contains($path, '..')) {
            return false;
        }

        return $disk === null
            || (is_string($disk) && in_array($disk, PrivateStorageManager::PRIVATE_DISK_MAP, true));
    }
}
