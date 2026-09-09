<?php

namespace App\Modules\Documents\Models;

use App\Models\User;
use App\Modules\Shared\Models\Contact;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * One file in the library.
 *
 * @property int $id
 * @property string $uuid
 * @property int $workspace_id
 * @property int|null $folder_id
 * @property int|null $contact_id
 * @property string $name
 * @property string $path
 * @property string $mime
 * @property string $extension
 * @property int $size_bytes
 * @property string $source
 * @property Carbon|null $expires_at
 * @property array<int, int>|null $remind_days
 * @property array<int, int>|null $reminders_sent
 * @property int|null $kb_document_id
 * @property int|null $created_by
 */
class Document extends Model
{
    use SoftDeletes;

    /**
     * The kinds the type filter offers, and the extensions each covers.
     *
     * Read from the extension THIS application stored the file under, never from
     * the name it arrived with — the same rule the icon and the preview follow.
     */
    public const KINDS = [
        'pdf' => ['pdf'],
        'word' => ['doc', 'docx'],
        'excel' => ['xls', 'xlsx', 'csv'],
        'image' => ['png', 'jpg', 'gif', 'webp'],
    ];

    protected $fillable = [
        'workspace_id', 'folder_id', 'contact_id', 'name', 'path', 'mime',
        'extension', 'size_bytes', 'source', 'source_message_id', 'created_by',
        'expires_at', 'remind_days', 'reminders_sent', 'kb_document_id',
    ];

    protected $casts = [
        'size_bytes' => 'integer',
        'expires_at' => 'date',
        'remind_days' => 'array',
        'reminders_sent' => 'array',
    ];

    protected static function boot(): void
    {
        parent::boot();
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

    /** @return BelongsTo<DocumentFolder, $this> */
    public function folder(): BelongsTo
    {
        return $this->belongsTo(DocumentFolder::class, 'folder_id');
    }

    /** @return BelongsTo<Contact, $this> */
    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    /** @return HasOne<DocumentContent, $this> */
    public function content(): HasOne
    {
        return $this->hasOne(DocumentContent::class);
    }

    /** @return HasMany<DocumentVersion, $this> */
    public function versions(): HasMany
    {
        return $this->hasMany(DocumentVersion::class)->orderByDesc('version');
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Narrow the list the way the screen does: a folder, a kind, a search term.
     *
     * @param  Builder<self>  $query
     * @param  array<string, mixed>  $filters
     * @return Builder<self>
     */
    public function scopeNarrowed(Builder $query, array $filters): Builder
    {
        if (! empty($filters['folder_id'])) {
            $query->where('folder_id', $filters['folder_id']);
        }

        if (! empty($filters['kind']) && isset(self::KINDS[$filters['kind']])) {
            $query->whereIn('extension', self::KINDS[$filters['kind']]);
        }

        if (! empty($filters['search'])) {
            // LIKE and not FULLTEXT: a person typing "contr" expects the
            // contract, and a word index would find nothing until the whole word
            // is typed.
            $term = '%'.str_replace(['%', '_'], ['\%', '\_'], (string) $filters['search']).'%';
            $query->where(function (Builder $q) use ($term) {
                $q->where('name', 'like', $term)
                    ->orWhereHas('contact', function (Builder $c) use ($term) {
                        $c->where('first_name', 'like', $term)
                            ->orWhere('last_name', 'like', $term)
                            ->orWhere('company', 'like', $term);
                    })
                    // And inside the file itself, which is how a contract gets
                    // found by a clause rather than by whatever it was named.
                    ->orWhereHas('content', function (Builder $c) use ($term) {
                        $c->where('text', 'like', $term);
                    });
            });
        }

        return $query;
    }
}
