<?php

namespace Tests\Feature\Integrations;

use App\Models\SystemSetting;
use App\Modules\Integrations\Models\IntegrationConfig;
use App\Services\PrivateStorageManager;
use App\Services\StorageManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Private files and public files do not share a bucket.
 *
 * This is the whole of stage 6, and it is one sentence because on an object
 * store there is no second arrangement. R2 has no per-object ACL, and a key
 * prefix grants nothing — a bucket is published or it is not. The public bucket
 * MUST be published, because that is where logos and avatars are served from.
 * So a private file in it is a public file with a longer name.
 *
 * StorageManager and PrivateStorageManager both resolve the same
 * IntegrationConfig row, which is right — same account, same keys — but it means
 * the bucket is the only thing that separates them, and nothing used to make it
 * different. These pin the refusal.
 */
class PrivateBucketSeparationTest extends TestCase
{
    use RefreshDatabase;

    private function r2(array $credentials): IntegrationConfig
    {
        return IntegrationConfig::create([
            'provider' => 'storage_r2',
            'label' => 'Cloudflare R2',
            'mode' => 'live',
            'enabled' => true,
            'credentials' => array_merge([
                'account_id' => 'abc123',
                'key' => 'k',
                'secret' => 's',
                'bucket' => 'smartuno-public',
            ], $credentials),
        ]);
    }

    private function manager(): PrivateStorageManager
    {
        $m = app(PrivateStorageManager::class);
        $m->clearCache();

        return $m;
    }

    private function chooseR2(): PrivateStorageManager
    {
        $m = $this->manager();
        $m->setProvider('storage_r2');

        return $this->manager();
    }

    public function test_the_same_bucket_for_both_is_refused(): void
    {
        $this->r2(['private_bucket' => 'smartuno-public']);
        $manager = $this->chooseR2();

        // The setting says R2. The answer does not, and says why.
        $this->assertSame('storage_local', $manager->provider());
        $this->assertSame('storage_r2', $manager->configuredProvider());
        $this->assertSame('shared_bucket', $manager->fallbackReason());
        $this->assertSame('local', $manager->diskName());
    }

    public function test_no_private_bucket_at_all_is_refused(): void
    {
        $this->r2([]);
        $manager = $this->chooseR2();

        // Not an error — logos on R2 with contracts on the server disk is a
        // legitimate arrangement. It just is not the one that was asked for, so
        // it is reported rather than silently treated as success.
        $this->assertSame('storage_local', $manager->provider());
        $this->assertSame('no_private_bucket', $manager->fallbackReason());
    }

    public function test_a_blank_private_bucket_is_not_a_bucket(): void
    {
        $this->r2(['private_bucket' => '   ']);

        $this->assertSame('no_private_bucket', $this->chooseR2()->fallbackReason());
    }

    public function test_two_distinct_buckets_are_honoured(): void
    {
        $this->r2(['private_bucket' => 'smartuno-private']);
        $manager = $this->chooseR2();

        $this->assertSame('storage_r2', $manager->provider());
        $this->assertNull($manager->fallbackReason());
        $this->assertSame('r2_private', $manager->diskName());
    }

    public function test_the_private_disk_uses_the_private_bucket_and_carries_no_url(): void
    {
        $this->r2(['private_bucket' => 'smartuno-private', 'url' => 'https://media.firma.ro']);
        $config = config('filesystems.disks.'.$this->chooseR2()->diskName());

        $this->assertIsArray($config);

        // The one that matters: writes must not land in the published bucket.
        $this->assertSame('smartuno-private', $config['bucket']);

        // And the custom domain belongs to the public bucket only. A 'url' here
        // makes FilesystemAdapter::url() return a working address for every
        // contract in the product.
        $this->assertArrayNotHasKey('url', $config);
        $this->assertSame('private', $config['visibility']);
    }

    public function test_the_public_disk_keeps_the_public_bucket(): void
    {
        $this->r2(['private_bucket' => 'smartuno-private']);
        $this->chooseR2();

        // Same row, same credentials, deliberately — and a different bucket.
        $public = app(StorageManager::class)->buildDiskConfig('storage_r2', [
            'account_id' => 'abc123', 'key' => 'k', 'secret' => 's',
            'bucket' => 'smartuno-public', 'private_bucket' => 'smartuno-private',
        ]);

        $this->assertSame('smartuno-public', $public['bucket']);
    }

    public function test_the_switch_is_independent_of_the_public_default(): void
    {
        $config = $this->r2(['private_bucket' => 'smartuno-private']);
        $config->update(['is_default' => false]);

        // is_default drives the PUBLIC path. Private storage must not read it:
        // a firm serving logos locally can still keep contracts on R2, and the
        // reverse pairing must not follow from one tick.
        $this->assertSame('storage_r2', $this->chooseR2()->provider());
    }

    public function test_a_provider_that_cannot_hold_private_files_is_rejected_outright(): void
    {
        foreach (['storage_s3', 'storage_do', 'storage_wasabi', 'nonsense'] as $slug) {
            try {
                $this->manager()->setProvider($slug);
                $this->fail("setProvider accepted [{$slug}].");
            } catch (\InvalidArgumentException $e) {
                // Right answer: the map is the allow-list, and it names itself.
                $this->assertStringContainsString('cannot hold private files', $e->getMessage());
            }
        }
    }

    public function test_a_hand_edited_setting_still_falls_back(): void
    {
        $this->r2(['private_bucket' => 'smartuno-private']);

        // setProvider() validates, so this is the shape a direct DB edit leaves.
        SystemSetting::set(PrivateStorageManager::SETTING_KEY, 'storage_wasabi', false, 'storage');

        $manager = $this->manager();
        $this->assertSame('storage_local', $manager->provider());
        $this->assertSame('unsupported_provider', $manager->fallbackReason());
    }
}
