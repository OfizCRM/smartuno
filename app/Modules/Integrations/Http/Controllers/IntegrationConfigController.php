<?php

namespace App\Modules\Integrations\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Integrations\Models\IntegrationAuditLog;
use App\Modules\Integrations\Models\IntegrationConfig;
use App\Modules\Integrations\Services\ConnectionTester;
use App\Services\PrivateStorageManager;
use App\Services\StorageManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class IntegrationConfigController extends Controller
{
    public function index(): Response
    {
        $configs = IntegrationConfig::whereIn('provider', IntegrationConfig::PROVIDERS)->get()->keyBy('provider');

        $grouped = [];
        foreach (IntegrationConfig::PROVIDERS as $provider) {
            $config = $configs->get($provider);
            $category = IntegrationConfig::CATEGORIES[$provider];
            $grouped[$category][] = [
                'provider' => $provider,
                'label' => IntegrationConfig::LABELS[$provider],
                'category' => $category,
                'enabled' => $config?->enabled ?? false,
                'is_default' => $config?->is_default ?? false,
                'mode' => $config?->mode ?? 'live',
                'configured' => $config?->isConfigured() ?? false,
                'last_test_status' => $config?->last_test_status ?? 'untested',
                'last_test_message' => $config?->last_test_message,
                'last_tested_at' => $config?->last_tested_at?->toISOString(),
            ];
        }

        return Inertia::render('Admin/Integrations/Index', [
            'grouped' => $grouped,
            'storage' => $this->storageReality(),
        ]);
    }

    /**
     * What actually holds files right now — as opposed to what the cards imply.
     *
     * Three things on this screen were untrue before this method existed:
     *
     *  - Two enabled storage providers with neither flagged default both showed
     *    "Active", while StorageManager picks the first one in
     *    STORAGE_PROVIDERS order. One of those badges was always a lie, and
     *    which one depended on the order of a constant.
     *  - The "Default" star reads off is_default, which update() never clears
     *    when an admin unticks Enabled on the edit form. A disabled provider can
     *    therefore keep the star while a different provider takes the uploads.
     *  - Private files — documents, invoices, contracts — never appeared at all.
     *    They have always lived on the `local` disk and never on the provider
     *    the panel calls active, and nothing on this screen said so.
     *
     * Both sides are resolved here exactly the way an upload resolves them, so
     * the panel cannot drift from the behaviour it describes. activeProvider()
     * was dead code until this call; diskName() is called for its answer, and
     * its credential injection into the runtime config is the same harmless
     * work any upload in this request would have done.
     *
     * @return array{public: array{provider: ?string, label: string, disk: string, implicit: bool}, private: array{provider: string, label: string, disk: string, configured_provider: string, fallback_reason: ?string}}
     */
    private function storageReality(): array
    {
        $storage = app(StorageManager::class);
        $private = app(PrivateStorageManager::class);

        $publicProvider = $storage->activeProvider();
        $privateProvider = $private->provider();

        return [
            'public' => [
                // null means no storage integration is enabled at all. Uploads
                // still work — they land on the local `public` disk — so the
                // label says local and `implicit` says nobody chose it.
                'provider' => $publicProvider,
                'label' => IntegrationConfig::LABELS[$publicProvider ?? 'storage_local'],
                'disk' => $storage->diskName(),
                'implicit' => $publicProvider === null,
            ],
            'private' => [
                'provider' => $privateProvider,
                'label' => IntegrationConfig::LABELS[$privateProvider],
                'disk' => $private->diskName(),
                // Shown side by side with the resolved provider so a setting
                // that cannot be honoured is visible rather than swallowed.
                'configured_provider' => $private->configuredProvider(),
                // What the switch may be set to. Read from the map rather than
                // listed here, so a provider gaining a private implementation
                // appears in the panel without a second edit — and one that
                // cannot hold private files never appears at all.
                'options' => collect(array_keys(PrivateStorageManager::PRIVATE_DISK_MAP))
                    ->mapWithKeys(fn (string $slug) => [$slug => IntegrationConfig::LABELS[$slug]])
                    ->all(),
                'fallback_reason' => $private->fallbackReason(),
            ],
        ];
    }

    public function edit(string $provider): Response
    {
        abort_unless(in_array($provider, IntegrationConfig::PROVIDERS, true), 404);

        $config = IntegrationConfig::forProvider($provider) ?? new IntegrationConfig([
            'provider' => $provider,
            'label' => IntegrationConfig::LABELS[$provider],
            'mode' => 'live',
            'enabled' => false,
        ]);

        return Inertia::render('Admin/Integrations/Edit', [
            'provider' => $provider,
            'label' => IntegrationConfig::LABELS[$provider],
            'category' => IntegrationConfig::CATEGORIES[$provider],
            'fields' => IntegrationConfig::FIELDS[$provider],
            // OAuth redirect/callback URL the admin must register in the platform's app settings.
            'callbackUrl' => match ($provider) {
                'oauth_shopify' => route('client.ecommerce.oauth.shopify.callback'),
                'oauth_bigcommerce' => route('client.ecommerce.oauth.bigcommerce.callback'),
                default => null,
            },
            'config' => [
                'enabled' => $config->enabled ?? false,
                'mode' => $config->mode ?? 'live',
                'last_test_status' => $config->last_test_status ?? 'untested',
                'last_test_message' => $config->last_test_message,
                'last_tested_at' => $config->last_tested_at?->toISOString(),
                'credentials' => $config->exists ? $config->maskedCredentials() : [],
            ],
        ]);
    }

    public function update(Request $request, string $provider): RedirectResponse
    {
        abort_unless(in_array($provider, IntegrationConfig::PROVIDERS, true), 404);

        $fields = IntegrationConfig::FIELDS[$provider];
        $rules = ['enabled' => ['required', 'boolean'], 'mode' => ['required', 'in:test,live']];
        foreach ($fields as $f) {
            // Every field is nullable at the REQUEST level, including the ones
            // marked required, and that part is deliberate: the form renders a
            // saved secret as ••••, and a blank means "keep what is there". A
            // literal `required` rule would make an admin retype every secret
            // to change a checkbox.
            //
            // What it must not mean is that required goes unchecked — the line
            // here used to read
            //
            //     [$f['required'] ? 'nullable' : 'nullable', 'string', ...]
            //
            // with the same value in both branches, so the flag decided nothing
            // and a provider could be enabled with a blank bucket or a blank
            // secret. The failure then surfaced as uploads silently going
            // nowhere, a long way from the screen that caused it.
            //
            // So the requirement is enforced below, against the MERGED
            // credentials, which is the set that will actually be used.
            $rules['credentials.'.$f['key']] = ['nullable', 'string', 'max:1024'];
        }

        $validated = $request->validate($rules);

        $config = IntegrationConfig::firstOrNew(['provider' => $provider, 'mode' => $validated['mode']]);

        // Merge credentials: skip masked values (••••xxxx) to preserve existing
        $existing = $config->credentials ?? [];
        $incoming = $validated['credentials'] ?? [];
        $merged = $existing;
        $changedKeys = [];

        foreach ($incoming as $k => $v) {
            if ($v === null || $v === '') {
                continue;
            }
            if (preg_match('/^•+/', (string) $v)) {
                continue; // keep existing
            }
            $merged[$k] = $v;
            $changedKeys[] = $k;
        }

        // Checked only when the provider is being turned ON. A half-filled
        // draft can be saved and come back to; what cannot happen is an ENABLED
        // provider with a credential missing, because from that point something
        // in the product believes it has working storage, or a working Meta
        // app, and fails at the far end instead.
        if ($validated['enabled']) {
            $missing = [];

            foreach ($fields as $f) {
                if ($f['required'] && trim((string) ($merged[$f['key']] ?? '')) === '') {
                    $missing['credentials.'.$f['key']] = __(':field is required to enable this integration.', ['field' => $f['label']]);
                }
            }

            if ($missing !== []) {
                throw ValidationException::withMessages($missing);
            }
        }

        $wasEnabled = $config->enabled ?? false;
        $config->fill([
            'label' => IntegrationConfig::LABELS[$provider],
            'enabled' => (bool) $validated['enabled'],
            'mode' => $validated['mode'],
            'credentials' => $merged,
            'updated_by_admin_id' => auth('admin')->id(),
        ])->save();

        $this->auditLog($request, $config, $config->wasRecentlyCreated ? 'create' : 'update', $changedKeys);
        if ($wasEnabled !== $config->enabled) {
            $this->auditLog($request, $config, $config->enabled ? 'enable' : 'disable', []);
        }

        if (str_starts_with($provider, 'storage_')) {
            app(StorageManager::class)->clearCache();
        }

        return back()->with('success', __('Integration saved.'));
    }

    public function test(Request $request, string $provider): RedirectResponse|JsonResponse
    {
        abort_unless(in_array($provider, IntegrationConfig::PROVIDERS, true), 404);

        $config = IntegrationConfig::forProvider($provider);
        if (! $config) {
            return response()->json(['ok' => false, 'message' => __('Not configured yet.')]);
        }

        $result = app(ConnectionTester::class)->test($config);
        $this->auditLog($request, $config, 'test', []);

        if ($request->wantsJson()) {
            return response()->json($result);
        }

        return back()->with($result['ok'] ? 'success' : 'error', $result['message']);
    }

    public function toggle(Request $request, string $provider): RedirectResponse
    {
        abort_unless(in_array($provider, IntegrationConfig::PROVIDERS, true), 404);

        $config = IntegrationConfig::forProvider($provider);
        if (! $config) {
            return back()->with('error', __('Configure credentials before enabling.'));
        }

        $updates = ['enabled' => ! $config->enabled];
        // If disabling a storage that was the default, clear its default flag
        if ($config->enabled && ($config->is_default ?? false) && str_starts_with($provider, 'storage_')) {
            $updates['is_default'] = false;
        }
        $config->update($updates);
        $this->auditLog($request, $config, $config->enabled ? 'enable' : 'disable', []);

        if (str_starts_with($provider, 'storage_')) {
            app(StorageManager::class)->clearCache();
        }

        return back()->with('success', $config->enabled ? __('Integration enabled.') : __('Integration disabled.'));
    }

    /**
     * Choose where PRIVATE files live — documents, offers, conversation media.
     *
     * A second switch, not a second meaning for is_default. That column drives
     * the PUBLIC path, and the two answers are genuinely independent: a firm can
     * serve logos from R2 while contracts stay on the server disk, and the
     * reverse is a mistake nobody should be able to make by ticking one box.
     *
     * The setting is written even when it cannot be honoured, and then the
     * resolved answer is read back. That is deliberate: PrivateStorageManager
     * refuses R2 when there is no separate private bucket, or when the private
     * bucket IS the public one, and an admin who has just clicked "use R2" needs
     * to be told that in the same breath rather than discovering it when a
     * contract is not where they expect. The panel shows the same reason
     * permanently beside the provider.
     */
    public function setPrivate(Request $request, string $provider): RedirectResponse
    {
        abort_unless(array_key_exists($provider, PrivateStorageManager::PRIVATE_DISK_MAP), 404);

        $manager = app(PrivateStorageManager::class);
        $manager->setProvider($provider);

        $config = IntegrationConfig::forProvider($provider);

        if ($config) {
            $this->auditLog($request, $config, 'update', ['private_storage_provider']);
        }

        $resolved = $manager->provider();

        if ($resolved !== $provider) {
            return back()->with('error', __('Saved, but private files are still on :actual. :reason', [
                'actual' => IntegrationConfig::LABELS[$resolved],
                'reason' => match ($manager->fallbackReason()) {
                    'no_private_bucket' => __('Set a separate private bucket on this provider first.'),
                    'shared_bucket' => __('The private bucket must not be the same as the public one — on R2 a bucket is either published or it is not.'),
                    'missing_credentials' => __('This provider has no usable credentials yet.'),
                    default => __('This provider cannot hold private files.'),
                },
            ]));
        }

        return back()->with('success', __('Private files now stored on :provider.', [
            'provider' => IntegrationConfig::LABELS[$provider],
        ]));
    }

    public function setDefault(Request $request, string $provider): RedirectResponse
    {
        abort_unless(in_array($provider, IntegrationConfig::STORAGE_PROVIDERS, true), 404);

        $config = IntegrationConfig::forProvider($provider);
        if (! $config || ! $config->enabled) {
            return back()->with('error', __('Only an enabled storage provider can be set as default.'));
        }

        // Clear is_default on all other storage providers
        IntegrationConfig::whereIn('provider', IntegrationConfig::STORAGE_PROVIDERS)
            ->where('provider', '!=', $provider)
            ->update(['is_default' => false]);

        $config->update(['is_default' => true]);
        $this->auditLog($request, $config, 'update', ['is_default']);

        app(StorageManager::class)->clearCache();

        return back()->with('success', __(':provider set as default storage.', ['provider' => IntegrationConfig::LABELS[$provider]]));
    }

    public function rotate(Request $request, string $provider): RedirectResponse
    {
        abort_unless(in_array($provider, IntegrationConfig::PROVIDERS, true), 404);

        $config = IntegrationConfig::forProvider($provider);
        if (! $config) {
            return back()->with('error', __('Not configured.'));
        }

        $secret = bin2hex(random_bytes(32));
        $config->update(['webhook_secret' => $secret, 'updated_by_admin_id' => auth('admin')->id()]);
        $this->auditLog($request, $config, 'rotate', ['webhook_secret']);

        return back()->with('success', __('Webhook secret rotated.'));
    }

    public function auditLogIndex(Request $request): Response
    {
        $logs = IntegrationAuditLog::with('admin')
            ->latest('created_at')
            ->paginate(50);

        return Inertia::render('Admin/Integrations/AuditLog', ['logs' => $logs]);
    }

    private function auditLog(Request $request, IntegrationConfig $config, string $action, array $changedKeys): void
    {
        IntegrationAuditLog::create([
            'admin_user_id' => auth('admin')->id(),
            'integration_config_id' => $config->id,
            'provider' => $config->provider,
            'action' => $action,
            'diff_json' => $changedKeys,
            'ip' => $request->ip(),
            'user_agent' => substr($request->userAgent() ?? '', 0, 512),
        ]);
    }
}
