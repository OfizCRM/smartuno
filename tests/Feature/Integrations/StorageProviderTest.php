<?php

namespace Tests\Feature\Integrations;

use App\Modules\Integrations\Models\IntegrationConfig;
use App\Modules\Integrations\Services\ConnectionTester;
use App\Services\StorageManager;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Which disk the platform writes to, and what that disk is made of.
 *
 * StorageManager resolves one row out of integration_configs into a Laravel
 * disk name and a ready-to-inject disk config. Both halves fail quietly:
 *
 *  - A provider missing from STORAGE_DISK_MAP resolves to 'public'. The admin
 *    panel still shows the cloud provider as active while every upload lands
 *    on the local disk, so the fallback is asserted against by name here.
 *  - Every cloud disk is built with 'throw' => false and PrivateFileStore::put()
 *    discards the write's boolean, so a disk config R2 rejects produces a green
 *    tick, a DB row, a success toast, a quota charge — and no file. Cloudflare
 *    R2 does not implement S3 ACLs: it answers 501 NotImplemented to the
 *    x-amz-acl: public-read header that the storage_do arm sends. The R2 arm
 *    must therefore carry no 'visibility' and no 'options' key at all, which
 *    is what makes Flysystem fall through to its own determineAcl and send
 *    x-amz-acl: private — a no-op R2 accepts. Those two absences are asserted
 *    by name, and the storage_do arm is asserted to still carry its ACL, so
 *    the R2 assertion cannot start passing vacuously.
 *
 * The endpoint shape matters beyond correctness of the hostname: Laravel's own
 * R2 accommodation in FilesystemManager (line 343) matches the literal string
 * "r2.cloudflarestorage.com" inside the endpoint to force retain_visibility to
 * false. A hostname built any other way silently loses that accommodation.
 *
 * Nothing here touches the network. Every assertion is made on the config that
 * would be handed to the S3 driver, never on a driver that was built from it.
 */
class StorageProviderTest extends TestCase
{
    use RefreshDatabase;

    /**
     * What an admin types into Admin › Integrations › Cloudflare R2.
     *
     * There is deliberately no region and no endpoint: R2 pins region to the
     * literal 'auto' in code, and the endpoint is built from account_id and
     * jurisdiction. Neither is a field on the form.
     *
     * @param  array<string, string>  $overrides
     * @return array<string, string>
     */
    private function r2Creds(array $overrides = []): array
    {
        return array_merge([
            'account_id' => 'a1b2c3d4e5f6a7b8c9d0e1f2a3b4c5d6',
            'key' => 'r2-access-key-id',
            'secret' => 'r2-secret-access-key',
            'bucket' => 'smartuno-files',
            'directory_prefix' => '',
        ], $overrides);
    }

    /** @return array<string, string> */
    private function doCreds(): array
    {
        return [
            'key' => 'do-spaces-key',
            'secret' => 'do-spaces-secret',
            'region' => 'fra1',
            'bucket' => 'smartuno-do',
            'endpoint' => 'https://fra1.digitaloceanspaces.com',
        ];
    }

    /** @param array<string, string> $creds */
    private function storageProvider(
        string $provider,
        array $creds = [],
        bool $enabled = true,
        bool $isDefault = false,
    ): IntegrationConfig {
        return IntegrationConfig::create([
            'provider' => $provider,
            'label' => IntegrationConfig::LABELS[$provider] ?? $provider,
            'mode' => 'live',
            'enabled' => $enabled,
            'is_default' => $isDefault,
            'credentials' => $creds,
        ]);
    }

    /**
     * Resolve the active disk name from scratch.
     *
     * StorageManager caches the resolution for 60 s, so a test that changes a
     * row between two resolutions has to drop the cache the way the admin
     * panel does when it saves.
     */
    private function resolveDiskName(): string
    {
        $manager = app(StorageManager::class);
        $manager->clearCache();

        return $manager->diskName();
    }

    /**
     * The disk config StorageManager would inject for a provider, obtained the
     * way the application obtains it: seed the row, resolve the disk, read back
     * what landed in the runtime config.
     *
     * @param  array<string, string>  $creds
     * @return array<string, mixed>
     */
    private function builtDiskConfig(string $provider, array $creds): array
    {
        $this->storageProvider($provider, $creds, isDefault: true);

        $diskName = $this->resolveDiskName();

        $config = config("filesystems.disks.{$diskName}");

        $this->assertIsArray($config, "No disk config was injected for {$provider}.");

        return $config;
    }

    // ── Which disk ───────────────────────────────────────────────────────────

    public function test_disk_name_is_public_when_no_storage_provider_is_enabled(): void
    {
        $this->assertSame('public', $this->resolveDiskName());
    }

    public function test_disk_name_is_public_for_storage_local(): void
    {
        $this->storageProvider('storage_local');

        $this->assertSame('public', $this->resolveDiskName());
    }

    public function test_disk_name_is_public_when_r2_is_configured_but_disabled(): void
    {
        $this->storageProvider('storage_r2', $this->r2Creds(), enabled: false);

        $this->assertSame(
            'public',
            $this->resolveDiskName(),
            'A provider that is configured but switched off must not take over the disk.'
        );
    }

    public function test_disk_name_is_r2_when_cloudflare_r2_is_enabled(): void
    {
        $this->storageProvider('storage_r2', $this->r2Creds());

        $diskName = $this->resolveDiskName();

        $this->assertSame('r2', $diskName);

        // Stated separately because 'public' is the exact way this fails: a
        // provider slug missing from STORAGE_DISK_MAP resolves to the local
        // disk, and every upload then lands on the server while the panel
        // reports R2 as the active backend.
        $this->assertNotSame(
            'public',
            $diskName,
            'storage_r2 fell back to the local public disk — check IntegrationConfig::STORAGE_DISK_MAP.'
        );
    }

    public function test_is_default_wins_over_the_first_merely_enabled_provider(): void
    {
        $this->storageProvider('storage_do', $this->doCreds());
        $this->storageProvider('storage_r2', $this->r2Creds());

        // With neither row flagged, the winner is whichever provider comes
        // first in STORAGE_PROVIDERS. Flag the *loser* of that run, so the
        // assertion below cannot pass just because the array happened to be
        // ordered in its favour.
        $winnerDisk = $this->resolveDiskName();
        $loser = $winnerDisk === 'r2' ? 'storage_do' : 'storage_r2';
        $loserDisk = IntegrationConfig::STORAGE_DISK_MAP[$loser];

        $this->assertNotSame($loserDisk, $winnerDisk, 'Both providers resolved to the same disk.');

        IntegrationConfig::where('provider', $loser)->update(['is_default' => true]);

        $this->assertSame(
            $loserDisk,
            $this->resolveDiskName(),
            'The admin-designated default must beat array order among enabled providers.'
        );
    }

    public function test_directory_prefix_is_normalised_with_a_trailing_slash(): void
    {
        $this->storageProvider('storage_r2', $this->r2Creds(['directory_prefix' => '/smartuno/']));

        $manager = app(StorageManager::class);
        $manager->clearCache();

        $this->assertSame('smartuno/', $manager->directoryPrefix());
        $this->assertSame('smartuno/documents/a.pdf', $manager->prefixedPath('documents/a.pdf'));
    }

    // ── What the R2 disk is made of ──────────────────────────────────────────

    public function test_r2_disk_config_pins_region_to_auto(): void
    {
        $config = $this->builtDiskConfig('storage_r2', $this->r2Creds());

        $this->assertSame('s3', $config['driver']);
        $this->assertSame('auto', $config['region'], "R2 has no regions; the S3 API expects the literal 'auto'.");
        $this->assertSame('smartuno-files', $config['bucket']);
        $this->assertSame('r2-access-key-id', $config['key']);
        $this->assertSame('r2-secret-access-key', $config['secret']);
    }

    public function test_r2_region_is_auto_even_if_a_region_is_present_in_the_credentials(): void
    {
        // There is no region field on the R2 form, but a row migrated from
        // another provider, or hand-edited, can still carry one.
        $config = $this->builtDiskConfig('storage_r2', $this->r2Creds(['region' => 'us-east-1']));

        $this->assertSame(
            'auto',
            $config['region'],
            'The R2 region is pinned in code and must never be read from stored credentials.'
        );
    }

    public function test_r2_disk_config_is_pinned_private_and_carries_no_acl_option(): void
    {
        $config = $this->builtDiskConfig('storage_r2', $this->r2Creds());

        // Stated, not omitted. An omitted visibility gives private OBJECTS —
        // AwsS3V3Adapter::upload falls through to determineAcl — but PUBLIC
        // DIRECTORIES, because Laravel hands the same key to
        // AwsS3PortableVisibilityConverter as its directory default. R2 ignores
        // x-amz-acl either way (it stopped rejecting public-read in 2022), so
        // what this pins is our own intent, not R2's behaviour: the bucket's
        // own setting is the only thing that decides who can read it.
        $this->assertSame(
            'private',
            $config['visibility'] ?? null,
            'The R2 disk must be pinned private, for directories as well as objects.'
        );

        $this->assertArrayNotHasKey(
            'options',
            $config,
            "The R2 arm must not carry an 'options' ACL header. R2 ignores it, so a ".
            'storage_do arm copied onto R2 reads as if it had made the objects world-readable '.
            'while only the bucket setting decides — a config that lies about its own posture.'
        );

        $this->assertNull(
            $config['url'] ?? null,
            'Stage 1 exposes no public bucket hostname for R2; nothing should read a URL off this disk.'
        );

        // The value is a deliberate decision documented in StorageManager —
        // R2 accepts both addressing styles — but the key has to be stated
        // rather than left to the driver default.
        $this->assertArrayHasKey('use_path_style_endpoint', $config);
        $this->assertIsBool($config['use_path_style_endpoint']);
    }

    /**
     * The companion that keeps the assertion above honest: if buildDiskConfig
     * ever stopped emitting ACLs for anyone, the two assertArrayNotHasKey calls
     * would pass for the wrong reason.
     */
    public function test_do_spaces_disk_config_still_carries_its_public_acl(): void
    {
        $config = $this->builtDiskConfig('storage_do', $this->doCreds());

        $this->assertSame('public', $config['visibility'] ?? null);
        $this->assertSame(['ACL' => 'public-read'], $config['options'] ?? null);
    }

    public function test_r2_endpoint_is_built_from_the_account_id_and_jurisdiction(): void
    {
        $account = 'a1b2c3d4e5f6a7b8c9d0e1f2a3b4c5d6';

        $cases = [
            'absent' => [[], "https://{$account}.r2.cloudflarestorage.com"],
            'blank' => [['jurisdiction' => ''], "https://{$account}.r2.cloudflarestorage.com"],
            'default' => [['jurisdiction' => 'default'], "https://{$account}.r2.cloudflarestorage.com"],
            'eu' => [['jurisdiction' => 'eu'], "https://{$account}.eu.r2.cloudflarestorage.com"],
            'fips' => [['jurisdiction' => 'fips'], "https://{$account}.fips.r2.cloudflarestorage.com"],
        ];

        foreach ($cases as $name => [$overrides, $expected]) {
            IntegrationConfig::where('provider', 'storage_r2')->delete();

            $config = $this->builtDiskConfig('storage_r2', $this->r2Creds($overrides));

            $this->assertSame($expected, $config['endpoint'], "Wrong endpoint for jurisdiction '{$name}'.");

            // FilesystemManager forces retain_visibility => false only when the
            // endpoint contains this exact host fragment. Any other hostname
            // shape loses Laravel's R2 accommodation without an error.
            $this->assertStringContainsString(
                'r2.cloudflarestorage.com',
                $config['endpoint'],
                "Laravel's R2 accommodation matches this literal host fragment; deviate and it stops applying."
            );
        }
    }

    public function test_r2_credential_fields_match_what_the_disk_builder_reads(): void
    {
        $fields = IntegrationConfig::FIELDS['storage_r2'] ?? null;

        $this->assertIsArray($fields, 'storage_r2 must declare its form fields in IntegrationConfig::FIELDS.');

        $keys = array_column($fields, 'key');

        $this->assertSame(
            ['account_id', 'key', 'secret', 'bucket', 'private_bucket', 'jurisdiction', 'url', 'directory_prefix'],
            $keys,
            'The form must collect exactly the credential keys buildDiskConfig reads, in this order.'
        );

        $this->assertNotContains('region', $keys, "R2 has no regions — the region is pinned to 'auto' in code.");
        $this->assertNotContains('endpoint', $keys, 'The R2 endpoint is derived from account_id and jurisdiction, never typed.');

        $this->assertSame(
            ['account_id', 'key', 'secret', 'bucket'],
            array_column(array_filter($fields, fn ($f) => ($f['required'] ?? false) === true), 'key'),
            'Only the four that no bucket can be reached without are required. private_bucket is '.
            'optional because keeping private files on the server disk while logos go to R2 is a '.
            'legitimate arrangement; what is NOT legitimate is it being equal to the public bucket, '.
            'and that is refused at resolve time rather than by this form.'
        );

        $this->assertSame(
            'password',
            Arr::first($fields, fn ($f) => $f['key'] === 'secret')['type'] ?? null,
            'The secret access key must render as a password field.'
        );
    }

    // ── The tester and the manager must not drift ────────────────────────────

    /**
     * The green tick has to mean something.
     *
     * ConnectionTester used to hand-build its own S3 array, which is how a
     * tester can pass against a disk config the application never uses. Both
     * sides are asserted to produce the same config for the same credentials —
     * and both are asserted separately to carry no ACL keys, so a regression
     * applied to both at once cannot satisfy the equality vacuously.
     */
    public function test_connection_tester_and_storage_manager_agree_on_the_r2_disk_config(): void
    {
        $creds = $this->r2Creds(['jurisdiction' => 'eu']);
        $row = $this->storageProvider('storage_r2', $creds, isDefault: true);

        $managerConfig = $this->builtDiskConfigForRow();
        $testerConfig = $this->captureTesterDiskConfig($row);

        foreach (['manager' => $managerConfig, 'tester' => $testerConfig] as $side => $config) {
            // Private, stated rather than omitted: an omitted visibility is
            // private for objects but PUBLIC for directories, because Laravel
            // passes it to AwsS3PortableVisibilityConverter as the directory
            // default. What must never be true is that it is public.
            $this->assertSame('private', $config['visibility'] ?? null, "The {$side} does not pin the disk private.");
            $this->assertArrayNotHasKey('options', $config, "The {$side} carries an ACL option copied from storage_do.");
        }

        // 'throw' is the one key the two sides are allowed to differ on: the
        // tester needs the exception to put a real reason on the screen, while
        // the application must not throw on a background upload.
        $manager = Arr::except($managerConfig, ['throw']);
        $tester = Arr::except($testerConfig, ['throw']);
        ksort($manager);
        ksort($tester);

        $this->assertSame(
            $manager,
            $tester,
            'ConnectionTester tested a disk config the application would never build.'
        );
    }

    /** @return array<string, mixed> */
    private function builtDiskConfigForRow(): array
    {
        $diskName = $this->resolveDiskName();

        $config = config("filesystems.disks.{$diskName}");

        $this->assertIsArray($config, 'StorageManager injected no disk config.');

        return $config;
    }

    /**
     * Run ConnectionTester's storage path and return the disk config it applied.
     *
     * The Storage facade is swapped for a local stand-in first, so the tester's
     * probe write, read-back and delete all happen on disk and nothing leaves
     * the machine. The config itself is captured whether the tester publishes
     * it through Config::set or hands it straight to Storage::build.
     *
     * @return array<string, mixed>
     */
    private function captureTesterDiskConfig(IntegrationConfig $row): array
    {
        $probe = Storage::fake('r2_probe');
        $captured = null;

        Storage::shouldReceive('build')->andReturnUsing(function (array $config) use (&$captured, $probe): Filesystem {
            $captured = $config;

            return $probe;
        });
        Storage::shouldReceive('forgetDisk')->andReturnNull();
        Storage::shouldReceive('disk')->andReturn($probe);

        $result = app(ConnectionTester::class)->test($row);

        $this->assertTrue(
            $result['ok'],
            'The R2 connection test failed against a local stand-in disk: '.($result['message'] ?? '')
        );

        $config = $captured ?? config('filesystems.disks.r2');

        $this->assertIsArray($config, 'ConnectionTester applied no disk config for storage_r2.');

        // Proof that what came back is the tester's own array and not the
        // manager's leftover under the same config key — without it, a tester
        // that published nothing would satisfy the parity assertion vacuously.
        // 'throw' => true is the tester's one deliberate override; every
        // runtime disk is built with false.
        $this->assertTrue(
            $config['throw'] ?? false,
            'ConnectionTester published no disk config of its own; the parity assertion would prove nothing.'
        );

        return $config;
    }
}
