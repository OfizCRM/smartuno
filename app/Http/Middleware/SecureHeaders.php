<?php

namespace App\Http\Middleware;

use App\Modules\Integrations\Services\CredentialResolver;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SecureHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $response->headers->set('X-Frame-Options', 'SAMEORIGIN');
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('X-XSS-Protection', '1; mode=block');
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $response->headers->set('Permissions-Policy', 'geolocation=(), microphone=(), camera=()');

        // Prevent the browser from caching authenticated pages. Without this, the
        // back button after logout restores a cached/bfcache copy of the dashboard,
        // making it look as if the session is still active. `no-store` forces a
        // server round-trip on back/forward, which redirects to login once logged out.
        if (auth()->guard('web')->check() || auth()->guard('admin')->check()) {
            $response->headers->set('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0');
            $response->headers->set('Pragma', 'no-cache');
            $response->headers->set('Expires', '0');
        }

        // A response that already set its own policy keeps it. The site-wide one
        // is written for the application's own pages and permits inline script;
        // a route that serves a file from outside — an email attachment shown in
        // the page — sets something far stricter on purpose, and overwriting it
        // here would quietly hand a stranger's document the looser rules.
        $csp = $this->buildCsp();
        if ($csp !== null && ! $response->headers->has('Content-Security-Policy')) {
            $response->headers->set('Content-Security-Policy', $csp);
        }

        return $response;
    }

    private function buildCsp(): ?string
    {
        $unsafeEval = config('app.env') !== 'production' ? " 'unsafe-eval'" : '';
        // The document editor is served from its own origin and needs to appear
        // in nearly every directive: it loads a script, injects styles and
        // fonts, draws itself inside an iframe, and talks back over websockets.
        $office = $this->officeSource();
        $scriptSrc = "'self' 'unsafe-inline'".$unsafeEval.$this->viteDevSources().$office.$this->thirdPartyScriptSources();
        $styleSrc = "'self' 'unsafe-inline' https://fonts.bunny.net https://fonts.googleapis.com".$this->viteDevSources().$office.$this->thirdPartyStyleSources();
        $fontSrc = "'self' data: https://fonts.bunny.net https://fonts.gstatic.com https://fonts.googleapis.com".$office;

        $frameSrc = "'self'".$office.$this->metaFrameSources();

        // No array_filter: every entry below is a non-empty string, so it only
        // ever returned the array it was given.
        $directives = ["default-src 'self'",
            'script-src '.$scriptSrc,
            'script-src-elem '.$scriptSrc,
            'style-src '.$styleSrc,
            'style-src-elem '.$styleSrc,
            // The editor serves its own icons, and on a developer machine it does
            // so over plain http, which `https:` alone would block.
            "img-src 'self' data: https: blob:".$this->officeSource(),
            'font-src '.$fontSrc,
            "connect-src 'self' ".$this->connectSources().$this->officeSource().$this->officeWebsocket(),
            'frame-src '.$frameSrc,
            "frame-ancestors 'self'",
        ];

        return implode('; ', $directives);
    }

    /**
     * Allow the Vite dev server in development so CSP does not block scripts.
     *
     * The port is read from public/hot, which Vite writes on start and Laravel
     * already trusts to know where the assets come from. It used to be hard
     * coded to 5173, so the day anything else held that port — another worktree,
     * a second project — Vite fell back to 5174, every script was blocked, and
     * the application went blank with nothing but a CSP error to go on.
     */
    private function viteDevSources(): string
    {
        if (config('app.env') === 'production') {
            return '';
        }

        $port = 5173;
        $hot = public_path('hot');

        if (is_file($hot)) {
            $found = parse_url(trim((string) file_get_contents($hot)), PHP_URL_PORT);
            $port = is_int($found) ? $found : $port;
        }

        // Not http://[::1]:port — invalid in script-src for Chromium, and noisy.
        return " http://localhost:{$port} http://127.0.0.1:{$port}";
    }

    /**
     * The document editor's origin, or nothing when it is not configured.
     *
     * Both host spellings are listed: the browser may be pointed at localhost
     * while the configured value says 127.0.0.1, and CSP matches on the literal
     * origin rather than resolving it.
     */
    private function officeSource(): string
    {
        $url = trim((string) config('services.onlyoffice.url'));

        if ($url === '') {
            return '';
        }

        $parts = parse_url($url);
        $scheme = $parts['scheme'] ?? 'http';
        $host = $parts['host'] ?? '';
        $port = isset($parts['port']) ? ':'.$parts['port'] : '';

        if ($host === '') {
            return '';
        }

        $origins = [$scheme.'://'.$host.$port];

        foreach (['localhost' => '127.0.0.1', '127.0.0.1' => 'localhost'] as $from => $to) {
            if ($host === $from) {
                $origins[] = $scheme.'://'.$to.$port;
            }
        }

        return ' '.implode(' ', $origins);
    }

    /** The editor keeps a websocket open to its own origin. */
    private function officeWebsocket(): string
    {
        $sources = $this->officeSource();

        return $sources === '' ? '' : str_replace(['http://', 'https://'], ['ws://', 'wss://'], $sources);
    }

    /** OneSignal (when configured), Meta JS SDK, and Cloudflare Web Analytics / beacon scripts. */
    private function thirdPartyScriptSources(): string
    {
        $extra = ' https://static.cloudflareinsights.com';
        if (filled(config('services.onesignal.app_id'))) {
            // SDK loads from cdn; runtime sync/scripts also come from api.* (see OneSignal v16 CSP docs).
            $extra .= ' https://cdn.onesignal.com https://*.onesignal.com';
        }
        if ($this->metaSdkEnabled()) {
            $extra .= ' https://connect.facebook.net';
        }

        return $extra;
    }

    /** OneSignal injects styles from the apex host (e.g. OneSignalSDK.page.styles.css). */
    private function thirdPartyStyleSources(): string
    {
        if (! filled(config('services.onesignal.app_id'))) {
            return '';
        }

        return ' https://onesignal.com https://*.onesignal.com';
    }

    private function connectSources(): string
    {
        $sources = [];
        if (config('app.env') !== 'production') {
            $sources[] = 'ws:';
            $sources[] = 'wss:';
        }
        $url = config('app.url');
        if ($url) {
            $sources[] = parse_url($url, PHP_URL_HOST) ?: $url;
        }
        if (filled(config('services.onesignal.app_id'))) {
            $sources[] = 'https://onesignal.com';
            $sources[] = 'https://*.onesignal.com';
        }
        if ($this->metaSdkEnabled()) {
            $sources[] = 'https://graph.facebook.com';
            $sources[] = 'https://www.facebook.com';
            $sources[] = 'https://web.facebook.com';
            $sources[] = 'https://business.facebook.com';
            // FB JS SDK fetches /app_config/json/{appId}/ from here during init. Without it the
            // Embedded Signup dialog config never loads and Meta never posts WA_EMBEDDED_SIGNUP.
            $sources[] = 'https://connect.facebook.net';
        }

        return implode(' ', array_unique($sources));
    }

    /** Allow Meta Login / Embedded Signup dialogs in iframes when the Meta App is configured. */
    private function metaFrameSources(): string
    {
        if (! $this->metaSdkEnabled()) {
            return '';
        }

        return ' https://www.facebook.com https://web.facebook.com https://business.facebook.com https://connect.facebook.net';
    }

    private function metaSdkEnabled(): bool
    {
        try {
            return filled(CredentialResolver::system()->meta()?->appId());
        } catch (\Throwable) {
            return false;
        }
    }
}
