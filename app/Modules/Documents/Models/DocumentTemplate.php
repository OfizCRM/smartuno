<?php

namespace App\Modules\Documents\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $workspace_id
 * @property string $name
 * @property string $path
 * @property string $disk
 * @property string $mime
 * @property string $extension
 * @property int $size_bytes
 */
class DocumentTemplate extends Model
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

    protected $fillable = ['workspace_id', 'name', 'path', 'disk', 'mime', 'extension', 'size_bytes', 'created_by'];
}
