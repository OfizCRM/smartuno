<?php

namespace App\Services;

use App\Modules\Integrations\Models\IntegrationConfig;
use App\Support\SvgSanitizer;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use LogicException;
use Symfony\Component\Mime\MimeTypes;

/**
 * Single source of truth for file storage configuration.
 *
 * ALL credentials live encrypted in the `integration_configs` DB table.
 * No .env keys are read here — zero fallback to the environment.
 *
 * Admins configure a storage provider via Admin › Integrations › Storage.
 * The resolved disk config and directory prefix are cached (60 s) so there
 * is at most one DB read per minute per process instead of one per upload.
 */
class StorageManager
{
    private const CACHE_KEY = 'storage_manager_resolved';

    private const CACHE_TTL = 60; // seconds

    /**
     * Returns the active filesystem disk instance.
     * Falls back to the 'public' local disk when no cloud provider is enabled.
     */
    public function disk(): Filesystem
    {
        return Storage::disk($this->diskName());
    }

    /**
     * Returns the Laravel disk name for the active provider.
     * For cloud providers this also injects the DB credentials into the
     * runtime config before returning, ensuring the disk is ready to use.
     *
     * This is the PUBLIC path only — logos, favicons, avatars, message media.
     * Every disk it can return is either served through the storage symlink or
     * built to be fetched by URL. Files that must never have a URL resolve
     * through PrivateStorageManager instead, and assertNotPrivateDisk() below
     * is what stops the two ever meeting.
     */
    public function diskName(): string
    {
        $resolved = $this->resolved();

        if ($resolved['provider'] === null || $resolved['provider'] === 'storage_local') {
            return self::assertNotPrivateDisk('public');
        }

        $diskName = self::assertNotPrivateDisk(
            IntegrationConfig::STORAGE_DISK_MAP[$resolved['provider']] ?? 'public'
        );

        // After the assertion, never before: a colliding disk name must not have
        // public credentials written over it on the way to the exception.
        $this->injectDiskConfig($diskName, $resolved['disk_config']);

        return $diskName;
    }

    /**
     * The public resolver must never hand back a disk that holds private files.
     *
     * Structurally this is already true, and deliberately so: the two maps —
     * IntegrationConfig::STORAGE_DISK_MAP and
     * PrivateStorageManager::PRIVATE_DISK_MAP — have disjoint value sets, so no
     * disk name exists in both. 'local' is not a public disk name and 'r2' is
     * not a private one. Nothing an admin can type reaches this exception; only
     * a later edit that renamed a disk into the other map can, which is exactly
     * the edit that would otherwise go unnoticed. A private R2 bucket serving
     * logos through a CDN, or a contract written into a world-readable bucket,
     * are both one careless map entry away and neither announces itself.
     *
     * So the check is not defence against input. It is the disjointness
     * invariant made executable at the one choke point every public upload
     * passes through, rather than left as a convention in a comment. It fails
     * loudly because the alternative — falling back to 'public' — is the silent
     * fallback this class has already been bitten by twice.
     *
     * PrivateStorageManager::assertNotPublicDisk() is the mirror of this.
     */
    private static function assertNotPrivateDisk(string $diskName): string
    {
        if (PrivateStorageManager::isPrivateDisk($diskName)) {
            throw new LogicException(sprintf(
                'The [%s] disk appears in both IntegrationConfig::STORAGE_DISK_MAP and '.
                'PrivateStorageManager::PRIVATE_DISK_MAP. The public upload path must not '.
                'resolve to a disk that holds private files; give one of the two its own '.
                'disk name.',
                $diskName
            ));
        }

        return $diskName;
    }

    /**
     * Returns the directory prefix (with trailing slash) to prepend to paths,
     * or an empty string for local storage / providers with no prefix set.
     */
    public function directoryPrefix(): string
    {
        return $this->resolved()['directory_prefix'];
    }

    /**
     * Prepend the active directory prefix to an arbitrary storage path.
     */
    public function prefixedPath(string $path): string
    {
        $prefix = $this->directoryPrefix();

        return $prefix !== '' ? $prefix.ltrim($path, '/') : $path;
    }

    /**
     * Returns the active provider slug or null if nothing is enabled.
     */
    public function activeProvider(): ?string
    {
        return $this->resolved()['provider'];
    }

    /**
     * Clears the in-process and persistent cache.
     * Must be called whenever the admin saves or toggles a storage integration.
     */
    public function clearCache(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    /**
     * Ensures credentials are injected into runtime config for the given disk name.
     * Use this when rendering URLs for media records that may live on any disk.
     *
     * Callers pass a disk name read off a row — media.disk, clients.logo_disk,
     * system_settings.app_logo_disk — so this one takes a name from data rather
     * than resolving it. It needs no private-disk guard of its own: the lookup
     * below is against STORAGE_DISK_MAP, whose values are disjoint from
     * PrivateStorageManager::PRIVATE_DISK_MAP, so a private disk name simply
     * finds no provider and returns without touching the runtime config. Should
     * a documents row ever reach here carrying disk='local', the result is that
     * nothing happens — which is correct, not a near miss.
     */
    public function ensureDiskReady(string $diskName): void
    {
        if ($diskName === 'public') {
            return;
        }

        $providerSlug = array_search($diskName, IntegrationConfig::STORAGE_DISK_MAP, true);
        if (! $providerSlug) {
            return;
        }

        $config = IntegrationConfig::forProvider($providerSlug);
        if (! $config) {
            return;
        }

        $diskConfig = $this->buildDiskConfig($providerSlug, $config->credentials ?? []);
        $this->injectDiskConfig($diskName, $diskConfig);
    }

    /**
     * Stores an uploaded image and returns its path and disk, or null when the
     * file is an SVG that cannot be made safe — or when the write itself failed.
     *
     * SVG is XML and can carry script, so what lands on the disk for one is the
     * output of SvgSanitizer and never the bytes the uploader sent. Every other
     * type is streamed through untouched.
     *
     * The extension comes from the file's own detected mime type, never from
     * the client-supplied name: a valid PNG uploaded as "shell.php" passes an
     * image rule, and stored under that name on the public disk it is served
     * straight back through the storage symlink for the webserver to execute.
     *
     * @return array{path: string, disk: string}|null
     */
    public function storeImageUpload(UploadedFile $file, string $directory): ?array
    {
        $extension = $file->extension() ?: 'png';
        $path = $this->prefixedPath(trim($directory, '/').'/'.Str::uuid().'.'.$extension);
        $disk = $this->diskName();

        if ($extension === 'svg') {
            $cleaned = SvgSanitizer::sanitize((string) $file->get());

            if ($cleaned === null) {
                return null;
            }

            $written = $this->disk()->put($path, $cleaned);
        } else {
            $written = $this->disk()->putFileAs(dirname($path), $file, basename($path));
        }

        // Every disk this builds sets 'throw' => false, so a failed write is a
        // false return and not an exception — a full volume, a bad bucket
        // policy, an expired key. Ignoring it returns a path that names nothing,
        // and the caller stores that path next to a URL it then shows people.
        return $written === false ? null : ['path' => $path, 'disk' => $disk];
    }

    /**
     * The same, for bytes we already hold rather than an upload.
     *
     * Media downloaded from the WhatsApp Graph API arrives as a string and a
     * mime type, so it cannot go through storeImageUpload() — but it needs
     * every rule that method applies, and for the same reasons. The extension
     * is therefore derived from the mime type through Symfony's own map, which
     * is the identical source UploadedFile::extension() reads.
     *
     * What it must never be is what the old caller did:
     *
     *     $ext = explode('/', $mimeType)[1] ?? 'bin';
     *
     * That takes the subtype verbatim from a remote API response and makes it
     * the extension of a file on the disk nginx serves from the app's own
     * origin. A response saying `text/html` produced an .html file at a URL on
     * our domain; `image/svg+xml` produced `svg+xml`, which is not an extension
     * at all. Neither is hypothetical — both are just what that line returns.
     *
     * Unknown mime types get `bin`, which is served as a download rather than
     * executed. SVG goes through the sanitiser exactly as it does above.
     *
     * @return array{path: string, disk: string}|null null when an SVG could not be made safe
     */
    public function storeBytes(string $contents, string $mime, string $directory): ?array
    {
        $extension = self::extensionForMime($mime);
        $path = $this->prefixedPath(trim($directory, '/').'/'.Str::uuid().'.'.$extension);
        $disk = $this->diskName();

        if ($extension === 'svg') {
            $cleaned = SvgSanitizer::sanitize($contents);

            if ($cleaned === null) {
                return null;
            }

            $contents = $cleaned;
        }

        // Same reason as above: 'throw' => false makes a failed write a return
        // value, not an exception.
        if ($this->disk()->put($path, $contents) === false) {
            return null;
        }

        return ['path' => $path, 'disk' => $disk];
    }

    /**
     * A file extension for a mime type, or `bin` when there is no safe answer.
     *
     * Symfony's MimeTypes is the same table UploadedFile::extension() consults,
     * so an upload and a download of the same content land under the same
     * extension. Its first suggestion is the canonical one.
     *
     * The deny list is not about correctness, it is about what the webserver
     * does with the file afterwards. These types are served from our own origin
     * through the storage symlink, so a document that a browser will execute in
     * that origin — HTML, XHTML, a standalone JS file — is stored XSS with a
     * session cookie attached, whatever its content really is. There is no
     * legitimate chat attachment among them.
     */
    public static function extensionForMime(string $mime): string
    {
        $deny = ['html', 'htm', 'xhtml', 'xht', 'shtml', 'js', 'mjs', 'php', 'phtml', 'xml'];

        $mime = strtolower(trim(explode(';', $mime)[0]));
        $extension = strtolower(MimeTypes::getDefault()->getExtensions($mime)[0] ?? '');

        if ($extension === '' || in_array($extension, $deny, true)) {
            return 'bin';
        }

        return $extension;
    }

    /**
     * Removes one stored file, whichever disk it landed on.
     *
     * Takes the path and disk rather than the owning model, so a replacement
     * can be written first and the file it superseded deleted afterwards — the
     * order that keeps a failed write from leaving a logo_path naming nothing.
     */
    public function deleteStoredFile(?string $path, ?string $disk = null): void
    {
        if ($path === null || $path === '') {
            return;
        }

        $disk ??= 'public';
        $this->ensureDiskReady($disk);
        Storage::disk($disk)->delete($path);
    }

    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Resolves and caches the full storage configuration from the DB.
     *
     * Returns:
     *   [
     *     'provider'         => 'storage_do' | 'storage_local' | null,
     *     'disk_config'      => [...],  // ready-to-inject Laravel disk array
     *     'directory_prefix' => 'uploads/' | '',
     *   ]
     */
    private function resolved(): array
    {
        return Cache::remember(self::CACHE_KEY, self::CACHE_TTL, function () {
            // Prefer the admin-designated default provider; fall back to first enabled
            $defaultConfig = IntegrationConfig::whereIn('provider', IntegrationConfig::STORAGE_PROVIDERS)
                ->where('mode', 'live')
                ->where('enabled', true)
                ->where('is_default', true)
                ->first();

            $orderedProviders = $defaultConfig
                ? array_merge([$defaultConfig->provider], array_diff(IntegrationConfig::STORAGE_PROVIDERS, [$defaultConfig->provider]))
                : IntegrationConfig::STORAGE_PROVIDERS;

            foreach ($orderedProviders as $provider) {
                $config = IntegrationConfig::forProvider($provider);

                if (! $config || ! $config->enabled) {
                    continue;
                }

                if ($provider === 'storage_local') {
                    return [
                        'provider' => 'storage_local',
                        'disk_config' => [],
                        'directory_prefix' => '',
                    ];
                }

                $creds = $config->credentials ?? [];

                $diskConfig = $this->buildDiskConfig($provider, $creds);

                $prefix = trim($creds['directory_prefix'] ?? '', '/');

                return [
                    'provider' => $provider,
                    'disk_config' => $diskConfig,
                    'directory_prefix' => $prefix !== '' ? $prefix.'/' : '',
                ];
            }

            // Nothing enabled → use local public disk
            return [
                'provider' => null,
                'disk_config' => [],
                'directory_prefix' => '',
            ];
        });
    }

    /**
     * Builds a complete Laravel disk config array from DB credentials.
     * No .env reads — only what the admin entered in the integrations panel.
     *
     * Public because ConnectionTester must build the disk it tests from exactly
     * this method. When the tester assembled its own array, a green tick proved
     * nothing about what an upload would actually do.
     */
    public function buildDiskConfig(string $provider, array $creds): array
    {
        return match ($provider) {
            'storage_s3' => [
                'driver' => 's3',
                'key' => $creds['key'] ?? null,
                'secret' => $creds['secret'] ?? null,
                'region' => $creds['region'] ?? 'us-east-1',
                'bucket' => $creds['bucket'] ?? null,
                'url' => self::publicBaseUrl($creds['url'] ?? null),
                'endpoint' => null,
                'use_path_style_endpoint' => false,
                'throw' => false,
            ],
            'storage_do' => [
                'driver' => 's3',
                'key' => $creds['key'] ?? null,
                'secret' => $creds['secret'] ?? null,
                'region' => $creds['region'] ?? 'nyc3',
                'bucket' => $creds['bucket'] ?? null,
                'url' => self::publicBaseUrl($creds['url'] ?? null),
                'endpoint' => $creds['endpoint'] ?? 'https://nyc3.digitaloceanspaces.com',
                'use_path_style_endpoint' => false,
                'throw' => false,
                'visibility' => 'public',
                'options' => ['ACL' => 'public-read'],
            ],
            'storage_wasabi' => [
                'driver' => 's3',
                'key' => $creds['key'] ?? null,
                'secret' => $creds['secret'] ?? null,
                'region' => $creds['region'] ?? 'us-east-1',
                'bucket' => $creds['bucket'] ?? null,
                'url' => self::publicBaseUrl($creds['url'] ?? null),
                'endpoint' => $creds['endpoint'] ?? 'https://s3.wasabisys.com',
                'use_path_style_endpoint' => false,
                'throw' => false,
            ],
            // Cloudflare R2. If you are here to add a fifth storage provider,
            // copy THIS arm, not 'storage_do' — and read why first.
            //
            // R2 does not implement S3 ACLs: x-amz-acl is listed as
            // unimplemented for PutObject, CreateMultipartUpload and CopyObject
            // (developers.cloudflare.com/r2/api/s3/api/). It does not REJECT the
            // header — Cloudflare stopped doing that on 2022-11-30 — it ignores
            // it. So a bucket is public or private as a whole, decided in the
            // Cloudflare dashboard, and no per-object header can change that.
            //
            // That is why 'storage_do' is the wrong shape to copy. It sets
            // 'visibility' => 'public' and 'options' => ['ACL' => 'public-read'],
            // which on DigitalOcean genuinely makes each object world-readable.
            // Carried onto R2 the header does nothing, so the arm reads as if it
            // had made the bucket public when the bucket's own setting is the
            // only thing that decides — a comment that lies about the security
            // posture of the disk it configures.
            //
            // This arm sets 'visibility' => 'private' deliberately rather than
            // omitting it. Omitting it is ALMOST right: AwsS3V3Adapter::upload()
            // falls through to determineAcl() and sends x-amz-acl: private for
            // objects. But FilesystemManager::createS3Driver() also does
            // new AwsS3PortableVisibilityConverter($config['visibility'] ?? PUBLIC),
            // and that argument is the default for DIRECTORIES — so an omitted
            // key selects public there. Nothing calls makeDirectory() on a cloud
            // disk today, which makes it latent rather than broken; stating the
            // value makes the invariant true instead of nearly true.
            'storage_r2' => [
                'driver' => 's3',
                // Objects and directories both. See the note above: the omitted
                // key is public for directories, which is not what this says.
                'visibility' => 'private',
                'key' => $creds['key'] ?? null,
                'secret' => $creds['secret'] ?? null,
                // R2 has no regions. SigV4 still needs a region in the
                // credential scope and Cloudflare only accepts the literal
                // 'auto', so it is pinned here and never read from $creds —
                // storage_r2 has no region field for an admin to get wrong.
                'region' => 'auto',
                'bucket' => $creds['bucket'] ?? null,
                // The address the world reads objects from, which on R2 is
                // never the endpoint above — that one is the S3 API, it
                // authenticates every request, and it carries the account id.
                // Serving needs a custom domain bound to the bucket in
                // Cloudflare, so the admin supplies it and ->url() concatenates
                // it with the key.
                //
                // Null when the field is empty, which is the right answer for a
                // bucket holding only private files: PrivateStorageManager
                // strips this key anyway, and a public disk with no public URL
                // is a misconfiguration this should not paper over with a
                // plausible-looking address that 401s.
                'url' => self::publicBaseUrl($creds['url'] ?? null),
                'endpoint' => self::r2Endpoint($creds['account_id'] ?? '', $creds['jurisdiction'] ?? ''),
                // Path style: Cloudflare documents the S3 endpoint as
                // account-scoped, with the bucket as the first path segment
                // (https://<account>.r2.cloudflarestorage.com/<bucket>/<key>).
                // Virtual-hosted style would move the bucket into the hostname,
                // which R2 does serve but which breaks TLS for any bucket name
                // that is not a single legal DNS label. Path style works for
                // every bucket name R2 allows, so it is the deliberate choice
                // here rather than the 'false' inherited from the other arms.
                'use_path_style_endpoint' => true,
                'throw' => false,
            ],
            default => [],
        };
    }

    /**
     * The admin-supplied public base URL, or null when there is not a usable one.
     *
     * Trailing slash removed because FilesystemAdapter concatenates this with
     * '/'.$path, and a bucket serving every object from a doubled slash is the
     * kind of fault that looks like a CDN problem for a day.
     *
     * Only http(s) is accepted. The value ends up in an href the browser
     * follows, so a javascript: or data: scheme pasted into an admin field
     * would otherwise become script on every page that shows an avatar.
     */
    private static function publicBaseUrl(?string $url): ?string
    {
        $url = rtrim(trim((string) $url), '/');

        if ($url === '') {
            return null;
        }

        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));

        return in_array($scheme, ['http', 'https'], true) ? $url : null;
    }

    /**
     * Builds R2's S3 endpoint from the account ID and jurisdiction.
     *
     *   default (or anything unrecognised) → https://<account_id>.r2.cloudflarestorage.com
     *   eu                                 → https://<account_id>.eu.r2.cloudflarestorage.com
     *   fips                               → https://<account_id>.fips.r2.cloudflarestorage.com
     *
     * The "r2.cloudflarestorage.com" suffix is load-bearing, not cosmetic.
     * Illuminate\Filesystem\FilesystemManager::createFlysystem() does
     * str_contains($config['endpoint'] ?? '', 'r2.cloudflarestorage.com') and
     * only then forces retain_visibility => false. Without that flag Flysystem
     * reads an object's ACL (GetObjectAcl) before copying or moving it, which
     * R2 does not implement. Any other host shape — a custom domain, an r2.dev
     * URL — silently loses the accommodation, so the endpoint is derived here
     * rather than typed by an admin.
     *
     * A blank account ID still yields a cloudflarestorage.com host, which fails
     * to resolve and says so. Returning null instead would leave the AWS SDK
     * pointing at s3.amazonaws.com, sending R2 credentials to Amazon and
     * reporting an AWS error for a Cloudflare misconfiguration. ConnectionTester
     * rejects a blank account ID up front so this branch is not normally hit.
     */
    private static function r2Endpoint(string $accountId, string $jurisdiction): string
    {
        $accountId = trim($accountId);
        $jurisdiction = strtolower(trim($jurisdiction));
        $segment = in_array($jurisdiction, ['eu', 'fips'], true) ? $jurisdiction.'.' : '';

        return "https://{$accountId}.{$segment}r2.cloudflarestorage.com";
    }

    /**
     * Push the DB-sourced credentials into Laravel's runtime config and
     * forget any previously resolved disk instance so it is rebuilt fresh.
     */
    private function injectDiskConfig(string $diskName, array $diskConfig): void
    {
        if (empty($diskConfig)) {
            return;
        }

        Config::set("filesystems.disks.{$diskName}", $diskConfig);
        Storage::forgetDisk($diskName);
    }
}
