<?php

namespace App\Modules\Shared\Models;

use App\Support\Concerns\MasksDemoData;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One message in a thread.
 *
 * Declared for the same reason Conversation's are: without them every
 * `$message->payload` and `$message->conversation` is an "undefined property"
 * to static analysis, and dozens of them ended up suppressed in the baseline —
 * including the ones that decide which FILE a route serves.
 *
 * `payload` is the loose end of this model. It is a JSON column holding whatever
 * the channel sent plus the keys the server owns (media_path, media_disk,
 * attachments), and there is no type that can say so — which is exactly why the
 * server-owned keys are stripped from every incoming request rather than
 * trusted. See the allow-lists in InboxController::reply() and
 * MobileConversationController::reply().
 *
 * @property int $id
 * @property int $conversation_id
 * @property string $direction
 * @property string $channel
 * @property string $type
 * @property array<string, mixed>|null $payload
 * @property string|null $body
 * @property int|null $media_id
 * @property string $status
 * @property string|null $provider_message_id
 * @property array<string, mixed>|null $error_json
 * @property string $sent_by
 * @property int|null $user_id
 * @property Carbon|null $sent_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Conversation|null $conversation
 */
class Message extends Model
{
    use MasksDemoData;

    protected $fillable = [
        'conversation_id', 'direction', 'channel', 'type', 'payload', 'body',
        'media_id', 'status', 'provider_message_id', 'error_json',
        'sent_by', 'user_id', 'sent_at',
    ];

    /**
     * Scrub emails / phone numbers embedded in message text in demo mode. The
     * structured payload is left intact so interactive messages still render.
     *
     * @return array<string, string>
     */
    protected function demoMask(): array
    {
        return ['body' => 'text'];
    }

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'error_json' => 'array',
            'sent_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Conversation, $this> */
    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    /**
     * Where a file lives is the server's business, not the browser's.
     *
     * Every route that renders a thread ships the whole payload, so an email
     * attachment's `path` and `disk` land in page source that anyone who can
     * read the thread can also read. Neither is needed there: the attachment
     * route addresses a file by message and index, and the two things the UI
     * actually asks `path` are whether the file was stored at all and what
     * extension it has — answered here by `stored` and by `name`.
     *
     * Placed on toArray() rather than in a controller because three routes ship
     * messages today (the inbox thread, a contact's recent messages, an offer's
     * source conversation) and $hidden cannot reach inside a cast array. PHP
     * readers are unaffected: $message->payload still returns the full array,
     * which is what AttachmentController needs to serve the bytes.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $array = parent::toArray();

        if (! is_array($array['payload'] ?? null)) {
            return $array;
        }

        // Where the conversation's own media sits, which since stage 5 is a key
        // on the private disk. The browser addresses it by message id through a
        // route that resolves the workspace first, and never by path — so the
        // path in page source would be nothing but the half of an address that
        // becomes live the day anything can serve that disk directly.
        //
        // It is replaced by a flag rather than simply removed, because the thread
        // still has to know there is something to ASK for. It used to infer that
        // from media_id, which only WhatsApp sets: a picture sent on Messenger
        // has a stored file and no media_id, so dropping the path without saying
        // anything left those messages rendering nothing at all.
        if (($array['payload']['media_path'] ?? null) !== null) {
            $array['payload']['has_media'] = true;
        }

        unset($array['payload']['media_path'], $array['payload']['media_disk']);

        if (! is_array($array['payload']['attachments'] ?? null)) {
            return $array;
        }

        $array['payload']['attachments'] = array_map(
            static function ($entry) {
                if (! is_array($entry)) {
                    return $entry;
                }

                // Derived, not read: `stored` post-dates some rows, while a
                // non-null path has always meant the same thing.
                $entry['stored'] = ($entry['path'] ?? null) !== null;

                unset($entry['path'], $entry['disk']);

                return $entry;
            },
            $array['payload']['attachments'],
        );

        return $array;
    }
}
