<?php

namespace App\Modules\Documents\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $document_id
 * @property int $version
 * @property string $name
 * @property string $path
 * @property string $disk
 * @property int $size_bytes
 */
class DocumentVersion extends Model
{
    /**
     * Never serialised.
     *
     * `path` is the object key and `disk` is the bucket it lives in — together
     * they are the address of a private file, and the two halves of a URL
     * somebody eventually builds. Nothing on a screen needs either: every link
     * to a document goes through a named route that checks the workspace first.
     *
     * $hidden suppresses serialisation only. $model->path and $model->disk keep
     * working everywhere in PHP; what stops is their arrival in an Inertia prop
     * or a JSON response. Enforced here rather than remembered at each payload,
     * which is what tests/Feature/ProductionHardening/PrivateStorageBoundaryTest
     * asserts and how it caught this.
     *
     * @var list<string>
     */
    protected $hidden = ['path', 'disk'];

    protected $fillable = ['document_id', 'version', 'name', 'path', 'disk', 'mime', 'size_bytes', 'created_by'];

    /** @return BelongsTo<Document, $this> */
    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
