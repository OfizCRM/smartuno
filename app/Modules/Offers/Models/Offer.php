<?php

namespace App\Modules\Offers\Models;

use App\Models\User;
use App\Modules\Catalog\Support\Money;
use App\Modules\Shared\Models\Contact;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * One offer: a numbered price quote a firm builds by hand from its catalogue
 * and sends to a customer.
 *
 * Money is an integer number of bani in every column. 24000 is 240,00 lei.
 *
 * @property int $id
 * @property string $uuid
 * @property int $workspace_id
 * @property string $number
 * @property int|null $contact_id
 * @property int|null $conversation_id
 * @property string|null $channel
 * @property string $status
 * @property string $source
 * @property string $currency
 * @property int $subtotal_cents
 * @property string|null $discount_label
 * @property int $discount_cents
 * @property int $shipping_cents
 * @property string|null $vat_status
 * @property string|null $vat_rate
 * @property int $vat_cents
 * @property int $total_cents
 * @property Carbon|null $valid_until
 * @property string|null $notes
 * @property string|null $message_body
 * @property int|null $pdf_document_id
 * @property int|null $created_by
 * @property Carbon|null $sent_at
 * @property int|null $sent_by
 * @property Carbon|null $decided_at
 * @property string|null $decision
 * @property array<string, mixed>|null $ai_json
 * @property string|null $ai_reason
 * @property bool|null $edited_before_send
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 */
class Offer extends Model
{
    use SoftDeletes;

    /**
     * The states an offer can be in, and the tabs the list screen offers.
     *
     * 'sent' is first written in stage 2b; 'expired' is set by a sweep once
     * valid_until has passed.
     *
     * @var list<string>
     */
    public const STATUSES = ['draft', 'sent', 'accepted', 'refused', 'expired'];

    /**
     * Every writable column. An omission here silently drops the value on save,
     * which is a bug that looks like the form not working — so the list is kept
     * complete, including the columns only the later stages write.
     */
    protected $fillable = [
        'workspace_id', 'number', 'contact_id', 'conversation_id', 'channel',
        'status', 'source', 'currency', 'subtotal_cents', 'discount_label',
        'discount_cents', 'shipping_cents', 'vat_status', 'vat_rate',
        'vat_cents', 'total_cents', 'valid_until', 'notes', 'message_body',
        'pdf_document_id', 'created_by', 'sent_at', 'sent_by', 'decided_at',
        'decision', 'ai_json', 'ai_reason', 'edited_before_send',
    ];

    /**
     * vat_rate is deliberately NOT cast. MySQL already returns decimal(5,2) as
     * a fixed-scale string ("19.00"); Laravel's decimal cast would send it
     * through a float and back to get there.
     */
    protected $casts = [
        'subtotal_cents' => 'integer',
        'discount_cents' => 'integer',
        'shipping_cents' => 'integer',
        'vat_cents' => 'integer',
        'total_cents' => 'integer',
        'valid_until' => 'date',
        'sent_at' => 'datetime',
        'decided_at' => 'datetime',
        'ai_json' => 'array',
        'edited_before_send' => 'boolean',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $model) {
            if (empty($model->uuid)) {
                $model->uuid = (string) Str::uuid();
            }
        });
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    /**
     * The lines, in the order the person arranged them.
     *
     * @return HasMany<OfferItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(OfferItem::class)->orderBy('position');
    }

    /** @return BelongsTo<Contact, $this> */
    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Narrow the list the way the screen does: a status tab, a search term, a
     * channel, a date range.
     *
     * Does NOT scope the workspace. Tenancy is applied by the caller, on every
     * query, before this is reached.
     *
     * @param  Builder<self>  $query
     * @param  array<string, mixed>  $filters
     * @return Builder<self>
     */
    public function scopeNarrowed(Builder $query, array $filters): Builder
    {
        if (! empty($filters['status']) && in_array($filters['status'], self::STATUSES, true)) {
            $query->where('status', $filters['status']);
        }

        if (! empty($filters['channel'])) {
            $query->where('channel', $filters['channel']);
        }

        if (! empty($filters['search'])) {
            // LIKE and not FULLTEXT: a person typing "014" expects OF-0142, and
            // a word index would find nothing until the whole number is typed.
            $term = Money::likeTerm((string) $filters['search']);
            $query->where(function (Builder $q) use ($term) {
                $q->where('number', 'like', $term)
                    // The other way an offer is looked for: by who it is for.
                    ->orWhereHas('contact', function (Builder $c) use ($term) {
                        $c->where('first_name', 'like', $term)
                            ->orWhere('last_name', 'like', $term)
                            ->orWhere('company', 'like', $term);
                    });
            });
        }

        // The range reads created_at, the column the list is ordered by and the
        // one (workspace_id, created_at) covers. Compared against the day's
        // boundaries rather than with whereDate(): wrapping the column in date()
        // makes that index unusable, and endOfDay is what keeps an inclusive
        // "to" from dropping everything saved after midnight on the last day.
        if (! empty($filters['from'])) {
            $query->where('created_at', '>=', Carbon::parse((string) $filters['from'])->startOfDay());
        }

        if (! empty($filters['to'])) {
            $query->where('created_at', '<=', Carbon::parse((string) $filters['to'])->endOfDay());
        }

        return $query;
    }
}
