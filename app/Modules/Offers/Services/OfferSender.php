<?php

namespace App\Modules\Offers\Services;

use App\Events\MessageSent;
use App\Modules\Documents\Models\Document;
use App\Modules\Documents\Services\DocumentImporter;
use App\Modules\Email\Services\AttachmentStore;
use App\Modules\Offers\Models\Offer;
use App\Modules\Shared\Models\Conversation;
use App\Modules\Shared\Models\Message;
use App\Modules\Shared\Services\ChannelManager;
use App\Modules\Shared\Services\PrivateFileStore;
use App\Modules\Whatsapp\Services\CloudApiClient;
use Illuminate\Support\Facades\Log;

/**
 * Putting the offer in front of the customer, on the channel they wrote from.
 *
 * Deliberately NOT a second copy of InboxController::reply(). That method is
 * already duplicated in Api\V1\MobileConversationController, and a third copy of
 * the send semantics would mean a change to how we send lands in three places.
 * This builds the Message row and hands it to the same
 * ChannelManager::driver()->send() the inbox uses.
 *
 * What each channel can carry is not the same, and the difference is visible to
 * the operator rather than silently swallowed:
 *
 *  - email     — the PDF goes as a real attachment.
 *  - whatsapp  — the PDF is uploaded to Meta's Media API and sent as a document
 *                with the message as its caption.
 *  - messenger — no document path exists in either driver, so the offer goes as
 *  - instagram   text and the caller is told the PDF did not travel with it.
 */
class OfferSender
{
    /** What Meta accepts as a document, and what our own message row calls it. */
    private const PDF_MIME = 'application/pdf';

    public function __construct(
        private readonly ChannelManager $channels,
        private readonly PrivateFileStore $files,
        private readonly DocumentImporter $importer,
    ) {}

    /**
     * Send the offer into its conversation.
     *
     * @return array{message: Message, error: string|null, pdf_sent: bool}
     */
    public function send(Offer $offer, Conversation $conversation, string $body, ?Document $pdf): array
    {
        $channel = $conversation->resolvedChannel();

        if ($channel === null) {
            throw new \RuntimeException(__('This conversation is not attached to a channel.'));
        }

        [$type, $payload, $pdfSent] = $this->carry($channel, $conversation, $body, $pdf);

        $message = Message::create([
            'conversation_id' => $conversation->id,
            'direction' => 'out',
            'channel' => $channel,
            'type' => $type,
            'body' => $body,
            'payload' => $payload,
            'status' => 'queued',
            'sent_by' => 'human',
            'sent_at' => now(),
        ]);

        $error = null;

        try {
            $providerId = $this->channels->driver($channel)->send($message);
            $message->update(['status' => 'sent', 'provider_message_id' => $providerId]);
        } catch (\Throwable $e) {
            // The offer is not marked sent when the send failed — the operator
            // must be able to try again rather than find a "sent" offer the
            // customer never received.
            $error = $e->getMessage();
            Log::error('Offer send failed', [
                'offer_id' => $offer->id,
                'conversation_id' => $conversation->id,
                'channel' => $channel,
                'error' => $error,
            ]);
            $message->update(['status' => 'failed', 'error_json' => ['message' => $error]]);
        }

        $conversation->update(['last_message_at' => now()]);

        // getAttribute rather than the property: Conversation carries no
        // @property docblock, and inventing one in a model thirty files depend
        // on is a bigger change than this line deserves.
        if ($conversation->getAttribute('last_inbound_at') && ! $conversation->getAttribute('first_response_at')) {
            $conversation->update(['first_response_at' => now()]);
        }

        $message->load('conversation');
        MessageSent::dispatch($message);

        return ['message' => $message, 'error' => $error, 'pdf_sent' => $pdfSent && $error === null];
    }

    /**
     * What this channel can actually carry.
     *
     * @return array{0: string, 1: array<string, mixed>|null, 2: bool}
     */
    private function carry(string $channel, Conversation $conversation, string $body, ?Document $pdf): array
    {
        if ($pdf === null) {
            return ['text', null, false];
        }

        if ($channel === 'email') {
            $entry = $this->importer->toAttachment($pdf, 0, AttachmentStore::MAX_MESSAGE_BYTES);

            // Over the cap, the message still goes — an offer the customer can
            // read is better than one that did not send because of its own PDF.
            return $entry === null
                ? ['text', null, false]
                : ['text', ['attachments' => [$entry]], true];
        }

        if ($channel === 'whatsapp') {
            $mediaId = $this->uploadPdf((int) $conversation->getAttribute('workspace_id'), $pdf);

            return $mediaId === null
                ? ['text', null, false]
                : ['document', [
                    'media_id' => $mediaId,
                    'filename' => $pdf->name,
                    'caption' => $body,
                ], true];
        }

        // Messenger and Instagram have no document path in their drivers at all.
        return ['text', null, false];
    }

    /**
     * Hand the PDF to Meta and keep the id they give back.
     *
     * uploadMedia() wants a path on disk, and ours lives on the private disk
     * behind a Flysystem adapter that has no local path to give. The file is
     * written to a temporary one for the length of the upload and removed
     * afterwards — it must not linger in the system temp directory.
     */
    private function uploadPdf(int $workspaceId, Document $pdf): ?string
    {
        $contents = $this->files->contents($pdf->path, $pdf->disk);

        if ($contents === null) {
            // The operator is not left in the dark: the offer still goes and the
            // controller tells them the PDF did not travel with it. What that
            // sentence says, though, is that the channel cannot carry a file,
            // and on WhatsApp that is untrue — so the reason goes in the log,
            // where it is the only trace that the PDF existed and then did not.
            // The message itself still leaves: an offer the customer can read
            // beats one held back by its own attachment.
            Log::warning('Offer PDF file is missing from storage', [
                'feature' => 'offers.send.whatsapp',
                'workspace_id' => $workspaceId,
                'document_id' => $pdf->id,
                'path' => $pdf->path,
            ]);

            return null;
        }

        $client = CloudApiClient::forWorkspace($workspaceId);

        if ($client === null) {
            return null;
        }

        $temp = tempnam(sys_get_temp_dir(), 'offer_').'.pdf';

        try {
            file_put_contents($temp, $contents);

            return $client->uploadMedia($temp, self::PDF_MIME);
        } catch (\Throwable $e) {
            // A failed upload costs the attachment, not the message.
            Log::warning('Offer PDF upload failed', ['error' => $e->getMessage()]);

            return null;
        } finally {
            @unlink($temp);
        }
    }
}
