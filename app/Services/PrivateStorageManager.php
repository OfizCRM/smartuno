<?php

namespace App\Services;

use App\Models\SystemSetting;
use App\Modules\Integrations\Models\IntegrationConfig;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use LogicException;

/**
 * Which backend holds the files that must never have a public URL.
 *
 * This is the SECOND storage switch, and it is deliberately not the first one.
 * StorageManager answers "where do logos, avatars and message media go" — files
 * whose whole purpose is to be fetched by URL with no authentication. This class
 * answers "where do invoices, contracts and scanned IDs go". The two questions
 * have different right answers, and a single control that answered both would
 * mean an admin who wanted a CDN for logos also moved every signed contract, or
 * an admin who moved contracts to a private bucket also stopped serving logos.
 *
 * WHERE THE SETTING LIVES, AND WHAT WAS REJECTED
 *
 * The value is one row in `system_settings`, key `private_storage_provider`.
 *
 *  - ClientSetting was rejected: it is keyed by client_id, i.e. per tenant.
 *    Storage is a platform decision, not a workspace one — one bucket serves
 *    every workspace, the migration that fills it is a platform operation, and
 *    there is no screen on which a client could answer this question. A
 *    per-client key would also multiply the half-migrated states that the new
 *    `disk` column has to express, from two to one per workspace.
 *
 *  - IntegrationConfig was rejected as the home for the SELECTION, and kept as
 *    the home for the CREDENTIALS. Its selection mechanism is the pair
 *    (`enabled`, `is_default`), and that pair is already the public switch:
 *    StorageManager::resolved() reads exactly those two columns. A second
 *    boolean on the same row would sit one click away from "Set Default" in the
 *    same card, which is how the two switches get confused for each other.
 *    Credentials are a different matter — an R2 account is an R2 account — so
 *    this class reads the same integration_configs row for keys and bucket, and
 *    reads NEITHER `enabled` NOR `is_default` from it. Enabling R2 for public
 *    uploads must not move private files, and vice versa.
 *
 *  - SystemSetting was chosen: platform-wide, one key, one value, already the
 *    home of every other single-value platform choice (branding, Pusher,
 *    Firebase). Stage 6 flips the private backend with one call to
 *    setProvider(), and nothing else in the codebase has to change.
 *
 * WHAT THIS DOES NOT DO
 *
 * It does not move anything, and today it resolves to 'local' for every
 * installation: no row exists under this key, so the default applies. Nothing
 * calls setProvider() yet and there is no admin control that writes it — the
 * integrations panel shows the resolved answer read-only. A switch that moved
 * every private file in the platform is a Stage 6 decision with a backfill
 * behind it, not a toggle to leave lying around while the files are still on
 * one disk.
 */
class PrivateStorageManager
{
    /** The `system_settings` key holding the private-storage provider slug. */
    public const SETTING_KEY = 'private_storage_provider';

    /** The `system_settings` group, for the grouped Advanced settings screen. */
    public const SETTING_GROUP = 'storage';

    /** The provider slug meaning "the server's own disk". */
    public const LOCAL_PROVIDER = 'storage_local';

    /**
     * The local private disk name, and it must stay this exact string.
     *
     * config/filesystems.php points `local` at storage_path('app/private'),
     * outside the web root. Storage::fake() swaps a disk BY NAME, and sixteen
     * test files fake the private disk: a private disk introduced under any
     * other name — 'private', 'documents' — would leave every one of them
     * writing to the developer's real storage/app, and passing while it did.
     *
     * It is also the only thing a row written before the disk column existed
     * can mean. PrivateFileStore::FALLBACK_DISK pins the same literal for that
     * reason, separately from where new writes go. This constant is the name
     * those two already agree on, not a new disk.
     */
    public const LOCAL_DISK = 'local';

    /**
     * Provider slug → private disk name.
     *
     * The values here are chosen to be DISJOINT from
     * IntegrationConfig::STORAGE_DISK_MAP, and that disjointness is the guard
     * that keeps the private backend out of the public resolver. See
     * assertNotPublicDisk() below and the matching check in
     * StorageManager::diskName().
     *
     * 'storage_r2' maps to 'r2_private' and not to 'r2' for a reason that is
     * not merely hygiene: R2 does not implement per-object ACLs, so a bucket is
     * public or private as a whole (see the storage_r2 arm in StorageManager).
     * A bucket that serves logos to the open internet therefore cannot also
     * hold contracts. Public R2 and private R2 are two buckets, so they are two
     * disks, so they need two names.
     *
     * s3, do_spaces and wasabi are absent on purpose. do_spaces is declared
     * 'visibility' => 'public' with ['ACL' => 'public-read'] in both
     * config/filesystems.php and StorageManager::buildDiskConfig(), so it is
     * certainly world-readable; s3 and wasabi declare no visibility at all and
     * inherit whatever bucket policy was set outside this repository, which
     * cannot be read from here. This map is an allowlist, not a lookup table:
     * a provider absent from it cannot hold private files, and a value written
     * into the setting by hand falls back to the local disk rather than
     * silently landing contracts somewhere unknowable.
     */
    public const PRIVATE_DISK_MAP = [
        'storage_local' => 'local',
        'storage_r2' => 'r2_private',
    ];

    private const CACHE_KEY = 'private_storage_resolved';

    private const CACHE_TTL = 60; // seconds

    /**
     * The slug as it is stored, before validation.
     *
     * Differs from provider() only when the stored value cannot be honoured,
     * which is what the admin panel shows next to the resolved answer.
     */
    public function configuredProvider(): string
    {
        return $this->resolved()['configured'];
    }

    /** The provider slug that actually holds private files right now. */
    public function provider(): string
    {
        return $this->resolved()['provider'];
    }

    /**
     * Why the stored provider is not the one in use, or null when it is.
     *
     * A machine code — 'unsupported_provider', 'missing_credentials',
     * 'no_private_bucket' or 'shared_bucket' — never a sentence. The panel owns
     * the wording, in both languages.
     */
    public function fallbackReason(): ?string
    {
        return $this->resolved()['reason'];
    }

    public function isLocal(): bool
    {
        return $this->provider() === self::LOCAL_PROVIDER;
    }

    /**
     * The Laravel disk name for private files, ready to use.
     *
     * For the local disk this returns the string and touches nothing else —
     * config/filesystems.php already describes it, and rewriting that entry at
     * runtime is exactly what would break Storage::fake('local'). Only a cloud
     * provider gets its credentials injected, and only under its own private
     * disk name.
     */
    /**
     * A disk config from StorageManager, made fit for private files.
     *
     * buildDiskConfig() is deliberately shared with the public path — the R2
     * endpoint rule that Laravel's own ACL accommodation keys off is subtle
     * enough that a second copy would drift, which is exactly what a hand-rolled
     * copy in ConnectionTester once did. What it returns is therefore shaped for
     * the public bucket, and it carries `url` because a public bucket is served
     * from a custom domain.
     *
     * On the private disk that key is the whole hole: FilesystemAdapter::url()
     * checks it first and returns it concatenated with the path, so every
     * contract and invoice gains an address that resolves without passing
     * through any controller and therefore without any workspace check. It
     * arrives null today, which is harmless — and one `?? config(...)` away from
     * not being. PrivateStorageBoundaryTest requires the key to be ABSENT on the
     * private disk, and a disk that appears at runtime is held to the same rule
     * as one written in config/filesystems.php.
     *
     * @param  array<string, mixed>  $config
     * @param  array<string, mixed>  $credentials
     * @return array<string, mixed>
     */
    private function forPrivateUse(array $config, array $credentials): array
    {
        // The bucket is the boundary, so it has to change here.
        //
        // buildDiskConfig() answers with the PUBLIC bucket, because that is what
        // it is for. Writing private files into it would put every contract and
        // invoice in the bucket the custom domain serves — and on R2 a prefix is
        // not a permission boundary: a bucket is either published or it is not.
        // resolved() refuses to reach this method at all unless a distinct
        // private bucket is configured, so by the time we are here there is one.
        //
        // $credentials has no default, deliberately. It had one for an hour and
        // one of the two call sites was left without it, which meant the private
        // disk was built with the PUBLIC bucket — silently, since every other
        // field was right and the panel reported success. A parameter that can
        // be forgotten will be.
        $private = trim((string) ($credentials['private_bucket'] ?? ''));

        if ($private !== '') {
            $config['bucket'] = $private;
        }

        unset($config['url']);

        // Stated rather than assumed. On an S3-compatible driver this is what
        // keeps a written object from being world-readable.
        $config['visibility'] = 'private';

        return $config;
    }

    /**
     * Make a disk by NAME usable, whether or not it is the current default.
     *
     * diskName() configures the disk it is about to return, which covers every
     * write. Reads do not go through it: they take the disk recorded on the row,
     * which after a migration is frequently NOT the current default. Without
     * this, Storage::disk('r2_private') raises InvalidArgumentException — the
     * name exists only as a Config::set side effect of diskName(), never in
     * config/filesystems.php — and every download of an already-migrated file
     * becomes a 500 rather than the 404 its call site is written to expect.
     *
     * Only names in PRIVATE_DISK_MAP are configured. Anything else is left to
     * Laravel to reject, which is the right answer for a disk name that reached
     * a row without passing through setProvider().
     */
    public function ensureDisk(string $diskName): string
    {
        if ($diskName === self::LOCAL_DISK || ! in_array($diskName, self::PRIVATE_DISK_MAP, true)) {
            return $diskName;
        }

        if (config("filesystems.disks.{$diskName}") !== null) {
            return $diskName;
        }

        $provider = array_search($diskName, self::PRIVATE_DISK_MAP, true);
        $config = $provider === false ? null : IntegrationConfig::forProvider((string) $provider);

        if ($config !== null) {
            Config::set(
                "filesystems.disks.{$diskName}",
                $this->forPrivateUse(
                    app(StorageManager::class)->buildDiskConfig((string) $provider, $config->credentials ?? []),
                    $config->credentials ?? [],
                ),
            );
            Storage::forgetDisk($diskName);
        }

        return $diskName;
    }

    public function diskName(): string
    {
        $resolved = $this->resolved();
        $diskName = self::assertNotPublicDisk($resolved['disk']);

        if ($resolved['disk_config'] !== []) {
            Config::set("filesystems.disks.{$diskName}", $resolved['disk_config']);
            Storage::forgetDisk($diskName);
        }

        return $diskName;
    }

    /**
     * The one place Stage 6 changes.
     *
     * Rejects anything outside PRIVATE_DISK_MAP up front rather than storing it
     * and falling back later: a typo that silently keeps writing to the local
     * disk is the failure that gets discovered by a customer.
     */
    public function setProvider(string $provider): void
    {
        if (! array_key_exists($provider, self::PRIVATE_DISK_MAP)) {
            throw new InvalidArgumentException(sprintf(
                '[%s] cannot hold private files. Allowed: %s.',
                $provider,
                implode(', ', array_keys(self::PRIVATE_DISK_MAP))
            ));
        }

        SystemSetting::set(self::SETTING_KEY, $provider, false, self::SETTING_GROUP);

        $this->clearCache();
    }

    /**
     * Clears the resolved cache. Call after writing the setting, or after
     * changing the credentials of the provider that holds private files.
     */
    public function clearCache(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    /** True when the given Laravel disk name is one that holds private files. */
    public static function isPrivateDisk(string $diskName): bool
    {
        return in_array($diskName, self::PRIVATE_DISK_MAP, true);
    }

    /**
     * The half of the guard that lives on this side.
     *
     * StorageManager::diskName() refuses to hand back a private disk; this
     * refuses to hand back a public one. Both are unreachable while the two
     * maps stay disjoint, which is the point: the check cannot fire on any
     * value an admin can type, only on a code change that made the two maps
     * overlap — a public logo written into the documents bucket, or a contract
     * written into a world-readable one. That is a programmer error and it
     * fails loudly, at the choke point, rather than being caught in review.
     */
    private static function assertNotPublicDisk(string $diskName): string
    {
        if (in_array($diskName, IntegrationConfig::STORAGE_DISK_MAP, true)) {
            throw new LogicException(sprintf(
                'The [%s] disk appears in both PrivateStorageManager::PRIVATE_DISK_MAP and '.
                'IntegrationConfig::STORAGE_DISK_MAP. Private files must not share a disk with '.
                'the public upload path; give the private backend its own disk name.',
                $diskName
            ));
        }

        return $diskName;
    }

    /**
     * Resolve the setting into a provider, a disk name and a disk config.
     *
     * Cached for the same 60 s as StorageManager, and for the same reason: this
     * is consulted once per file read and write, and the answer changes only
     * when an admin acts. Note that the cached array carries the built disk
     * config, credentials included, once a cloud provider is selected — the
     * pattern StorageManager::resolved() already established. It is inert today
     * (the local arm returns an empty config and caches no secret) and worth
     * revisiting in Stage 6 alongside where the cache store itself lives.
     *
     * Every failure falls back to the local disk. That direction is the safe
     * one: private files stay where they already are, behind the controllers
     * that check the workspace. Falling back to a cloud disk instead would put
     * contracts in a bucket nobody in this codebase can prove is private.
     *
     * @return array{configured: string, provider: string, disk: string, disk_config: array<string, mixed>, reason: ?string}
     */
    private function resolved(): array
    {
        return Cache::remember(self::CACHE_KEY, self::CACHE_TTL, function (): array {
            $configured = trim((string) SystemSetting::get(self::SETTING_KEY, self::LOCAL_PROVIDER));

            if ($configured === '') {
                $configured = self::LOCAL_PROVIDER;
            }

            $local = [
                'configured' => $configured,
                'provider' => self::LOCAL_PROVIDER,
                'disk' => self::LOCAL_DISK,
                'disk_config' => [],
                'reason' => null,
            ];

            if ($configured === self::LOCAL_PROVIDER) {
                return $local;
            }

            if (! array_key_exists($configured, self::PRIVATE_DISK_MAP)) {
                return array_merge($local, ['reason' => 'unsupported_provider']);
            }

            // Deliberately NOT ->enabled and NOT ->is_default: those two columns
            // are the public upload switch. All that is read here is whether
            // there are credentials to connect with.
            $config = IntegrationConfig::forProvider($configured);

            if (! $config || ! $config->isConfigured()) {
                return array_merge($local, ['reason' => 'missing_credentials']);
            }

            // The one refusal that matters on an object store.
            //
            // Public and private files share these credentials and this account
            // — that is deliberate, it is one Cloudflare account — but they must
            // not share the BUCKET. The public one carries a custom domain and
            // answers anyone; a private file in it is a public file with a long
            // name. R2 has no per-object ACL and a prefix grants nothing, so
            // there is no arrangement in which one bucket is both.
            //
            // Falling back to the local disk is the safe direction: private
            // files stay where they already are, behind the controllers that
            // check the workspace. The panel shows the reason beside the
            // configured provider, so this is refused visibly rather than
            // half-honoured.
            $credentials = $config->credentials ?? [];
            $privateBucket = trim((string) ($credentials['private_bucket'] ?? ''));

            if ($privateBucket === '') {
                return array_merge($local, ['reason' => 'no_private_bucket']);
            }

            if ($privateBucket === trim((string) ($credentials['bucket'] ?? ''))) {
                return array_merge($local, ['reason' => 'shared_bucket']);
            }

            return [
                'configured' => $configured,
                'provider' => $configured,
                'disk' => self::PRIVATE_DISK_MAP[$configured],
                // Reused rather than rebuilt. ConnectionTester was made to share
                // this method after a hand-rolled copy meant a green tick proved
                // nothing about the disk uploads actually used; a third copy here
                // would reintroduce that drift, and with it the R2 endpoint rule
                // that Laravel's own ACL accommodation depends on.
                'disk_config' => $this->forPrivateUse(
                    app(StorageManager::class)->buildDiskConfig($configured, $config->credentials ?? []),
                    $config->credentials ?? [],
                ),
                'reason' => null,
            ];
        });
    }
}
