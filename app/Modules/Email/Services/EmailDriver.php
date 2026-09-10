<?php

namespace App\Modules\Email\Services;

use App\Modules\Shared\Contracts\ChannelDriverInterface;
use App\Modules\Shared\Models\Message;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\Mailer\Mailer;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email as MimeEmail;

/**
 * Sends a reply out of the mailbox it arrived in.
 *
 * The customer sees an ordinary thread with the firm — same address, same
 * subject, threaded by the headers their own mail client uses. Nothing tells
 * them a platform is in the middle, which is the whole point.
 */
class EmailDriver implements ChannelDriverInterface
{
    public function __construct(
        private readonly MailboxTransport $transports,
        private readonly AttachmentStore $attachments,
    ) {}

    public function send(Message $message): string
    {
        $conversation = $message->conversation;
        $mailbox = $conversation->getAttribute('channelAccount');

        if (! $mailbox || $mailbox->channel !== 'email') {
            throw new \RuntimeException(__('This conversation is not attached to a mailbox.'));
        }

        $to = $conversation->getAttribute('contact')?->email;
        if (! $to) {
            throw new \RuntimeException(__('This contact has no email address.'));
        }

        $credentials = $mailbox->getAttribute('credentials') ?? [];
        $from = (string) ($credentials['email'] ?? $mailbox->display_name);

        // Our own Message-ID, stored on the row: the customer's reply names it in
        // In-Reply-To, and that is how their answer finds this thread again.
        $messageId = sprintf('%s@%s', Str::uuid(), $this->domainOf($from));

        $body = (string) $message->getAttribute('body');

        $mail = (new MimeEmail)
            ->from(new Address($from, (string) ($credentials['from_name'] ?? '')))
            ->to($to)
            ->subject($this->subjectFor($message, $conversation))
            // Both parts, from the same source. What the composer stores is
            // Markdown, which is already the plain-text version — so the text
            // part is the message as typed, and the HTML part is that same text
            // with the formatting applied. A client that refuses HTML still gets
            // something a person can read.
            ->text($body)
            ->html($this->htmlFor($body));

        foreach ((($message->getAttribute('payload') ?? [])['attachments'] ?? []) as $attachment) {
            if (empty($attachment['path'])) {
                // An inbound file that was over the per-file cap: recorded by
                // name so the thread shows it arrived, never written. A known
                // state, not a file that went missing.
                continue;
            }

            $contents = $this->attachments->contents(
                $attachment['path'],
                // Missing on everything received before the key existed, and
                // those are all on the fallback disk the store already knows.
                isset($attachment['disk']) ? (string) $attachment['disk'] : null,
            );

            if ($contents === null) {
                // The whole send is refused rather than quietly stripped of its
                // attachment. A mail cannot be recalled: a customer reading "see
                // attached" with nothing attached costs a phone call and there is
                // no fixing it afterwards, while a refusal costs a retry — every
                // caller of a driver already catches this, marks the message
                // failed and puts the reason in front of the operator. It is also
                // the call the inbox already makes one layer up, where a file
                // over the cap stops the reply instead of travelling without it.
                Log::warning('Email attachment file is missing from storage', [
                    'feature' => 'email.send',
                    'workspace_id' => (int) $conversation->getAttribute('workspace_id'),
                    'message_id' => $message->id,
                    'path' => $attachment['path'],
                ]);

                throw new \RuntimeException(__('An attachment on this message is no longer stored, so the email was not sent.'));
            }

            $mail->attach($contents, $attachment['name'] ?? 'atasament', $attachment['mime'] ?? null);
        }

        $headers = $mail->getHeaders();
        $headers->addIdHeader('Message-ID', $messageId);

        [$inReplyTo, $references] = $this->threadHeaders($conversation);
        if ($inReplyTo) {
            $headers->addIdHeader('In-Reply-To', $inReplyTo);
        }
        if ($references !== []) {
            $headers->addIdHeader('References', $references);
        }

        $this->mailerFor($credentials['smtp'] ?? [])->send($mail);

        return $messageId;
    }

    /**
     * The seam a test replaces: everything above it — addresses, subject,
     * threading headers — is what actually needs asserting, and none of it needs
     * a mail server.
     *
     * @param  array<string, mixed>  $smtp
     */
    protected function mailerFor(array $smtp): MailerInterface
    {
        return new Mailer($this->transports->make($smtp));
    }

    /**
     * A mailbox is read on a schedule, not pushed to, so there is no webhook.
     * The contract asks for the method; PollMailboxJob is where the work is.
     */
    /** @return array<int, Message> */
    public function receiveWebhook(Request $request): array
    {
        return [];
    }

    public function verifyCreds(): bool
    {
        return true;
    }

    /**
     * Keep the customer's subject, prefixed once.
     *
     * A subject typed in the composer wins; otherwise the thread's own subject is
     * reused so their mail client keeps the messages together even if the headers
     * are lost somewhere along the way.
     */
    private function subjectFor(Message $message, mixed $conversation): string
    {
        $typed = trim((string) (($message->getAttribute('payload') ?? [])['subject'] ?? ''));
        if ($typed !== '') {
            return $typed;
        }

        $last = Message::where('conversation_id', $conversation->id)
            ->where('direction', 'in')
            ->latest('sent_at')
            ->first();

        $subject = trim((string) (($last?->getAttribute('payload') ?? [])['subject'] ?? ''));
        if ($subject === '') {
            return __('Message from :name', ['name' => config('app.name')]);
        }

        return Str::startsWith(Str::lower($subject), ['re:', 'răspuns:']) ? $subject : 'Re: '.$subject;
    }

    /**
     * @return array{0: string|null, 1: array<int, string>}
     */
    private function threadHeaders(mixed $conversation): array
    {
        $ids = Message::where('conversation_id', $conversation->id)
            ->whereNotNull('provider_message_id')
            ->orderBy('sent_at')
            ->pluck('provider_message_id')
            ->all();

        if ($ids === []) {
            return [null, []];
        }

        // References carries the whole chain and can run to kilobytes on a long
        // thread; the last few are what every client actually matches on.
        return [end($ids), array_slice($ids, -8)];
    }

    /**
     * The HTML part, rendered from the Markdown the composer produced.
     *
     * `html_input: escape` is not optional: without it CommonMark passes raw
     * HTML straight through, and the body of a message is text a person typed —
     * or, on a forward, text that came from outside. Unsafe link schemes are
     * dropped for the same reason.
     */
    private function htmlFor(string $body): string
    {
        return Str::markdown($body, [
            'html_input' => 'escape',
            'allow_unsafe_links' => false,
        ]);
    }

    private function domainOf(string $email): string
    {
        $domain = substr(strrchr($email, '@') ?: '', 1);

        return $domain !== '' ? $domain : 'localhost';
    }
}
