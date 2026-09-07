<?php

namespace App\Http\Middleware;

use App\Models\Locale;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SetLocale
{
    /**
     * Set application locale from: 1) user preference, 2) tenant default, 3) session/cookie (guests), 4) default locale from DB.
     * Validate against enabled locales only.
     */
    public function handle(Request $request, Closure $next): Response
    {
        try {
            $enabledCodes = Locale::enabled()->pluck('code')->all();
            $defaultCode = Locale::defaultCode();
        } catch (\Throwable $e) {
            $enabledCodes = ['en'];
            $defaultCode = 'en';
        }
        if (empty($enabledCodes)) {
            $enabledCodes = ['en'];
        }
        if (! in_array($defaultCode, $enabledCodes, true)) {
            $defaultCode = $enabledCodes[0] ?? 'en';
        }

        $locale = $defaultCode;

        // Track whether a higher-priority source has answered, rather than testing
        // `$locale === $defaultCode`. That comparison silently fails whenever a
        // user's own preference happens to equal the platform default: with both
        // set to 'ro' the guard stayed true, so a stale session value from the
        // login page (where the visitor is still a guest) overrode the account
        // setting and the UI kept reverting to English.
        $resolved = false;

        // 1) Authenticated user preference (web guard = client User)
        $user = $request->user();
        if ($user && ! empty($user->locale) && in_array($user->locale, $enabledCodes, true)) {
            $locale = $user->locale;
            $resolved = true;
        }

        // 2) Workspace default locale (when no user locale and user has workspace)
        if (! $resolved && $user?->workspace_id) {
            $workspace = $user->workspace;
            if ($workspace && $workspace->default_locale && in_array($workspace->default_locale, $enabledCodes, true)) {
                $locale = $workspace->default_locale;
                $resolved = true;
            }
        }

        // 3) Guest/session preference (and admin panel: admin has no locale on model, use session)
        if (! $resolved && $request->hasSession()) {
            $sessionLocale = $request->session()->get('locale');
            if ($sessionLocale && in_array($sessionLocale, $enabledCodes, true)) {
                $locale = $sessionLocale;
                $resolved = true;
            }
        }

        // 4) Cookie for guests (no session)
        if (! $resolved && ! $request->hasSession() && $request->cookie('locale')) {
            $cookieLocale = $request->cookie('locale');
            if (in_array($cookieLocale, $enabledCodes, true)) {
                $locale = $cookieLocale;
            }
        }

        // Resolve the RTL flag defensively — the locales table may be
        // unavailable (e.g. before the database is configured during the
        // first-run install), and this must not crash the install wizard.
        try {
            $localeModel = Locale::where('code', $locale)->first();
            $isRtl = $localeModel && $localeModel->is_rtl;
        } catch (\Throwable $e) {
            $isRtl = false;
        }

        app()->setLocale($locale);
        $request->attributes->set('active_locale', $locale);
        $request->attributes->set('is_rtl', $isRtl);

        $response = $next($request);

        // Optional: set cookie for guests so next visit remembers
        if (! $request->user() && $request->hasSession()) {
            // Session is enough; cookie can be set when they switch language
        }

        return $response;
    }
}
