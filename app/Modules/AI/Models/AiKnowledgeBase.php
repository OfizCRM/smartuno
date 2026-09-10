<?php

namespace App\Modules\AI\Models;

use Database\Factories\AiKnowledgeBaseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * One chatbot's corpus, owned by exactly one workspace.
 *
 * Declared for the same reason Conversation's are: without them every
 * `$kb->workspace_id` is an "undefined property" to static analysis, and a
 * suppressed tenancy expression is the last one that should be invisible.
 *
 * @property int $id
 * @property string $uuid
 * @property int $workspace_id
 * @property string $name
 * @property string $embedding_model
 * @property int $dimensions
 * @property string $status
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class AiKnowledgeBase extends Model
{
    use HasFactory;

    protected static function newFactory()
    {
        return AiKnowledgeBaseFactory::new();
    }

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

    protected $table = 'ai_knowledge_bases';

    protected $fillable = ['workspace_id', 'name', 'embedding_model', 'dimensions', 'status'];

    public function documents(): HasMany
    {
        return $this->hasMany(AiKbDocument::class, 'kb_id');
    }

    public function chatbots(): HasMany
    {
        return $this->hasMany(AiChatbot::class, 'ai_kb_id');
    }
}
