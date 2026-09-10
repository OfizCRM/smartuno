<?php

namespace App\Services;

use App\Models\Media;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

class MediaService
{
    public function __construct(private StorageManager $storageManager) {}

    public function store(
        UploadedFile $file,
        Model $owner,
        string $collection = 'default',
        ?string $disk = null
    ): Media {
        $filename = $file->getClientOriginalName();

        // The extension used to be getClientOriginalExtension() — the one the
        // BROWSER sent, written verbatim into the name of a file on the disk
        // nginx publishes from this application's own origin. Uploading
        // anything at all as "notes.html" put a page at a URL on our domain
        // that runs in our origin with the visitor's session attached. The uuid
        // made it unguessable, not harmless: the uploader knows the URL.
        //
        // storeImageUpload() takes the extension from the file's DETECTED type
        // instead, refuses an SVG it cannot sanitise, and applies the directory
        // prefix once. Its name says images because that is what it was written
        // for; what it actually does — safe extension, uuid, prefix, SVG — is
        // what every public upload needs, so it is shared rather than copied.
        $stored = $this->storageManager->storeImageUpload($file, 'media');

        if ($stored === null) {
            throw new RuntimeException('This image could not be processed safely.');
        }

        // An explicit disk from the caller still wins, but the path is the one
        // storeImageUpload() actually wrote to.
        $resolvedDisk = $disk ?? $stored['disk'];
        $path = $stored['path'];

        if ($resolvedDisk !== $stored['disk']) {
            Storage::disk($resolvedDisk)->put($path, (string) $this->storageManager->disk()->get($path));
            $this->storageManager->disk()->delete($path);
        }

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
