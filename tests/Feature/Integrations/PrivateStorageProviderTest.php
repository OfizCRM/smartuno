<?php

namespace Tests\Feature\Integrations;

use App\Models\AdminUser;
use App\Models\Permission;
use App\Models\Role;
use App\Models\SystemSetting;
use App\Modules\Integrations\Models\IntegrationConfig;
use App\Modules\Shared\Services\PrivateFileStore;
use App\Services\PrivateStorageManager;
use App\Services\StorageManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use LogicException;
use Tests\TestCase;

/**
 * The second storage switch, and the wall between it and the first one.
 *
 * StorageManager answers "where do logos go" and PrivateStorageManager answers
 * "where do contracts go". Stage 3 moves nothing: the private answer is the
 * `local` disk on every installation here, exactly as it was before. What is
 * asserted is that the two answers are now separately expressible, that neither
 * switch can move the other's files, and that the admin panel says which is
 * which instead of implying they are the same thing.
 *
 * Nothing here touches the network and nothing writes a file.
 */
class PrivateStorageProviderTest extends TestCase
{
    use RefreshDatabase;

    /** @return array<string, string> */
    private function r2Creds(): array
    {
        return [
            'account_id' => 'a1b2c3d4e5f6a7b8c9d0e1f2a3b4c5d6',
            'key' => 'r2-access-key-id',
            'secret' => 'r2-secret-access-key',
            // Two buckets, because one is not an arrangement R2 supports. The
            // public one carries the custom domain and answers anyone; the
            // private one has neither. PrivateStorageManager refuses to resolve
            // at all unless these differ — see PrivateBucketSeparationTest.
            'bucket' => 'smartuno-public',
            'private_bucket' => 'smartuno-private',
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

    /** Both managers cache for 60 s, so a test that changes a row must drop it. */
    private function privateManager(): PrivateStorageManager
    {
        $manager = app(PrivateStorageManager::class);
        $manager->clearCache();

        return $manager;
    }

    private function publicDiskName(): string
    {
        $manager = app(StorageManager::class);
        $manager->clearCache();

        return $manager->diskName();
    }

    // ── The wall ─────────────────────────────────────────────────────────────

    /**
     * The guard, stated as the invariant it is.
     *
     * Everything else in this file rests on the two disk-name sets being
     * disjoint. If they ever overlap, the public resolver can hand back the
     * disk that holds contracts — and the failure is silent, because both maps
     * are constants and no request is involved.
     */
    public function test_the_public_and_private_disk_name_sets_are_disjoint(): void
    {
        $shared = array_intersect(
            array_values(PrivateStorageManager::PRIVATE_DISK_MAP),
            array_values(IntegrationConfig::STORAGE_DISK_MAP),
        );

        $this->assertSame(
            [],
            array_values($shared),
            'A disk name appears in both PRIVATE_DISK_MAP and STORAGE_DISK_MAP. '.
            'One of the two must be renamed: public and private files cannot share a disk.'
        );
    }

    /**
     * The same property from the other end: no storage provider an admin can
     * enable resolves the PUBLIC path onto a private disk.
     */
    public function test_no_enabled_public_provider_resolves_to_a_private_disk(): void
    {
        foreach (IntegrationConfig::STORAGE_PROVIDERS as $provider) {
            IntegrationConfig::query()->delete();
            $this->storageProvider($provider, $provider === 'storage_local' ? [] : $this->r2Creds(), isDefault: true);

            $diskName = $this->publicDiskName();

            $this->assertFalse(
                PrivateStorageManager::isPrivateDisk($diskName),
                "Enabling {$provider} pointed the public upload path at the [{$diskName}] disk, ".
                'which is where private files live.'
            );
        }
    }

    public function test_the_public_resolver_refuses_a_private_disk_name(): void
    {
        // Reaching the guard requires the invariant above to be broken, which
        // no fixture can do to a constant. What is asserted here is the guard
        // itself: given a private disk name, it says no rather than returning.
        $this->assertTrue(PrivateStorageManager::isPrivateDisk(PrivateStorageManager::LOCAL_DISK));
        $this->assertFalse(PrivateStorageManager::isPrivateDisk('public'));
        $this->assertFalse(PrivateStorageManager::isPrivateDisk('r2'));
    }

    // ── The default: nothing has moved ───────────────────────────────────────

    public function test_private_files_resolve_to_the_local_disk_with_no_setting_row(): void
    {
        $manager = $this->privateManager();

        $this->assertSame('storage_local', $manager->provider());
        $this->assertSame('local', $manager->diskName());
        $this->assertTrue($manager->isLocal());
        $this->assertNull($manager->fallbackReason());
    }

    /**
     * The literal string matters more than it looks.
     *
     * Storage::fake() swaps a disk by name, and the suite fakes the private
     * disk in sixteen files. A private disk introduced under any other name
     * would leave every one of them writing to the developer's real
     * storage/app, and passing green while it did. It is also the only thing a
     * row written before the disk column existed can mean.
     */
    public function test_the_local_private_disk_is_the_literal_string_local(): void
    {
        $this->assertSame('local', PrivateStorageManager::LOCAL_DISK);
        $this->assertSame('local', PrivateStorageManager::PRIVATE_DISK_MAP['storage_local']);
        $this->assertSame(storage_path('app/private'), config('filesystems.disks.local.root'));
    }

    /**
     * The seam Stage 6 turns.
     *
     * PrivateFileStore::diskName() is where every private write resolves its
     * disk, and it answers from its own constant today; this class is where the
     * admin's choice will be read from when it stops doing that. While the two
     * are separate, they must at least agree, or the setting describes a disk
     * the files are not on. Nothing here forces the delegation early — it just
     * refuses to let the two answers drift apart quietly in the meantime.
     */
    public function test_the_private_file_store_and_the_setting_name_the_same_disk_today(): void
    {
        $this->assertSame(
            PrivateStorageManager::PRIVATE_DISK_MAP['storage_local'],
            app(PrivateFileStore::class)->diskName(),
            'PrivateFileStore writes to a different disk than the private-storage setting resolves to.'
        );
    }

    /**
     * Resolving the private disk must not rewrite the `local` disk config.
     *
     * StorageManager::diskName() publishes a disk config as a side effect. If
     * the private side did the same for its local arm, Storage::fake('local')
     * would be undone by the next resolution.
     */
    public function test_resolving_the_local_private_disk_leaves_its_config_untouched(): void
    {
        $before = config('filesystems.disks.local');

        $this->privateManager()->diskName();

        $this->assertSame($before, config('filesystems.disks.local'));
    }

    /**
     * Enabling a cloud provider for the PUBLIC path must not move a single
     * private file. This is the whole point of the second switch.
     */
    public function test_enabling_r2_for_public_uploads_does_not_move_private_files(): void
    {
        $this->storageProvider('storage_r2', $this->r2Creds(), isDefault: true);

        $this->assertSame('r2', $this->publicDiskName(), 'Fixture is wrong: R2 did not take the public path.');

        $manager = $this->privateManager();

        $this->assertSame('storage_local', $manager->provider());
        $this->assertSame('local', $manager->diskName());
    }

    /** And the mirror: choosing a private provider must not move the logos. */
    public function test_choosing_r2_for_private_files_does_not_move_public_uploads(): void
    {
        $this->storageProvider('storage_r2', $this->r2Creds(), enabled: false);
        $this->privateManager()->setProvider('storage_r2');

        $this->assertSame(
            'public',
            $this->publicDiskName(),
            'The private selection reached the public resolver.'
        );
    }

    // ── The setting ──────────────────────────────────────────────────────────

    public function test_set_provider_writes_one_platform_wide_row(): void
    {
        $this->privateManager()->setProvider('storage_local');

        $this->assertDatabaseHas('system_settings', [
            'key' => PrivateStorageManager::SETTING_KEY,
            'value' => 'storage_local',
            'group' => PrivateStorageManager::SETTING_GROUP,
            'is_secret' => false,
        ]);

        $this->assertSame(1, SystemSetting::where('key', PrivateStorageManager::SETTING_KEY)->count());
    }

    public function test_set_provider_rejects_a_provider_that_cannot_hold_private_files(): void
    {
        // do_spaces is declared public-read in config and in buildDiskConfig;
        // s3 and wasabi inherit a bucket policy set outside this repository.
        foreach (['storage_do', 'storage_s3', 'storage_wasabi', 'nonsense'] as $provider) {
            try {
                $this->privateManager()->setProvider($provider);
                $this->fail("setProvider accepted [{$provider}] as a private backend.");
            } catch (InvalidArgumentException $e) {
                $this->assertStringContainsString($provider, $e->getMessage());
            }
        }

        $this->assertDatabaseMissing('system_settings', ['key' => PrivateStorageManager::SETTING_KEY]);
    }

    public function test_r2_resolves_to_its_own_private_disk_when_credentials_exist(): void
    {
        $this->storageProvider('storage_r2', $this->r2Creds(), enabled: false);
        $manager = $this->privateManager();
        $manager->setProvider('storage_r2');

        $diskName = $manager->diskName();

        $this->assertSame('storage_r2', $manager->provider());
        $this->assertNull($manager->fallbackReason());

        // Its own name, not 'r2'. R2 has no per-object ACLs, so a bucket is
        // public or private as a whole: the bucket that serves logos cannot
        // also hold contracts, which makes them two buckets and two disks.
        $this->assertSame('r2_private', $diskName);
        $this->assertNotSame('r2', $diskName);

        $config = config("filesystems.disks.{$diskName}");
        $this->assertIsArray($config, 'No disk config was published for the private R2 disk.');
        $this->assertSame('smartuno-private', $config['bucket']);
        $this->assertSame('private', $config['visibility'] ?? null);
        $this->assertStringContainsString('r2.cloudflarestorage.com', (string) $config['endpoint']);
    }

    /**
     * The credentials row is read for keys and bucket. Its `enabled` and
     * `is_default` columns are the PUBLIC switch and must not be consulted:
     * an admin who wants R2 for contracts but not for logos has to be able to
     * say so.
     */
    public function test_the_private_selection_ignores_the_public_enabled_and_default_flags(): void
    {
        $this->storageProvider('storage_r2', $this->r2Creds(), enabled: false, isDefault: false);
        $manager = $this->privateManager();
        $manager->setProvider('storage_r2');

        $this->assertSame('r2_private', $manager->diskName());
        $this->assertSame(
            'public',
            $this->publicDiskName(),
            'A disabled R2 row took over the public path.'
        );
    }

    public function test_a_provider_with_no_credentials_falls_back_to_the_local_disk(): void
    {
        $manager = $this->privateManager();
        $manager->setProvider('storage_r2'); // no integration_configs row at all

        $this->assertSame('local', $manager->diskName());
        $this->assertSame('storage_local', $manager->provider());
        $this->assertSame('storage_r2', $manager->configuredProvider());
        $this->assertSame('missing_credentials', $manager->fallbackReason());
    }

    /**
     * setProvider() cannot store an unsupported slug, but a hand-edited row can.
     * The fallback direction has to be local: private files staying where they
     * are is safe, a guess at a cloud bucket is not.
     */
    public function test_a_hand_edited_unsupported_value_falls_back_to_the_local_disk(): void
    {
        SystemSetting::set(PrivateStorageManager::SETTING_KEY, 'storage_do', false, 'storage');

        $manager = $this->privateManager();

        $this->assertSame('local', $manager->diskName());
        $this->assertSame('storage_do', $manager->configuredProvider());
        $this->assertSame('unsupported_provider', $manager->fallbackReason());
    }

    public function test_an_empty_setting_value_falls_back_to_local_without_complaining(): void
    {
        SystemSetting::set(PrivateStorageManager::SETTING_KEY, '', false, 'storage');

        $manager = $this->privateManager();

        $this->assertSame('local', $manager->diskName());
        $this->assertSame('storage_local', $manager->provider());
        $this->assertNull($manager->fallbackReason(), 'A blank value is absence, not a misconfiguration.');
    }

    // ── What the admin panel says ────────────────────────────────────────────

    private function adminWithIntegrationAccess(): AdminUser
    {
        $admin = AdminUser::factory()->create(['status' => AdminUser::STATUS_ACTIVE]);
        $role = Role::create(['name' => 'Integrations', 'key' => 'INTEGRATIONS_TEST']);
        $permission = Permission::firstOrCreate(
            ['key' => 'manage_integrations'],
            ['name' => 'Manage integrations', 'category' => 'Integrations']
        );
        $role->permissions()->attach($permission);
        $admin->roles()->attach($role);

        return $admin;
    }

    /**
     * The panel used to show one "Active" badge per enabled storage provider
     * and nothing at all about private files. Both halves are asserted here.
     */
    public function test_the_integrations_panel_reports_both_backends(): void
    {
        $this->storageProvider('storage_r2', $this->r2Creds(), isDefault: true);

        $this->actingAs($this->adminWithIntegrationAccess(), 'admin')
            ->get(route('admin.integrations.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Admin/Integrations/Index')
                ->where('storage.public.provider', 'storage_r2')
                ->where('storage.public.disk', 'r2')
                ->where('storage.public.implicit', false)
                ->where('storage.private.provider', 'storage_local')
                ->where('storage.private.disk', 'local')
                ->where('storage.private.fallback_reason', null)
            );
    }

    /**
     * Nothing enabled is the state every installation starts in. Uploads still
     * land on the local public disk, and the panel has to say that rather than
     * leaving the row blank.
     */
    public function test_the_panel_marks_the_public_fallback_as_nobody_s_choice(): void
    {
        $this->actingAs($this->adminWithIntegrationAccess(), 'admin')
            ->get(route('admin.integrations.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('storage.public.provider', null)
                ->where('storage.public.disk', 'public')
                ->where('storage.public.implicit', true)
                ->where('storage.private.disk', 'local')
            );
    }

    /**
     * Two enabled providers, neither flagged default: StorageManager picks by
     * array order, and the panel now names the winner instead of badging both.
     */
    public function test_the_panel_names_one_winner_when_two_providers_are_enabled(): void
    {
        $this->storageProvider('storage_local');
        $this->storageProvider('storage_r2', $this->r2Creds());

        $expected = $this->publicDiskName();

        $this->actingAs($this->adminWithIntegrationAccess(), 'admin')
            ->get(route('admin.integrations.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('storage.public.disk', $expected)
                ->where('storage.public.implicit', false)
            );
    }

    public function test_the_guard_message_names_both_constants(): void
    {
        // Documented behaviour of the exception, asserted without breaking a
        // constant: the message has to tell whoever hits it which two maps
        // collided, because nothing in a stack trace otherwise would.
        $reflection = new \ReflectionMethod(StorageManager::class, 'assertNotPrivateDisk');
        $reflection->setAccessible(true);

        try {
            $reflection->invoke(null, PrivateStorageManager::LOCAL_DISK);
            $this->fail('The public resolver accepted a private disk name.');
        } catch (LogicException $e) {
            $this->assertStringContainsString('STORAGE_DISK_MAP', $e->getMessage());
            $this->assertStringContainsString('PRIVATE_DISK_MAP', $e->getMessage());
        }
    }
}
