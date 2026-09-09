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
 * @property int $size_bytes
 */
class DocumentVersion extends Model
{
    protected $fillable = ['document_id', 'version', 'name', 'path', 'mime', 'size_bytes', 'created_by'];

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
