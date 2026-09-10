<?php

namespace App\Modules\Inbox\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Inbox\Services\MessageMediaStore;
use App\Modules\Shared\Models\Message;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;

/**
 * The one door into chat media that does not require a session.
 *
 * It exists because Messenger and Instagram do not accept an image, they accept
 * an ADDRESS: MessengerDriver posts
 *
 *     'attachment' => ['type' => 'image', 'payload' => ['url' => $url, ...]]
 *
 * and Meta's own servers fetch that URL. They have no session, no cookie and no
 * workspace, so the route the inbox uses cannot serve them, and until stage 5
 * the answer was that every such picture simply lived on a public disk for ever.
 *
 * What replaces that is a grant with an expiry. The URL is signed, valid for
 * thirty minutes, and names one message. Laravel checks the signature before
 * this method runs, so the authorisation is the signature — which is why there
 * is no workspace check here and why there must not be one: there is nobody to
 * check it against.
 *
 * Thirty minutes because Meta fetches within seconds of the POST, and because
 * `is_reusable => true` makes Meta re-host the file on its own CDN, so the
 * address is not needed again. If a send fails and is retried later, the driver
 * mints a new URL — signing is free, and a long-lived link is not.
 *
 * WhatsApp does not come through here. There the bytes are uploaded to the
 * Media API first and the send refers to a media_id, so nothing outside this
 * application ever needs to fetch the copy we keep.
 */
class OutboundMediaController extends Controller
{
    public function __construct(private readonly MessageMediaStore $media) {}

    public function show(Request $request, Message $message): Response
    {
        $payload = $message->getAttribute('payload') ?? [];
        $path = $payload['media_path'] ?? null;

        // The signature proves the URL came from us and has not expired. It says
        // nothing about whether the payload still names a file we would serve —
        // the row can have been edited, or the media replaced — so the address
        // is checked against the conversation's own workspace exactly as the
        // authenticated route checks it.
        // Null when the thread has been deleted — Conversation soft-deletes, so
        // the relation stops resolving while the message row remains. A message
        // with no thread has nobody to own its file, which is a 404 and not a
        // fatal on a property of null.
        $conversation = $message->conversation;
        abort_if($conversation === null, 404);

        abort_unless($this->media->addressableBy($path, (int) $conversation->workspace_id), 404);

        $contents = $this->media->contents((string) $path, $payload['media_disk'] ?? null);
        abort_if($contents === null, 404);

        $mime = $this->media->mimeFor((string) $path);

        // Meta needs a real content type or it rejects the attachment. A file we
        // have no safe type for is not something to send onward at all.
        abort_if($mime === null, 404);

        return response($contents, 200, [
            'Content-Type' => $mime,
            'Content-Length' => (string) strlen($contents),
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; sandbox",
            // No caching layer should keep this: the URL stops being valid long
            // before a shared cache would expire the body.
            'Cache-Control' => 'private, no-store',
            'Content-Disposition' => (new ResponseHeaderBag)->makeDisposition(
                ResponseHeaderBag::DISPOSITION_INLINE,
                basename((string) $path),
                'fisier',
            ),
        ]);
    }
}
