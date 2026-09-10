<?php

use App\Modules\Catalog\Http\Controllers\CatalogAiController;
use App\Modules\Catalog\Http\Controllers\CatalogImportController;
use App\Modules\Catalog\Http\Controllers\CatalogItemController;
use Illuminate\Support\Facades\Route;

Route::middleware(['web', 'client-app'])->prefix('app/catalog')->name('client.catalog.')->group(function () {
    Route::get('/', [CatalogItemController::class, 'index'])->name('index');
    Route::post('/', [CatalogItemController::class, 'store'])->name('store');

    // Both of these stand before /{item} on purpose: the wildcard binds any
    // single segment, so declared after it, "search" and "import" would be
    // looked up as uuids and answered 404.
    Route::get('/search', [CatalogItemController::class, 'search'])->name('search');
    Route::post('/import', [CatalogImportController::class, 'store'])->name('import');

    // The only two routes in this module that spend money. Same limit key as
    // the chatbot playground, so a plan that caps AI tokens caps both — today
    // no plan sets that key, EnforceLimit reads a null limit as unlimited, and
    // nobody is blocked.
    Route::post('/ai/describe', [CatalogAiController::class, 'describe'])
        ->middleware('limit:ai_tokens_per_month,ai_tokens')
        ->name('ai.describe');
    Route::post('/ai/apply', [CatalogAiController::class, 'apply'])->name('ai.apply');

    Route::get('/{item}', [CatalogItemController::class, 'show'])->name('show');
    Route::put('/{item}', [CatalogItemController::class, 'update'])->name('update');
    Route::delete('/{item}', [CatalogItemController::class, 'destroy'])->name('destroy');

    // Two segments, so these do not compete with /{item} and their order against
    // it does not matter. What a bundle is made of, read by the offer editor's
    // "adaugă ansamblu"; and everything the agent is told about one item, which
    // is a separate save from the name-and-price form above it.
    Route::get('/{item}/components', [CatalogItemController::class, 'components'])->name('components');
    Route::put('/{item}/knowledge', [CatalogItemController::class, 'knowledge'])->name('knowledge');
});
