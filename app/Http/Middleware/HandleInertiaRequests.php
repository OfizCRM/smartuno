<?php

namespace App\Http\Middleware;

use App\Models\Client;
use App\Models\Currency;
use App\Models\Locale;
use App\Models\SystemSetting;
use App\Models\User;
use App\Models\Workspace;
use App\Modules\Broadcasting\Models\UsageMeter;
use App\Modules\Integrations\Services\CredentialResolver;
use App\Modules\Offers\Models\Offer;
use App\Modules\Shared\Models\Conversation;
use App\Services\I18n\I18nFileService;
use App\Services\OnboardingService;
use App\Services\StorageManager;
use App\Support\Entitlement;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that is loaded on the first page visit.
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determine the current asset version.
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        $parent = parent::share($request);

        try {
            $app = $this->appShare($request);
        } catch (\Throwable $e) {
            Log::channel('single')->error('HandleInertiaRequests::share failed: '.$e->getMessage(), [
                'exception' => get_class($e),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);
            $locale = app()->getLocale();
            $app = [
                'csrf_token' => csrf_token(),
                'flash' => ['success' => null, 'error' => null],
                'auth' => [
                    'user' => $request->user(),
                    'adminUser' => null,
                    'permissions' => [],
                ],
                'inboxOpenCount' => 0,
                'offerAiDraftsCount' => 0,
                'currentWorkspace' => null,
                'workspaces' => [],
                'locale' => $locale,
                'dir' => 'ltr',
                'i18n' => [
                    'locale' => $locale,
                    'isRtl' => in_array($locale, ['ar'], true),
                    'locales' => [],
                ],
                'supportedLocales' => ['en' => 'English'],
                'rtlLocales' => ['ar'],
                'currencies' => [],
                'displayCurrency' => 'USD',
                'theme' => 'light',
                'demo_mode' => false,
                'app_version' => env('APP_VERSION', '1.0.0'),
                'onboardingSummary' => null,
                'subscription' => ['state' => Entitlement::ACTIVE, 'days_left' => null, 'grace_ends_at' => null, 'reason' => null],
            ];
        }

        return array_merge($parent, $app);
    }

    private function firebasePublicConfig(): array
    {
        try {
            $enabled = SystemSetting::get('firebase_enabled', 'false') === 'true';

            return [
                'enabled' => $enabled,
                'apiKey' => SystemSetting::get('firebase_api_key', ''),
                'authDomain' => SystemSetting::get('firebase_auth_domain', ''),
                'projectId' => SystemSetting::get('firebase_project_id', ''),
                'appId' => SystemSetting::get('firebase_app_id', ''),
            ];
        } catch (\Throwable) {
            return ['enabled' => false, 'apiKey' => '', 'authDomain' => '', 'projectId' => '', 'appId' => ''];
        }
    }

    private function oneSignalPublicConfig(): array
    {
        try {
            $appId = config('services.onesignal.app_id', '');

            return [
                'app_id' => $appId,
                'enabled' => filled($appId),
            ];
        } catch (\Throwable) {
            return ['app_id' => '', 'enabled' => false];
        }
    }

    private function pusherPublicConfig(): array
    {
        try {
            $key = SystemSetting::get('pusher_app_key') ?: env('PUSHER_APP_KEY', '');
            $cluster = SystemSetting::get('pusher_app_cluster') ?: env('PUSHER_APP_CLUSTER', 'mt1');
            $dbFlag = SystemSetting::get('pusher_enabled');

            // If the admin panel has explicitly disabled Pusher, respect that.
            // Otherwise (setting absent/null) treat a non-empty key as enabled,
            // so the .env credentials work out-of-the-box without a DB toggle.
            $enabled = $dbFlag === 'false' ? false : ! empty($key);

            return [
                'key' => $key,
                'cluster' => $cluster,
                'enabled' => $enabled,
            ];
        } catch (\Throwable) {
            $key = env('PUSHER_APP_KEY', '');

            return ['key' => $key, 'cluster' => env('PUSHER_APP_CLUSTER', 'mt1'), 'enabled' => ! empty($key)];
        }
    }

    private function brandingShare(): array
    {
        try {
            $logoPath = SystemSetting::get('app_logo_path');
            $faviconPath = SystemSetting::get('app_favicon_path');

            return [
                'app_name' => SystemSetting::get('app_name') ?: config('saas.app_name', config('app.name')),
                'app_tagline' => SystemSetting::get('app_tagline') ?: config('saas.tagline'),
                'support_email' => SystemSetting::get('support_email') ?: config('saas.support_email'),
                'docs_url' => SystemSetting::get('docs_url') ?: config('saas.docs_url'),
                'primary_color' => SystemSetting::get('primary_color') ?: config('saas.branding.primary_color', '#237A57'),
                'secondary_color' => SystemSetting::get('secondary_color') ?: config('saas.branding.secondary_color', '#113B2A'),
                'font_family' => SystemSetting::get('font_family') ?: config('saas.branding.font_family', 'plus-jakarta-sans'),
                'logo_url' => $logoPath ? $this->assetUrl($logoPath, SystemSetting::get('app_logo_disk', 'public')) : null,
                'favicon_url' => $faviconPath ? $this->assetUrl($faviconPath, SystemSetting::get('app_favicon_disk', 'public')) : null,
            ];
        } catch (\Throwable) {
            return [
                'app_name' => config('saas.app_name', config('app.name')),
                'app_tagline' => config('saas.tagline'),
                'support_email' => config('saas.support_email'),
                'docs_url' => config('saas.docs_url'),
                'primary_color' => config('saas.branding.primary_color', '#237A57'),
                'secondary_color' => config('saas.branding.secondary_color', '#113B2A'),
                'font_family' => config('saas.branding.font_family', 'plus-jakarta-sans'),
                'logo_url' => null,
                'favicon_url' => null,
            ];
        }
    }

    private function workspaceUsage(?int $workspaceId, mixed $plan): array
    {
        if (! $workspaceId) {
            return [];
        }

        $limits = $plan?->limits ?? [];
        if (empty($limits)) {
            return [];
        }

        $metricsMap = [
            'campaigns_per_month' => 'campaigns',
            'whatsapp_messages_per_month' => 'whatsapp_messages',
            'social_posts_per_month' => 'social_posts',
        ];

        $usage = [];
        foreach ($limits as $limitKey => $limit) {
            if ($limit === null) {
                continue;
            }
            $meterKey = $metricsMap[$limitKey] ?? $limitKey;
            $current = UsageMeter::current($workspaceId, $meterKey);
            $usage[$limitKey] = [
                'current' => $current,
                'limit' => $limit,
                'percent' => $limit > 0 ? min(100, round(($current / $limit) * 100)) : 0,
            ];
        }

        return $usage;
    }

    private function appShare(Request $request): array
    {
        $locale = app()->getLocale();
        $isRtl = (bool) $request->attributes->get('is_rtl', false);
        $dir = $isRtl ? 'rtl' : 'ltr';

        $i18nLocales = [];
        try {
            $list = Locale::forSwitcher();
            foreach ($list as $loc) {
                $i18nLocales[] = [
                    'code' => $loc->code,
                    'name' => $loc->name,
                    'native_name' => $loc->native_name ?? $loc->name,
                    'is_rtl' => (bool) $loc->is_rtl,
                    'flag' => $loc->flag,
                ];
            }
        } catch (\Throwable $e) {
            $i18nLocales = [
                ['code' => 'en', 'name' => 'English', 'native_name' => 'English', 'is_rtl' => false, 'flag' => null],
            ];
        }

        $user = $request->user();
        $adminUser = $request->user('admin');
        $isAdminRoute = $request->routeIs('admin.*') && ! $request->routeIs('admin.login');

        $displayCurrency = $user?->display_currency
            ?? ($user?->workspace?->currency_code ?? null)
            ?? $request->session()->get('display_currency')
            ?? Currency::defaultCode()
            ?? 'USD';

        $currencies = Currency::where('enabled', true)
            ->orderByRaw('is_default DESC')
            ->orderBy('code')
            ->get(['code', 'symbol', 'decimals', 'exchange_rate'])
            ->map(fn ($c) => ['code' => $c->code, 'symbol' => $c->symbol, 'decimals' => $c->decimals, 'exchange_rate' => (float) $c->exchange_rate]);

        $currentWorkspace = null;
        $workspacesForSwitcher = [];
        $workspaceId = null;
        $plan = null;
        // Only real client users have workspaces. On admin routes the default guard
        // is `admin`, so $request->user() is an AdminUser — calling the workspace
        // helpers (isAccessibleBy/accessibleWorkspaces, type-hinted to User) with it
        // throws a TypeError, which crashes share() and drops the page to the
        // fallback props (no translations, empty permissions → blank admin panel).
        // This only surfaced when the session carried a leftover current_workspace_id.
        if ($user instanceof User) {
            $workspaceId = $request->session()->get('current_workspace_id') ?? $user->workspace_id;
            if ($workspaceId) {
                $workspace = Workspace::with('client')->find($workspaceId);
                if ($workspace && $workspace->isAccessibleBy($user)) {
                    $currentWorkspace = ['id' => $workspace->id, 'name' => $workspace->name];
                    $plan = $workspace->client?->activePlan();
                }
            }
            try {
                $workspacesForSwitcher = $user->accessibleWorkspaces()->map(fn ($w) => ['id' => $w->id, 'name' => $w->name])->values()->all();
            } catch (\Throwable $e) {
                $workspacesForSwitcher = [];
            }
        }

        $auth = [
            'user' => $user,
            'adminUser' => null,
            'permissions' => [],
        ];
        if ($isAdminRoute && $adminUser) {
            $auth['adminUser'] = [
                'id' => $adminUser->id,
                'name' => $adminUser->name,
                'email' => $adminUser->email,
                'status' => $adminUser->status,
            ];
            $auth['permissions'] = $adminUser->permissionKeys();
        }

        $impersonation = null;
        if ($user && $request->session()->get('impersonating') && $request->session()->get('impersonated_client_id')) {
            $client = Client::find($request->session()->get('impersonated_client_id'));
            $impersonation = [
                'active' => true,
                'clientName' => $client?->name ?? 'Unknown',
                'returnUrl' => route('admin.impersonation.stop'),
            ];
        }

        $supportedLocalesMap = [];

        foreach ($i18nLocales as $loc) {
            $supportedLocalesMap[$loc['code']] = $loc['native_name'];
        }
        if (empty($supportedLocalesMap)) {
            $supportedLocalesMap = ['en' => 'English'];
        }

        $unreadNotificationsCount = $user ? $user->unreadNotifications()->count() : 0;

        // The badge beside Inbox in the rail: how many conversations are still
        // open. Only on client screens with a resolved workspace — an admin route
        // has no workspace and must not pay for this.
        //
        // Measured at 0.11 ms for a workspace with 80 open conversations and 0.13 ms
        // for one with 400, on a table holding 394k rows across 100 workspaces: the
        // existing (workspace_id, status) index answers it straight from the index.
        // The number of CLIENTS does not enter into it — only how many conversations
        // this one leaves unresolved.
        $inboxOpenCount = 0;
        if ($workspaceId && ! $isAdminRoute) {
            $inboxOpenCount = Conversation::where('workspace_id', $workspaceId)
                ->whereIn('status', Conversation::ACTIVE_STATUSES)
                ->count();
        }

        // The badge beside Oferte: how many drafts the agent prepared and nobody
        // has decided on yet. Same guard as the inbox count — a workspace has to
        // be resolved and the route must not be an admin one, so the admin panel
        // never pays for a client-panel badge.
        //
        // source = 'ai' AND status = 'draft' is the whole definition of "waiting
        // for a person": a human's own draft is not waiting on anybody, and an AI
        // draft that was sent, accepted or refused has been dealt with.
        //
        // Measured on a table of 400k offers across 97 workspaces: 0.20 ms for a
        // workspace holding 24 AI drafts among 166 drafts, 0.20 ms for 30 among
        // 206, and 0.60 ms for 120 among 825. The same harness measured 0.11 ms
        // for a count against an empty table, which is the round-trip floor and
        // matches what the inbox count above was measured at.
        //
        // The cost tracks the number of DRAFTS in the workspace, not the number
        // of AI ones: (workspace_id, status) gets us to the drafts, and `source`
        // is not on that index, so every draft row is read to test it. 825 open
        // drafts is already far past anything a firm of under 30 people holds; if
        // one ever does, the fix is a third column on the index, not a cache.
        $offerAiDraftsCount = 0;
        if ($workspaceId && ! $isAdminRoute) {
            $offerAiDraftsCount = Offer::where('workspace_id', $workspaceId)
                ->where('status', 'draft')
                ->where('source', 'ai')
                ->count();
        }

        $onboardingSummary = null;
        if ($user && ! $isAdminRoute && ($request->routeIs('client.*') || $request->routeIs('reports.exports.*'))) {
            try {
                $progress = app(OnboardingService::class)->getProgress($user);
                $onboardingSummary = [
                    'done' => $progress['done'],
                    'total' => $progress['total'],
                    'percent' => $progress['percent'],
                    'is_complete' => $progress['is_complete'],
                ];
            } catch (\Throwable) {
                $onboardingSummary = null;
            }
        }

        return [
            // Current CSRF token, re-shared on every Inertia response so the SPA can
            // keep its axios header + <meta> tag in sync. Without this a long-lived
            // page keeps the boot-time token, which goes stale when the session token
            // rotates (e.g. on impersonation) and causes 419s until a hard reload.
            'csrf_token' => csrf_token(),
            'flash' => [
                'success' => $request->session()->get('success'),
                'error' => $request->session()->get('error'),
                'openEditPlanId' => $request->session()->get('openEditPlanId'),
                'upgrade_required' => $request->session()->get('upgrade_required'),
                'upgrade_reason' => $request->session()->get('upgrade_reason'),
            ],
            'auth' => $auth,
            'unreadNotificationsCount' => $unreadNotificationsCount,
            'inboxOpenCount' => $inboxOpenCount,
            'offerAiDraftsCount' => $offerAiDraftsCount,
            'impersonation' => $impersonation,
            'theme' => $user?->theme ?? 'light',
            'timezone' => $user?->timezone ?? 'UTC',
            'currentWorkspace' => $currentWorkspace,
            'workspaces' => $workspacesForSwitcher,
            'locale' => $locale,
            'dir' => $dir,
            'i18n' => [
                'locale' => $locale,
                'isRtl' => $isRtl,
                'locales' => $i18nLocales,
                'translations' => app(I18nFileService::class)->getFlatDictionary($locale),
            ],
            'supportedLocales' => $supportedLocalesMap,
            'rtlLocales' => array_values(array_column(array_filter($i18nLocales, fn ($l) => ! empty($l['is_rtl'])), 'code')) ?: ['ar'],
            'currencies' => $currencies,
            'displayCurrency' => $displayCurrency,
            'demo_mode' => config('app.demo_mode', false),
            'current_workspace_usage' => $this->workspaceUsage($workspaceId ?? null, $plan ?? null),
            'app_version' => env('APP_VERSION', '1.0.0'),
            'onboardingSummary' => $onboardingSummary,
            'subscription' => $this->subscriptionShare($user),
            'landingPageEnabled' => SystemSetting::get('landing.page_enabled', '1') === '1',
            'branding' => $this->brandingShare(),
            'pusher' => $this->pusherPublicConfig(),
            'onesignal' => $this->oneSignalPublicConfig(),
            'firebase' => $this->firebasePublicConfig(),
            'metaAppId' => $this->metaAppId(),
        ];
    }

    /**
     * Entitlement summary for the expiry banner. Only a client user can be past
     * due, so an admin, a guest or a user with no client always reads 'active'
     * and the banner never appears where it makes no sense. State and dates
     * only — nothing here identifies anyone.
     *
     * @return array{state: string, days_left: int|null, grace_ends_at: string|null, reason: string|null}
     */
    private function subscriptionShare(mixed $user): array
    {
        if (! $user instanceof User || ! $user->client_id) {
            return ['state' => Entitlement::ACTIVE, 'days_left' => null, 'grace_ends_at' => null, 'reason' => null];
        }

        // Client::find rather than the $user->client relation: the relation is
        // typed as a bare Model, and Entitlement must be handed a real Client.
        $client = Client::find($user->client_id);

        return [
            'state' => Entitlement::state($client),
            'days_left' => Entitlement::daysLeft($client),
            'grace_ends_at' => Entitlement::graceEndsAt($client)?->toIso8601String(),
            // 'trial' or 'subscription': the banner must not tell a firm that
            // never paid a leu that its subscription has ended.
            'reason' => Entitlement::reason($client),
        ];
    }

    private function metaAppId(): ?string
    {
        try {
            return CredentialResolver::system()->meta()?->appId() ?: null;
        } catch (\Throwable) {
            return null;
        }
    }

    private function assetUrl(string $path, string $disk): string
    {
        app(StorageManager::class)->ensureDiskReady($disk);

        return Storage::disk($disk)->url($path);
    }
}
