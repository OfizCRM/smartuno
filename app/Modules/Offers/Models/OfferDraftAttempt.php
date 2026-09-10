<?php

namespace App\Modules\Offers\Models;

use App\Modules\Shared\Models\Conversation;
use App\Modules\Shared\Models\Message;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One attempt by the agent to turn an inbound customer message into a draft
 * offer — including the attempts that produced nothing.
 *
 * @property int $id
 * @property int $workspace_id
 * @property int $conversation_id
 * @property int $message_id
 * @property string $status
 * @property string|null $reason
 * @property int|null $offer_id
 * @property int $tokens
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class OfferDraftAttempt extends Model
{
    /**
     * The row exists, the job has not finished. Written by the listener on the
     * inbound path, before anything that can be dropped.
     */
    public const STATUS_QUEUED = 'queued';

    /** An offer was produced; offer_id points at it. */
    public const STATUS_DRAFTED = 'drafted';

    /** Nothing was produced. reason says why, as a translation key. */
    public const STATUS_FAILED = 'failed';

    /**
     * The situation changed while the job waited out its debounce — a person
     * took the thread, the toggle was switched off, the subscription lapsed.
     * Not a failure: nothing went wrong, the answer is simply no longer wanted.
     */
    public const STATUS_SKIPPED = 'skipped';

    /** @var list<string> */
    public const STATUSES = [
        self::STATUS_QUEUED,
        self::STATUS_DRAFTED,
        self::STATUS_FAILED,
        self::STATUS_SKIPPED,
    ];

    // ─── reasons ────────────────────────────────────────────────────────────
    //
    // EVERY VALUE IN THE reason COLUMN IS A TRANSLATION KEY, and that is the
    // whole point of the constants: never a sentence, never anything a provider
    // said. A provider error body can echo the prompt back, and the prompt
    // carries the customer's own words and the firm's floor prices — none of
    // which may reach a column that is rendered on a page.
    //
    // THREE FAMILIES LAND IN THIS COLUMN, all of them keys, all of them needing
    // an entry in BOTH resources/js/locales/en.json and ro.json:
    //
    //   offers.ai_*        the ones below — what the listener and the job decide
    //   offers.draft_*     App\Modules\Offers\Services\OfferDrafter's own, for
    //                      every way the drafting itself did not work
    //   ai.structured.*    LlmGateway::structured()'s five, when one reaches
    //                      here unwrapped
    //
    // OfferController::reasonKey() is a shape check and not an allow-list for
    // exactly this reason: a new key on any of the three sides must not need an
    // edit somewhere else before it can be stored.

    /** The workspace has no catalogue to quote from yet. */
    public const REASON_NO_CATALOGUE = 'offers.ai_fail_no_catalogue';

    /** Nothing in the catalogue answered what the customer asked for. */
    public const REASON_NO_MATCH = 'offers.ai_fail_no_match';

    /** Anything else. The log line carries the detail; the customer sees nothing. */
    public const REASON_UNEXPECTED = 'offers.ai_fail_unexpected';

    /** A person took the conversation while the draft was queued. */
    public const REASON_HANDOVER = 'offers.ai_skip_handover';

    /** Drafting was switched off for this firm while the draft was queued. */
    public const REASON_DISABLED = 'offers.ai_skip_disabled';

    /** The client's subscription lapsed while the draft was queued. */
    public const REASON_READONLY = 'offers.ai_skip_readonly';

    /** The conversation or its messages are no longer there. */
    public const REASON_GONE = 'offers.ai_skip_gone';

    /**
     * Every reason this module decides for itself — the drafter's and the
     * gateway's are theirs to name. Kept as a list so the locale parity check
     * has something to read, and so a typo in a caller is a missing constant
     * rather than an untranslatable string in a column.
     *
     * @var list<string>
     */
    public const REASONS = [
        self::REASON_NO_CATALOGUE,
        self::REASON_NO_MATCH,
        self::REASON_UNEXPECTED,
        self::REASON_HANDOVER,
        self::REASON_DISABLED,
        self::REASON_READONLY,
        self::REASON_GONE,
    ];

    /**
     * The four that pair with STATUS_SKIPPED. Not failures — nothing went wrong,
     * the answer is simply no longer wanted, and the screen should not shout
     * about them.
     *
     * @var list<string>
     */
    public const SKIP_REASONS = [
        self::REASON_HANDOVER,
        self::REASON_DISABLED,
        self::REASON_READONLY,
        self::REASON_GONE,
    ];

    /**
     * Every writable column. workspace_id is included and still force-filled by
     * the listener: tenant attribution must not depend on what a $fillable list
     * happens to allow.
     */
    protected $fillable = [
        'workspace_id', 'conversation_id', 'message_id',
        'status', 'reason', 'offer_id', 'tokens',
    ];

    protected $casts = [
        'workspace_id' => 'integer',
        'conversation_id' => 'integer',
        'message_id' => 'integer',
        'offer_id' => 'integer',
        'tokens' => 'integer',
    ];

    /**
     * The draft this attempt produced, if it produced one.
     *
     * Does NOT scope the workspace — tenancy is applied by the caller, on every
     * query, before this is reached.
     *
     * @return BelongsTo<Offer, $this>
     */
    public function offer(): BelongsTo
    {
        return $this->belongsTo(Offer::class);
    }

    /** @return BelongsTo<Conversation, $this> */
    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    /** @return BelongsTo<Message, $this> */
    public function message(): BelongsTo
    {
        return $this->belongsTo(Message::class);
    }
}
