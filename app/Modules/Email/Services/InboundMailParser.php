<?php

namespace App\Modules\Email\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use League\HTMLToMarkdown\HtmlConverter;

/**
 * Turns one received email into the flat shape the rest of the module works on.
 *
 * Kept free of any IMAP type so it can be exercised with plain arrays: the parts
 * that go wrong here — quoted history, tracking pixels, autoresponders — are the
 * parts a live mailbox makes hardest to test.
 */
class InboundMailParser
{
    /** messages.body is TEXT: 65,535 bytes, and fewer characters under utf8mb4. */
    private const MAX_BODY = 55000;

    /**
     * Senders whose mail must never start a conversation a person is expected to
     * answer. A human being does not read replies to no-reply@.
     */
    private const ROBOT_SENDERS = '/^(mailer-daemon|postmaster|no-?reply|do-?not-?reply|bounce|notifications?|newsletter)@/i';

    /**
     * @param  array<string, mixed>  $headers  header name => value, in either spelling
     * @return array{
     *     message_id: string|null, in_reply_to: string|null, references: array<int, string>,
     *     from_email: string, from_name: string|null, subject: string, text: string,
     *     date: Carbon, automated: bool, automated_reason: string|null
     * }
     */
    public function parse(
        array $headers,
        string $fromEmail,
        ?string $fromName,
        string $subject,
        ?string $html,
        ?string $text,
        ?Carbon $date = null,
    ): array {
        $headers = $this->normaliseHeaders($headers);
        $automated = $this->automatedReason($headers, $fromEmail);
        $subject = trim($this->decodeHeader($subject));

        return [
            'message_id' => $this->cleanId($headers['message-id'] ?? null),
            'in_reply_to' => $this->cleanId($headers['in-reply-to'] ?? null),
            'references' => $this->referenceIds($headers['references'] ?? null),
            'from_email' => strtolower(trim($fromEmail)),
            'from_name' => $this->displayName($fromName),
            'subject' => $subject !== '' ? $subject : __('(no subject)'),
            'text' => $this->body($html, $text),
            'date' => $date ?? Carbon::now(),
            'automated' => $automated !== null,
            'automated_reason' => $automated,
        ];
    }

    /**
     * One spelling for header names, whichever spelling arrived.
     *
     * The IMAP library normalises every header name to underscores, so it hands
     * back `message_id` and `auto_submitted`; RFC 5322, and everything below,
     * says `Message-ID` and `Auto-Submitted`. Reading the library's array with
     * the RFC spelling silently found nothing, which cost de-duplication,
     * threading and the automated-mail check all at once.
     *
     * @param  array<string, mixed>  $headers
     * @return array<string, string>
     */
    public function normaliseHeaders(array $headers): array
    {
        $out = [];

        foreach ($headers as $name => $value) {
            $key = str_replace('_', '-', strtolower(trim((string) $name)));
            $out[$key] = is_array($value)
                ? implode(', ', array_map(static fn ($item): string => (string) $item, $value))
                : (string) $value;
        }

        return $out;
    }

    /**
     * Decode RFC 2047 encoded words, e.g. `=?UTF-8?B?Q29tYW5kxIMgbm91xIM=?=`.
     *
     * The IMAP library's default decoder calls imap_utf8(), and ext-imap was
     * removed from PHP in 8.4 — so on this platform it returns the header
     * untouched and a Romanian subject reaches the inbox as base64. Kept here as
     * well as in the client configuration because it is cheap, it is idempotent,
     * and it is the only one of the two that a test can reach.
     */
    public function decodeHeader(?string $value): string
    {
        $value = (string) $value;

        if (! str_contains($value, '=?')) {
            return $value;
        }

        $decoded = iconv_mime_decode($value, ICONV_MIME_DECODE_CONTINUE_ON_ERROR, 'UTF-8');

        return $decoded === false ? $value : $decoded;
    }

    /**
     * The sender's name, fit to store on a contact.
     *
     * Quoted forms ("Farmacia Verde" <office@...>) keep their quotation marks in
     * the parsed header, and the contact list then shows a name starting with a
     * stray double quote.
     */
    private function displayName(?string $name): ?string
    {
        $name = trim($this->decodeHeader($name), " \t\n\r\0\x0B\"");

        return $name !== '' ? $name : null;
    }

    /**
     * Readable text for the inbox bubble.
     *
     * HTML is converted server-side rather than rendered: the project forbids
     * dangerouslySetInnerHTML, and mail from outside is exactly the content that
     * rule exists for. Images are dropped before conversion — a 1x1 in a received
     * message tells the sender the moment the clinic opened it.
     */
    public function body(?string $html, ?string $text): string
    {
        $body = trim((string) $text);

        if ($body === '' && $html) {
            $stripped = preg_replace('#<img\b[^>]*>#i', '', $html) ?? $html;
            // Quoted history lives in blockquotes; drop it before conversion so
            // the markdown does not carry the whole thread each time.
            $stripped = preg_replace('#<blockquote\b.*?</blockquote>#is', '', $stripped) ?? $stripped;

            try {
                $body = (new HtmlConverter(['strip_tags' => true, 'remove_nodes' => 'script style head']))
                    ->convert($stripped);
            } catch (\Throwable) {
                $body = strip_tags($stripped);
            }
        }

        return Str::limit($this->stripQuotedReply($body), self::MAX_BODY, '');
    }

    /**
     * Cut the conversation history a mail client staples underneath a reply.
     *
     * Only from the first marker onwards, and only when something is left above
     * it — a message that is nothing but a quote is better shown whole than shown
     * empty.
     */
    public function stripQuotedReply(string $body): string
    {
        $markers = [
            '/^-{2,}\s*(Original Message|Mesaj original)\s*-{2,}$/im',
            '/^\s*On .+ wrote:\s*$/im',
            '/^\s*(Pe|În) .+ a scris:\s*$/imu',
            '/^\s*_{10,}\s*$/m',
            '/^>{1,}\s?.*(\R>{1,}\s?.*){2,}$/m',
        ];

        $cut = strlen($body);
        foreach ($markers as $pattern) {
            if (preg_match($pattern, $body, $m, PREG_OFFSET_CAPTURE) === 1) {
                $cut = min($cut, $m[0][1]);
            }
        }

        $kept = rtrim(substr($body, 0, $cut));

        return $kept !== '' ? $kept : trim($body);
    }

    /**
     * Why this message must not be answered, or null when a person sent it.
     *
     * The chatbot answering an out-of-office, a bounce or a newsletter is not a
     * cosmetic problem: two mail robots replying to each other is a loop that
     * runs until someone notices the bill.
     *
     * @param  array<string, mixed>  $headers
     */
    public function automatedReason(array $headers, string $fromEmail): ?string
    {
        // Idempotent, and this method is public: a caller that hands it the
        // library's underscore spelling must get the same answer as parse().
        $headers = $this->normaliseHeaders($headers);
        $get = fn (string $k) => strtolower(trim($headers[$k] ?? ''));

        if ($get('auto-submitted') !== '' && $get('auto-submitted') !== 'no') {
            return 'auto-submitted';
        }
        if (in_array($get('precedence'), ['bulk', 'list', 'junk'], true)) {
            return 'precedence';
        }
        foreach (['list-id', 'list-unsubscribe', 'x-autoreply', 'x-autorespond', 'x-auto-response-suppress'] as $header) {
            if ($get($header) !== '') {
                return $header;
            }
        }
        // An empty envelope sender is how a bounce identifies itself.
        if (in_array($get('return-path'), ['<>', ''], true) && isset($headers['return-path'])) {
            return 'bounce';
        }
        if (preg_match(self::ROBOT_SENDERS, trim($fromEmail)) === 1) {
            return 'robot-sender';
        }

        return null;
    }

    private function cleanId(?string $value): ?string
    {
        $value = trim((string) $value, " \t\n\r\0\x0B<>");

        return $value !== '' ? Str::limit($value, 250, '') : null;
    }

    /** @return array<int, string> */
    private function referenceIds(?string $value): array
    {
        if (! $value) {
            return [];
        }

        // Two shapes reach here: the raw header, `<a@x> <b@y>`, and the form the
        // IMAP library returns, which has already stripped the brackets and
        // joined the ids with a comma. Matching only the first shape is why
        // threading through References never found a parent.
        $ids = array_filter(
            array_map('trim', preg_split('/[\s,]+/', str_replace(['<', '>'], ' ', $value)) ?: []),
            static fn (string $id): bool => $id !== '',
        );

        // Newest last in the header; the immediate parent is the one worth trying
        // first when threading.
        return array_reverse(array_slice(array_values($ids), -10));
    }
}
