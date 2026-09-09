<?php

namespace App\Modules\Documents\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $document_id
 * @property string $text
 */
class DocumentContent extends Model
{
    protected $fillable = ['document_id', 'text', 'extracted_at'];

    protected $casts = ['extracted_at' => 'datetime'];

    /** @return BelongsTo<Document, $this> */
    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }
}
