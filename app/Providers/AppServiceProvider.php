<?php

namespace App\Providers;

use App\Events\AutomationFailed;
use App\Events\AutomationWebhookReceived;
use App\Events\CampaignCompleted;
use App\Events\CommerceEventReceived;
use App\Events\ContactCreated;
use App\Events\ConversationAssigned;
use App\Events\MessageReceived;
use App\Events\PlanChanged;
use App\Events\SubscriptionCancelled;
use App\Events\SubscriptionExpired;
use App\Events\SubscriptionRenewed;
use App\Events\SubscriptionStarted;
use App\Events\TrialEnding;
use App\Listeners\AutomationTriggerListener;
use App\Listeners\AutoReplyListener;
use App\Listeners\DispatchOutboundWebhookListener;
use App\Listeners\LogSuccessfulLogin;
use App\Listeners\SendAutomationFailedNotification;
use App\Listeners\SendCampaignCompletedNotification;
use App\Listeners\SendConversationAssignedNotification;
use App\Listeners\SendNewMessageNotification;
use App\Listeners\SendPlanChangedNotification;
use App\Listeners\SendSubscriptionCancelledNotification;
use App\Listeners\SendSubscriptionExpiredNotification;
use App\Listeners\SendSubscriptionRenewedNotification;
use App\Listeners\SendSubscriptionStartedNotification;
use App\Listeners\SendTrialEndingNotification;
use App\Listeners\SendWelcomeNotification;
use App\Models\Workspace;
use App\Modules\Offers\Listeners\DraftOfferFromMessageListener;
use App\Modules\Shared\Services\ChannelManager;
use App\Services\Billing\BillingGatewayRegistry;
use App\Services\StorageManager;
use App\Support\Entitlement;
use Dedoc\Scramble\Scramble;
use Dedoc\Scramble\Support\Generator\OpenApi;
use Dedoc\Scramble\Support\Generator\SecurityScheme;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Registered;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Facades\Vite;
use Illuminate\Support\ServiceProvider;
use Symfony\Component\Mailer\Bridge\Brevo\Transport\BrevoApiTransport;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // On a fresh deploy the database (and its sessions/cache/queue tables)
        // does not exist yet, which would otherwise break the installer's own
        // session + CSRF. Until the app is installed, fall back to filesystem
        // drivers so the setup wizard works against an empty database.
        if (! config('app.installed')) {
            config([
                'session.driver' => 'file',
                'cache.default' => 'array',
                'queue.default' => 'sync',
            ]);
        }

        $this->app->singleton(BillingGatewayRegistry::class, fn () => new BillingGatewayRegistry);
        $this->app->singleton(StorageManager::class);
        $this->app->singleton(ChannelManager::class, fn () => new ChannelManager);
    }

    public function boot(): void
    {
        $this->registerBrevoApiTransport();

        // The public offer link. Keyed on the offer in the path rather than on
        // the caller's IP: TRUSTED_PROXIES defaults to '*', so the IP is
        // attacker-supplied and a rotating X-Forwarded-For defeated an
        // IP-keyed limiter entirely.
        RateLimiter::for('offer-link', fn (Request $request) => Limit::perMinute(30)
            ->by((string) $request->route('offer')));
        $this->configureHttpClientSsl();
        $this->forceHttpsForWebhookUrls();

        Gate::define('viewAdmin', fn ($user) => $user?->isAdmin());
        Gate::define('manageAdminSensitive', fn ($user) => $user?->isAdmin());

        // A web request lives milliseconds and concerns one client, so
        // Entitlement's memo is safe there. A queue worker lives for hours and
        // serves every client on the box: without this, the first verdict it
        // computed would be reused until the process was restarted, and a
        // customer who paid at 09:00 would keep getting no campaigns, no posts
        // and no auto-replies. A job boundary is the point at which the answer
        // can legitimately have changed, so that is where the memo is dropped.
        Queue::before(fn () => Entitlement::forget());

        // Listener auto-discovery is disabled in bootstrap/app.php, so this block is
        // the only place listeners are registered. Each one must appear exactly once;
        // tests/Feature/Automation/AutomationProductionReadinessTest guards that.
        Event::listen(Login::class, LogSuccessfulLogin::class);
        Event::listen(Registered::class, SendWelcomeNotification::class);

        // FIRST, and the order is the whole point. The dispatcher has no
        // try/catch around listeners — it calls them in registration order and
        // the first one to throw stops the rest — and neither
        // AutomationTriggerListener::handleMessageReceived nor
        // SendNewMessageNotification has a top-level catch of its own. A listener
        // registered last is therefore the one most likely to be silently skipped
        // when an earlier one throws. Registered first, with its entire body in
        // try/catch, this one can neither be skipped by an upstream failure nor
        // cause one downstream: its catch protects the three below it.
        Event::listen(MessageReceived::class, [DraftOfferFromMessageListener::class, 'handle']);

        Event::listen(MessageReceived::class, [AutomationTriggerListener::class, 'handleMessageReceived']);
        Event::listen(MessageReceived::class, [AutoReplyListener::class, 'handle']);
        Event::listen(ContactCreated::class, [AutomationTriggerListener::class, 'handleContactCreated']);
        Event::listen(AutomationWebhookReceived::class, [AutomationTriggerListener::class, 'handleAutomationWebhookReceived']);
        Event::listen(CommerceEventReceived::class, [AutomationTriggerListener::class, 'handleCommerceEvent']);

        // ── Outbound webhook event delivery ─────────────────────────────────
        Event::listen(ContactCreated::class, [DispatchOutboundWebhookListener::class, 'handleContactCreated']);
        Event::listen(MessageReceived::class, [DispatchOutboundWebhookListener::class, 'handleMessageReceived']);
        Event::listen(CampaignCompleted::class, [DispatchOutboundWebhookListener::class, 'handleCampaignCompleted']);

        // ── Notification bridging listeners ──────────────────────────────────
        Event::listen(MessageReceived::class, SendNewMessageNotification::class);
        Event::listen(CampaignCompleted::class, SendCampaignCompletedNotification::class);
        Event::listen(AutomationFailed::class, SendAutomationFailedNotification::class);
        Event::listen(ConversationAssigned::class, SendConversationAssignedNotification::class);

        // ── Subscription & billing notifications ────────────────────────────
        Event::listen(SubscriptionStarted::class, SendSubscriptionStartedNotification::class);
        Event::listen(SubscriptionCancelled::class, SendSubscriptionCancelledNotification::class);
        Event::listen(SubscriptionRenewed::class, SendSubscriptionRenewedNotification::class);
        Event::listen(SubscriptionExpired::class, SendSubscriptionExpiredNotification::class);
        Event::listen(PlanChanged::class, SendPlanChangedNotification::class);
        Event::listen(TrialEnding::class, SendTrialEndingNotification::class);

        // ── Named rate limiters ─────────────────────────────────────────────
        RateLimiter::for('api', function (Request $request) {
            return Limit::perMinute(60)
                ->by(optional($request->user())->id ?: $request->ip());
        });

        RateLimiter::for('webhooks', function (Request $request) {
            // Use the real client IP (respects X-Forwarded-For when trusted proxies are set).
            // Limit is intentionally high: a single Meta app services multiple workspaces and
            // all their traffic arrives from a small pool of Meta egress IPs.
            return Limit::perMinute(1000)->by($request->getClientIp());
        });

        RateLimiter::for('ai-runs', function (Request $request) {
            $workspaceId = $request->user()?->current_workspace_id ?? $request->ip();
            $workspace = $workspaceId ? Workspace::with('client.activePlan')->find($workspaceId) : null;
            $perMinute = $workspace?->client?->activePlan?->limits['ai_runs_per_minute'] ?? 10;

            return Limit::perMinute((int) $perMinute)->by((string) $workspaceId);
        });

        // ── Scramble / OpenAPI ──────────────────────────────────────────────
        Scramble::configure()
            ->withDocumentTransformers(function (OpenApi $openApi) {
                $openApi->secure(
                    SecurityScheme::http('bearer')
                );
            });

        // Only include /api/v1/* routes in the spec
        Scramble::routes(function (Route $route) {
            return str_starts_with($route->uri(), 'api/v1');
        });

        Vite::prefetch(concurrency: 3);
    }

    /**
     * Point Guzzle at a valid CA bundle when php.ini references a missing file
     * (Windows cURL error 77), or optionally disable verify for local dev only.
     */
    /**
     * Teach Laravel to send through Brevo's HTTPS API.
     *
     * Laravel ships transports for SES, Postmark and Resend, and none for Brevo,
     * so the Symfony bridge is registered by hand. The bridge itself is the
     * official one — writing an HTTP client for this would be a second
     * implementation of somebody else's API contract, and the first one to drift
     * when Brevo changes it.
     *
     * Why an API transport exists here at all: most container hosts block
     * outbound SMTP to stop their platform being used for spam, and Railway —
     * where this runs — is one of them. The block does not refuse the
     * connection, it swallows it, so a send hangs for a minute and then times
     * out. Port 443 is never blocked.
     *
     * The key comes from the mailer config rather than from env(), because it is
     * stored per-installation in the database and set from the admin screen.
     * MailService::configureMailer() is what puts it there.
     */
    private function registerBrevoApiTransport(): void
    {
        Mail::extend('brevo_api', function (array $config) {
            // Only the key. The optional HTTP client, event dispatcher and
            // logger are Symfony's own contracts — Laravel's Dispatcher is a
            // different interface and passing it throws a TypeError the moment
            // a mail is sent, which is a long way from here.
            return new BrevoApiTransport((string) ($config['key'] ?? ''));
        });
    }

    /** Meta webhook callbacks must use HTTPS in production. */
    private function forceHttpsForWebhookUrls(): void
    {
        $appUrl = config('app.url');
        if (
            app()->environment('production')
            && is_string($appUrl)
            && str_starts_with($appUrl, 'https://')
        ) {
            URL::forceScheme('https');
        }
    }

    private function configureHttpClientSsl(): void
    {
        $caPath = config('http.ca_path');

        if (is_string($caPath) && $caPath !== '' && is_file($caPath)) {
            Http::globalOptions(['verify' => $caPath]);

            return;
        }

        // Laragon / Windows PHP builds: cacert.pem next to php.exe (php.ini may still point elsewhere).
        if (defined('PHP_BINARY') && is_string(PHP_BINARY) && PHP_BINARY !== '') {
            $phpDirBundle = dirname(PHP_BINARY).DIRECTORY_SEPARATOR.'extras'
                .DIRECTORY_SEPARATOR.'ssl'.DIRECTORY_SEPARATOR.'cacert.pem';
            if (is_file($phpDirBundle)) {
                Http::globalOptions(['verify' => $phpDirBundle]);

                return;
            }
        }

        if (config('http.verify_ssl') === false) {
            Http::globalOptions(['verify' => false]);
        }
    }
}
