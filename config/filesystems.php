<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default Filesystem Disk
    |--------------------------------------------------------------------------
    |
    | Here you may specify the default filesystem disk that should be used
    | by the framework. The "local" disk, as well as a variety of cloud
    | based disks are available to your application for file storage.
    |
    */

    'default' => env('FILESYSTEM_DISK', 'local'),

    /*
    |--------------------------------------------------------------------------
    | Filesystem Disks
    |--------------------------------------------------------------------------
    |
    | Below you may configure as many filesystem disks as necessary, and you
    | may even configure multiple disks for the same driver. Examples for
    | most supported storage drivers are configured here for reference.
    |
    | Supported drivers: "local", "ftp", "sftp", "s3"
    |
    */

    'disks' => [

        'local' => [
            'driver' => 'local',
            'root' => storage_path('app/private'),
            'serve' => true,
            'throw' => false,
            'report' => false,
        ],

        'public' => [
            'driver' => 'local',
            'root' => storage_path('app/public'),
            // rtrim guards against a trailing slash in APP_URL (e.g. "https://site/"),
            // which would otherwise yield "https://site//storage/..." (double slash)
            // and 404 every uploaded logo/favicon/media asset.
            'url' => rtrim(env('APP_URL'), '/').'/storage',
            'visibility' => 'public',
            'throw' => false,
            'report' => false,
        ],

        // Cloud storage disks — credentials are stored encrypted in the database
        // and injected at runtime by App\Services\StorageManager.
        // Do NOT add credential env vars here; use Admin › Integrations › Storage.
        's3' => [
            'driver' => 's3',
            'key' => null,
            'secret' => null,
            'region' => 'us-east-1',
            'bucket' => null,
            'url' => null,
            'endpoint' => null,
            'use_path_style_endpoint' => false,
            'throw' => false,
        ],

        'do_spaces' => [
            'driver' => 's3',
            'key' => null,
            'secret' => null,
            'region' => 'nyc3',
            'bucket' => null,
            'url' => null,
            'endpoint' => 'https://nyc3.digitaloceanspaces.com',
            'use_path_style_endpoint' => false,
            'throw' => false,
            'visibility' => 'public',
            'options' => ['ACL' => 'public-read'],
        ],

        'wasabi' => [
            'driver' => 's3',
            'key' => null,
            'secret' => null,
            'region' => 'us-east-1',
            'bucket' => null,
            'url' => null,
            'endpoint' => 'https://s3.wasabisys.com',
            'use_path_style_endpoint' => false,
            'throw' => false,
        ],

        // Cloudflare R2. Modelled on the 's3' entry above and deliberately NOT
        // on 'do_spaces'. R2 does not implement S3 ACLs — it ignores x-amz-acl
        // rather than rejecting it — so a bucket is public or private as a
        // whole, decided in the Cloudflare dashboard, and no per-object header
        // changes that. do_spaces carries 'visibility' => 'public' and
        // 'options' => ['ACL' => 'public-read'], which is real on DigitalOcean
        // and inert here: copied onto R2 it would read as if it had made the
        // objects world-readable while the bucket setting alone decides.
        // StorageManager sets 'visibility' => 'private' on the runtime config;
        // see the note beside the storage_r2 arm for why it is stated rather
        // than omitted.
        //
        // 'region' is the literal 'auto': R2 has no regions and rejects
        // anything else in the SigV4 credential scope.
        // 'endpoint' is null here and built at runtime by StorageManager from
        // the account ID and jurisdiction — see r2Endpoint() there for why the
        // "r2.cloudflarestorage.com" host suffix is load-bearing.
        'r2' => [
            'driver' => 's3',
            'key' => null,
            'secret' => null,
            'region' => 'auto',
            'bucket' => null,
            'url' => null,
            'endpoint' => null,
            'use_path_style_endpoint' => true,
            'throw' => false,
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Symbolic Links
    |--------------------------------------------------------------------------
    |
    | Here you may configure the symbolic links that will be created when the
    | `storage:link` Artisan command is executed. The array keys should be
    | the locations of the links and the values should be their targets.
    |
    */

    'links' => [
        public_path('storage') => storage_path('app/public'),
    ],

];
