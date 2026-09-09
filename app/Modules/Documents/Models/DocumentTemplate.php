<?php

namespace App\Modules\Documents\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $workspace_id
 * @property string $name
 * @property string $path
 * @property string $mime
 * @property string $extension
 * @property int $size_bytes
 */
class DocumentTemplate extends Model
{
    protected $fillable = ['workspace_id', 'name', 'path', 'mime', 'extension', 'size_bytes', 'created_by'];
}
