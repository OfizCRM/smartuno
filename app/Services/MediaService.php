<?php

namespace App\Services;

use App\Models\Media;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class MediaService
{
    public function __construct(private StorageManager $storageManager) {}

    public function store(
        UploadedFile $file,
        Model $owner,
        string $collection = 'default',
        ?string $disk = null
    ): Media {
        $ext = $file->getClientOriginalExtension();
        $filename = $file->getClientOriginalName();

        // Resolve disk from StorageManager unless caller explicitly passes one
        $resolvedDisk = $disk ?? $this->storageManager->diskName();
        $rawPath = 'media/'.Str::uuid().'.'.$ext;
        $path = $this->storageManager->prefixedPath($rawPath);

        Storage::disk($resolvedDisk)->putFileAs(dirname($path), $file, basename($path));

        return Media::create([
            'mediable_type' => get_class($owner),
            'mediable_id' => $owner->getKey(),
            'disk' => $resolvedDisk,
            'path' => $path,
            'filename' => $filename,
            'mime_type' => $file->getMimeType(),
            'size_bytes' => $file->getSize(),
            'collection' => $collection,
        ]);
    }

    /**
     * Get total storage used by owner in bytes.
     */
    public function usedBytes(Model $owner, ?string $collection = null): int
    {
        $query = Media::where('mediable_type', get_class($owner))
            ->where('mediable_id', $owner->getKey());

        if ($collection) {
            $query->where('collection', $collection);
        }

        return (int) $query->sum('size_bytes');
    }

    /**
     * Get storage quota in bytes from the plan's limits.
     *
     * The key is `storage`, in megabytes — that is what the admin plan form
     * writes, labelled "Storage (MB)", and what every seeded plan carries. This
     * used to read `storage_gb`, a key that has never existed, so the lookup
     * always missed and every workspace silently got the 1 GB fallback no matter
     * which plan it was on.
     */
    public function quotaBytes(User $user): int
    {
        $plan = $user->effectiveSubscription()?->plan;
        $mb = $plan?->limitValue('storage') ?? 1024;

        return (int) $mb * 1024 * 1024;
    }
}
