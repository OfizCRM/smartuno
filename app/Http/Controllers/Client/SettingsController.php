<?php

namespace App\Http\Controllers\Client;

use App\Http\Controllers\Controller;
use App\Models\ClientSetting;
use App\Models\Currency;
use App\Models\Locale;
use App\Models\User;
use App\Models\Workspace;
use App\Modules\Broadcasting\Models\SmsProviderConfig;
use App\Modules\Broadcasting\Models\WorkspaceSmtpConfig;
use App\Modules\Ecommerce\Models\EcommerceStore;
use App\Modules\Shared\Models\ChannelAccount;
use App\Modules\Social\Models\SocialAccount;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class SettingsController extends Controller
{
    /**
     * The settings hub: a list of rows, each linking to a page at its own route.
     *
     * Everything a firm configures once lives behind here rather than in the
     * sidebar. The counts below drive the status chips on each row, which is what
     * lets someone answer "have I set this up?" without opening the page — the
     * main thing a hub loses against a flat menu, bought back cheaply.
     */
    public function index(Request $request): Response
    {
        $user = $request->user();
        $workspaceId = (int) $user->workspace_id;
        $isAdmin = $user->client_id && $user->isClientAdministrator();

        return Inertia::render('client/Settings/Hub', [
            'isAdmin' => (bool) $isAdmin,
            'status' => [
                'team' => $isAdmin ? User::where('client_id', $user->client_id)->count() : 0,
                'workspaces' => $user->client_id ? Workspace::where('client_id', $user->client_id)->count() : 0,
                'channels' => ChannelAccount::where('workspace_id', $workspaceId)->count(),
                'social' => SocialAccount::where('workspace_id', $workspaceId)->where('active', true)->count(),
                'stores' => EcommerceStore::where('workspace_id', $workspaceId)->count(),
                'sms' => SmsProviderConfig::where('workspace_id', $workspaceId)->exists(),
                'email' => WorkspaceSmtpConfig::where('workspace_id', $workspaceId)->where('is_active', true)->exists(),
            ],
        ]);
    }

    /** Personal preferences: language, currency, theme, timezone. */
    public function preferences(Request $request): Response
    {
        $user = $request->user();
        $supportedLocales = Locale::enabled()->orderByRaw('is_default DESC')->orderBy('sort_order')->get(['code', 'name']);
        if ($supportedLocales->isEmpty()) {
            $supportedLocales = collect([['code' => 'en', 'name' => 'English']]);
        }
        $supportedCurrencies = Currency::where('enabled', true)->orderBy('code')->get(['code', 'symbol']);

        return Inertia::render('client/Settings/Preferences', [
            'preferences' => [
                'locale' => $user->locale ?? config('app.locale', 'en'),
                'display_currency' => $user->display_currency ?? 'RON',
                'theme' => $user->theme ?? 'light',
                'timezone' => $user->timezone ?? 'Europe/Bucharest',
            ],
            'supportedLocales' => $supportedLocales->map(fn ($l) => ['code' => $l->code, 'name' => $l->name]),
            'supportedCurrencies' => $supportedCurrencies->map(fn ($c) => ['code' => $c->code, 'name' => $c->code, 'symbol' => $c->symbol ?? $c->code]),
        ]);
    }

    /** The organisation's own details. Administrators only, like TeamController. */
    public function company(Request $request): Response
    {
        $user = $request->user();
        abort_unless($user->client_id && $user->isClientAdministrator(), 403);

        $c = $user->client;
        abort_unless((bool) $c, 404);

        return Inertia::render('client/Settings/Company', [
            'client' => [
                'id' => $c->id,
                'name' => $c->name,
                'email' => $c->email,
                'phone' => $c->phone,
                'address' => $c->address,
            ],
        ]);
    }

    public function notifications(Request $request): Response
    {
        $user = $request->user();

        $preferences = $user->notificationPreferences
            ->groupBy('event')
            ->map(fn ($group) => $group->mapWithKeys(fn ($p) => [$p->channel => (bool) $p->enabled]));

        return Inertia::render('client/Settings/Notifications', [
            'preferences' => $preferences,
            // The weekly digest lives here rather than on the preferences form:
            // every email switch in one place is what a user expects, and it lets
            // the digest email itself deep-link to the switch that turns it off.
            'digestEnabled' => $user->client_id
                ? ClientSetting::get($user->client_id, 'weekly_digest_enabled', '1') !== '0'
                : true,
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $user = $request->user();
        $localeCodes = Locale::enabled()->pluck('code')->all() ?: ['en'];
        $currencyCodes = Currency::where('enabled', true)->pluck('code')->all() ?: ['USD'];

        $validated = $request->validate([
            'locale' => ['nullable', 'string', 'max:16', Rule::in($localeCodes)],
            'display_currency' => ['nullable', 'string', 'max:10', Rule::in($currencyCodes)],
            'theme' => ['nullable', 'string', 'in:light,dark'],
            'timezone' => ['nullable', 'string', 'max:64', 'timezone:all'],
            'client_name' => ['nullable', 'string', 'max:255'],
            'client_email' => ['nullable', 'email', 'max:255'],
            'client_phone' => ['nullable', 'string', 'max:64'],
            'client_address' => ['nullable', 'string'],
            'weekly_digest_enabled' => ['nullable', 'boolean'],
        ]);

        if (array_key_exists('locale', $validated) && $validated['locale'] !== null) {
            $user->locale = $validated['locale'];
        }
        if (array_key_exists('display_currency', $validated) && $validated['display_currency'] !== null) {
            $user->display_currency = $validated['display_currency'];
        }
        if (array_key_exists('theme', $validated) && $validated['theme'] !== null) {
            $user->theme = $validated['theme'];
        }
        if (array_key_exists('timezone', $validated) && $validated['timezone'] !== null) {
            $user->timezone = $validated['timezone'];
        }
        $user->save();

        if ($user->client_id && $user->isClientAdministrator() && $user->client) {
            $client = $user->client;
            if (array_key_exists('client_name', $validated)) {
                $client->name = $validated['client_name'] ?? $client->name;
            }
            if (array_key_exists('client_email', $validated)) {
                $client->email = $validated['client_email'];
            }
            if (array_key_exists('client_phone', $validated)) {
                $client->phone = $validated['client_phone'];
            }
            if (array_key_exists('client_address', $validated)) {
                $client->address = $validated['client_address'];
            }
            $client->save();
        }

        if ($user->client_id && array_key_exists('weekly_digest_enabled', $validated)) {
            ClientSetting::set($user->client_id, 'weekly_digest_enabled', $validated['weekly_digest_enabled'] ? '1' : '0');
        }

        // back(), not a fixed route: this action now serves three separate pages
        // (preferences, company, notifications) and a save should return you to
        // the one you were on rather than bouncing you to the hub.
        return back()->with('success', __('Settings saved.'));
    }
}
