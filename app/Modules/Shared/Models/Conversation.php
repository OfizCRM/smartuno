<?php

namespace App\Modules\Shared\Models;

use App\Models\InternalNote;
use App\Models\User;
use App\Modules\Inbox\Models\ConversationActivity;
use App\Modules\Inbox\Models\InboxLabel;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Str;

class Conversation extends Model
{
    protected static function boot(): void
    {
        parent::boot();
        static::creating(function (self $model) {
            if (empty($model->uuid)) {
                $model->uuid = (string) Str::uuid();
            }
        });
        static::created(function (self $model) {
            ConversationActivity::log($model, 'created', [
                'status' => $model->status,
            ]);
        });
        static::updated(function (self $model) {
            $model->recordActivityChanges();
        });
    }

    /**
     * Model-level activity trail: hooking `updated` (rather than each
     * controller) also captures changes made by automation, campaigns,
     * chatbot handover and the mobile API. Actor attribution comes from
     * the authenticated user inside ConversationActivity::log().
     */
    protected function recordActivityChanges(): void
    {
        if ($this->wasChanged('assigned_user_id')) {
            $fromId = $this->getOriginal('assigned_user_id');
            $toId = $this->assigned_user_id;
            $names = User::whereIn('id', array_filter([$fromId, $toId]))->pluck('name', 'id');

            $type = match (true) {
                ! $fromId && $toId => 'assigned',
                $fromId && ! $toId => 'unassigned',
                default => 'transferred',
            };

            ConversationActivity::log($this, $type, [
                'from_id' => $fromId,
                'from_name' => $fromId ? $names->get($fromId) : null,
                'to_id' => $toId,
                'to_name' => $toId ? $names->get($toId) : null,
            ]);
        }

        if ($this->wasChanged('status')) {
            ConversationActivity::log($this, 'status_changed', [
                'from' => $this->getOriginal('status'),
                'to' => $this->status,
            ]);
        }

        if ($this->wasChanged('assigned_to')) {
            ConversationActivity::log($this, 'handover', [
                'from' => $this->getOriginal('assigned_to'),
                'to' => $this->assigned_to,
            ]);
        }
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    protected $fillable = [
        'workspace_id', 'channel_account_id', 'contact_id', 'external_thread_id',
        'status', 'assigned_user_id', 'assigned_to', 'handover_at',
        'last_message_at', 'unread_count',
        'first_response_at', 'resolved_at', 'last_inbound_at',
    ];

    protected function casts(): array
    {
        return [
            'last_message_at' => 'datetime',
            'first_response_at' => 'datetime',
            'resolved_at' => 'datetime',
            'last_inbound_at' => 'datetime',
            'handover_at' => 'datetime',
            'unread_count' => 'integer',
        ];
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    public function channelAccount(): BelongsTo
    {
        return $this->belongsTo(ChannelAccount::class);
    }

    public function messages(): HasMany
    {
        return $this->hasMany(Message::class);
    }

    /** @return HasOne<Message, $this> */
    public function lastMessage(): HasOne
    {
        return $this->hasOne(Message::class)->latestOfMany('sent_at');
    }

    public function internalNotes(): HasMany
    {
        return $this->hasMany(InternalNote::class);
    }

    public function activities(): HasMany
    {
        return $this->hasMany(ConversationActivity::class);
    }

    public function labels(): BelongsToMany
    {
        return $this->belongsToMany(
            InboxLabel::class,
            'inbox_label_conversation',
            'conversation_id',
            'label_id'
        );
    }

    public function assignedUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_user_id');
    }

    /**
     * Statuses a workspace still has work to do on.
     *
     * 'pending' belongs here. The inbox list used to force status='open' for
     * every view except resolved/snoozed, so a conversation a person marked "in
     * asteptare" — a value the status dropdown offers and the API accepts —
     * disappeared from every screen with no way back to it.
     */
    public const ACTIVE_STATUSES = ['open', 'pending'];

    /**
     * The status / assignment predicate behind one sidebar view.
     *
     * Kept on the model because three controllers render this same list — the
     * inbox index, the sidebar on the conversation page, and the mobile API —
     * and until now each carried its own copy. A filter added to one and missed
     * in another shows as the list quietly changing when you open a conversation.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeInboxFolder(Builder $query, ?string $folder, ?int $userId): Builder
    {
        // Qualified: the count queries join channel_accounts and the label pivot,
        // and both carry a status / workspace_id of their own. Unqualified these
        // read fine until the first join and then fail as ambiguous.
        $status = $query->qualifyColumn('status');
        $assignee = $query->qualifyColumn('assigned_user_id');
        $unread = $query->qualifyColumn('unread_count');

        return match ($folder) {
            'mine' => $query->whereIn($status, self::ACTIVE_STATUSES)->where($assignee, $userId),
            'unassigned' => $query->whereIn($status, self::ACTIVE_STATUSES)->whereNull($assignee),
            'unread' => $query->whereIn($status, self::ACTIVE_STATUSES)->where($unread, '>', 0),
            'pending' => $query->where($status, 'pending'),
            'resolved' => $query->where($status, 'resolved'),
            'snoozed' => $query->where($status, 'snoozed'),
            default => $query->whereIn($status, self::ACTIVE_STATUSES),
        };
    }

    /**
     * Channel / account / label / search narrowing, applied on top of a folder.
     *
     * @param  Builder<self>  $query
     * @param  array<string, mixed>  $filters
     * @return Builder<self>
     */
    public function scopeInboxNarrowed(Builder $query, array $filters): Builder
    {
        return $query
            // An account-less conversation is invisible to a whereHas on the
            // account — which is every email and SMS thread a campaign has ever
            // mirrored. Those carry the channel on their messages instead, so the
            // filter has to look in both places or the Email row returns nothing,
            // for ever, with no way to tell that from "no email yet".
            ->when($filters['channel'] ?? null, fn (Builder $q, $channel) => $q->where(fn (Builder $q) => $q
                ->whereHas('channelAccount', fn ($c) => $c->where('channel', $channel))
                ->orWhere(fn (Builder $q) => $q
                    ->whereNull($q->qualifyColumn('channel_account_id'))
                    ->whereHas('messages', fn ($m) => $m->where('channel', $channel)))))
            ->when($filters['account_id'] ?? null, fn (Builder $q, $accountId) => $q->where($q->qualifyColumn('channel_account_id'), $accountId))
            ->when($filters['label'] ?? null, fn (Builder $q, $labelId) => $q->whereHas(
                'labels', fn ($l) => $l->where('inbox_labels.id', $labelId)
            ))
            ->tap(function (Builder $q) use ($filters) {
                $search = is_string($filters['search'] ?? null) ? trim($filters['search']) : '';
                if ($search !== '') {
                    $this->scopeInboxSearch($q, $search);
                }
            });
    }

    /**
     * Find a conversation by who it is with, or by something that was said in it.
     *
     * Deliberately LIKE on both halves rather than a FULLTEXT index on
     * messages.body. FULLTEXT is faster, and it was the first thing tried, but it
     * matches whole words: a person typing "livr" would be told there is nothing,
     * then find it on typing "livrare". For a box people use by typing a fragment
     * they half-remember, that reads as broken.
     *
     * The message half is a correlated EXISTS, so it walks each conversation's own
     * rows through the conversation_id index rather than scanning the table. That
     * holds comfortably at this product's scale; a workspace with tens of
     * thousands of conversations would want the index back, and a prefix-mode
     * MATCH ... AGAINST with a LIKE fallback for short terms.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeInboxSearch(Builder $query, string $term): Builder
    {
        $like = '%'.addcslashes($term, '%_\\').'%';

        return $query->where(fn (Builder $q) => $q
            ->whereHas('contact', fn ($c) => $c
                ->where('first_name', 'like', $like)
                ->orWhere('last_name', 'like', $like)
                ->orWhere('phone_e164', 'like', $like)
                ->orWhere('email', 'like', $like)
                ->orWhereRaw("CONCAT_WS(' ', first_name, last_name) LIKE ?", [$like]))
            ->orWhereHas('messages', fn ($m) => $m->where('body', 'like', $like)));
    }

    /**
     * Which channel this conversation is actually on.
     *
     * A conversation has no channel column: it borrows one from its channel
     * account. But campaign mirroring creates conversations with a NULL account
     * whenever the workspace has no ChannelAccount for that channel — and no
     * email or SMS account can be created at all today, so every email campaign
     * produces account-less threads.
     *
     * Every caller used to close that gap with `?? 'whatsapp'`. On an email
     * thread that resolved to the WhatsApp driver, which sends to the contact's
     * PHONE — so a reply typed into what looked like an email conversation went
     * out as a WhatsApp message, and the agent had no way to tell.
     *
     * The messages themselves carry the truth: SendCampaignMessageJob writes
     * channel='email' on the rows it mirrors. Read it from there, and guess only
     * when there is nothing at all to read.
     */
    public function resolvedChannel(): ?string
    {
        $fromAccount = $this->channelAccount?->channel;
        if ($fromAccount) {
            return $fromAccount;
        }

        return $this->relationLoaded('lastMessage')
            ? $this->lastMessage?->channel
            : $this->messages()->latest('sent_at')->value('channel');
    }

    /**
     * Whether the WhatsApp customer-service window allows free-form (session) messages.
     *
     * Meta only allows non-template outbound content while a user-initiated
     * conversation is within the rolling ~24h window from the contact's last
     * **inbound** message. Sending a template (including campaigns) does not
     * open this window until the contact sends a message (including tapping a
     * template button).
     *
     * Inbounds are scoped by workspace + contact across all WhatsApp threads so
     * a campaign-mirrored conversation still reflects replies if webhooks
     * attached to a different row (e.g. mismatched channel_account_id).
     */
    public function isWhatsappWindowOpen(): bool
    {
        if ($this->channelAccount?->channel !== 'whatsapp') {
            return true;
        }

        $latestInbound = Message::query()
            ->where('direction', 'in')
            ->where('channel', 'whatsapp')
            ->whereHas('conversation', function ($q) {
                $q->where('workspace_id', $this->workspace_id)
                    ->where('contact_id', $this->contact_id);
            })
            ->latest('sent_at')
            ->value('sent_at');

        return (bool) $latestInbound && now()->diffInHours($latestInbound) < 24;
    }
}
