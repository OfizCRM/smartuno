<?php

namespace App\Http\Middleware;

use App\Models\Client;
use App\Models\User;
use App\Support\Entitlement;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * When a client's subscription or trial has been expired for longer than the
 * grace period (App\Support\Entitlement), put the client area into read-only:
 * every write (POST, PUT, PATCH, DELETE) is refused, nothing is deleted, and the
 * customer keeps full sight of their inbox, contacts, reports and history.
 *
 * Reads (GET/HEAD/OPTIONS) always pass — being behind on an invoice must never
 * cost anyone access to their own data. During 'active' and 'grace' everything
 * passes; only 'readonly' blocks.
 *
 * The allowlist below is the safety-critical part: without it a blocked customer
 * cannot pay their way out of the block, cannot ask for help, and cannot leave.
 * Every entry is a route a read-only client must still be able to reach.
 */
class EnforceSubscriptionAccess
{
    /**
     * Route names that must keep working for a read-only client.
     *
     * Grouped by the reason they are here: pay, get help, exercise a legal right,
     * secure or leave the account, and pure UI/session housekeeping that writes no
     * business data. Names verified against `php artisan route:list`.
     */
    private const ALLOWED_ROUTE_NAMES = [
        // Pay. Blocking these would make the read-only state a dead end.
        'client.checkout.store',
        'client.coupon.check',
        'client.subscription.change-plan',
        'client.subscription.destroy',

        // Ask us why. A support ticket is the cheapest possible resolution path.
        'client.support.store',
        'client.support.reply',

        // A GDPR right does not depend on an unpaid invoice. Access (the export)
        // and erasure (deleting the account) are the same class of right, so
        // blocking one while allowing the other cannot be justified — and a
        // customer who wants to leave must not need us to let them.
        'client.settings.data-export.store',
        'client.profile.destroy',

        // Securing or leaving the account is never a "change" we should block.
        // The two-factor routes especially: someone whose account is being
        // targeted must be able to turn 2FA on, rotate leaked recovery codes, or
        // turn it off after losing their authenticator, whatever their invoice
        // says. All three run through the client-app group and so carry this gate.
        'logout',
        'admin.logout',
        'password.update',
        'password.confirm',
        'verification.send',
        'client.profile.sessions.destroy',
        'client.profile.2fa.enable',
        'client.profile.2fa.disable',
        'client.profile.2fa.recovery-codes',
        // So an admin can get back out of an impersonated read-only client.
        'admin.impersonation.stop',

        // Session/UI housekeeping: these write no business data, and blocking
        // them only produces stuck cards and console errors.
        'client.workspaces.switch',
        'client.notifications.read',
        'client.notifications.read-all',
        'client.onboarding.complete',
        'client.push.subscribe',
        'client.push.unsubscribe',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        if (! in_array($request->method(), ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
            return $next($request);
        }

        $user = $request->user();

        if (! $user instanceof User || ! $user->client_id) {
            return $next($request);
        }

        // Client::find rather than the $user->client relation: the relation is
        // typed as a bare Model, and Entitlement must be handed a real Client.
        if (Entitlement::state(Client::find($user->client_id)) !== Entitlement::READONLY) {
            return $next($request);
        }

        $name = $request->route()?->getName() ?? '';

        if (in_array($name, self::ALLOWED_ROUTE_NAMES, true)) {
            return $next($request);
        }

        // Laravel registers the password-confirmation submit (POST confirm-password)
        // without a route name, so it is matched by path.
        if ($request->path() === 'confirm-password') {
            return $next($request);
        }

        $message = __('Your subscription has ended. You can still see everything, but you cannot send messages or make changes. Reactivate it from Pricing.');

        // 402 Payment Required, never 403: an integration must be able to tell
        // "you are not allowed to do this" from "this account has not paid". The
        // `code` is the stable contract.
        //
        // Inertia is included, exactly as EnsureNotDemoMode does it. A redirect
        // back with a flash reaches nobody: Inertia reads a 302 as success, so
        // useForm closes the modal and clears the form, and only 19 of the 146
        // client pages render flash.error at all — the busiest ones (Contacts,
        // Campaigns) render flash.success only. The 402 instead trips Inertia's
        // cancelable `invalid` event, which app.jsx turns into a toast on every
        // page, the same way demo mode has always worked.
        if ($request->expectsJson() || $request->header('X-Inertia')) {
            return response()->json([
                'message' => $message,
                'code' => 'subscription_readonly',
            ], 402);
        }

        // Web: back where they were, with an explanation in the toast. A bare 403
        // page with no reason is exactly the support call we are trying to avoid.
        return redirect()->back()->with('error', $message);
    }
}
