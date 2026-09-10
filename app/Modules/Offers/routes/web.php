<?php

use App\Modules\Offers\Http\Controllers\OfferController;
use App\Modules\Offers\Http\Controllers\PublicOfferController;
use Illuminate\Support\Facades\Route;

/*
 * The offer as the customer sees it — the one route in this module, and the
 * first in the product, that answers somebody with no account.
 *
 * It is deliberately OUTSIDE the group below, and deliberately not in the `web`
 * group either. `web` would give it a session cookie, CSRF plumbing and
 * HandleInertiaRequests, which shares the whole User model — none of which a
 * guest needs and the last of which is exactly what must not reach one. The
 * controller sets every response header itself, its own CSP included.
 *
 * `throttle` stands before `signed` so a flood of guesses is refused before any
 * HMAC is computed. `signed:relative` is the relative form on purpose: an
 * absolute signature covers the host, and this application is reached on a host
 * it did not generate from in some deployments. The uuid is inside the signed
 * path, so one offer's link cannot open another's.
 *
 * `where` pins the segment to a uuid: there is no numeric id in this URL to
 * increment, and nothing here lists, searches or steps to a neighbouring offer.
 */
// Throttled on the offer in the path, not on the caller's IP: TRUSTED_PROXIES
// defaults to '*', so $request->ip() is attacker-supplied and a rotating
// X-Forwarded-For walked straight through an IP-keyed limiter — measured at
// 100 requests, 100 answered. The uuid cannot be rotated without a fresh
// signature, so this bounds the volume against one link, which is the thing
// worth bounding.
Route::middleware(['throttle:offer-link', 'signed:relative'])
    ->get('oferta/{offer}', [PublicOfferController::class, 'show'])
    ->where('offer', '[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}')
    ->name('offers.public');

Route::middleware(['web', 'client-app'])->prefix('app/offers')->name('client.offers.')->group(function () {
    Route::get('/', [OfferController::class, 'index'])->name('index');
    Route::post('/', [OfferController::class, 'store'])->name('store');

    // Both settings routes stand before /{offer} on purpose: the wildcard binds
    // any single segment, so declared after it, "settings" would be looked up as
    // an offer uuid and answered 404.
    Route::get('/settings', [OfferController::class, 'settings'])->name('settings');
    Route::put('/settings', [OfferController::class, 'saveSettings'])->name('settings.save');

    Route::get('/{offer}', [OfferController::class, 'show'])->name('show');
    Route::put('/{offer}', [OfferController::class, 'update'])->name('update');
    Route::delete('/{offer}', [OfferController::class, 'destroy'])->name('destroy');

    // The offer owns its PDF. The Document row the renderer files is never
    // reachable through the document library's own route from here — one
    // permission check, on the offer.
    Route::get('/{offer}/pdf', [OfferController::class, 'pdf'])->name('pdf');
    Route::post('/{offer}/decision', [OfferController::class, 'decision'])->name('decision');

    // Sending is a POST of its own rather than a status field on update(): it
    // has a side effect the customer sees, and it must not be reachable by
    // accident from a form that also edits prices.
    Route::post('/{offer}/send', [OfferController::class, 'send'])->name('send');

    // The two halves of correcting a draft the agent wrote. Separate on purpose:
    // saving the corrected reading must be free and instant, and rebuilding from
    // it spends a provider call — a single endpoint doing both would bill the
    // firm for every keystroke in the "ce a înțeles agentul" box.
    //
    // Both are writes, so both sit behind EnforceSubscriptionAccess with the
    // rest of the group: a lapsed client cannot spend the platform's LLM key.
    Route::put('/{offer}/interpretation', [OfferController::class, 'interpretation'])->name('interpretation');
    Route::post('/{offer}/regenerate', [OfferController::class, 'regenerate'])->name('regenerate');
});
