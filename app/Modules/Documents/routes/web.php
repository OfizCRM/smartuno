<?php

use App\Modules\Documents\Http\Controllers\DocumentController;
use App\Modules\Documents\Http\Controllers\FolderController;
use App\Modules\Documents\Http\Controllers\KnowledgeLinkController;
use App\Modules\Documents\Http\Controllers\OfficeController;
use App\Modules\Documents\Http\Controllers\TemplateController;
use App\Modules\Documents\Http\Controllers\VersionController;
use Illuminate\Support\Facades\Route;

Route::middleware(['web', 'client-app'])->prefix('app/documents')->name('client.documents.')->group(function () {
    Route::get('/', [DocumentController::class, 'index'])->name('index');
    Route::post('/', [DocumentController::class, 'store'])->name('store');
    Route::get('/list', [DocumentController::class, 'list'])->name('list');
    Route::post('/office', [OfficeController::class, 'create'])->name('office.create');
    Route::get('/{document}/office', [OfficeController::class, 'show'])->name('office');
    Route::post('/from-message', [DocumentController::class, 'fromMessage'])->name('from-message');
    Route::get('/{document}/file', [DocumentController::class, 'file'])->name('file');
    Route::patch('/{document}', [DocumentController::class, 'update'])->name('update');
    Route::delete('/{document}', [DocumentController::class, 'destroy'])->name('destroy');
    Route::post('/{document}/versions', [VersionController::class, 'store'])->name('versions.store');
    Route::get('/{document}/versions/{version}', [VersionController::class, 'show'])->name('versions.show');
    Route::post('/{document}/knowledge-base', [KnowledgeLinkController::class, 'store'])->name('kb.store');
    Route::delete('/{document}/knowledge-base', [KnowledgeLinkController::class, 'destroy'])->name('kb.destroy');

    Route::post('/{document}/template', [TemplateController::class, 'store'])->name('templates.store');
    Route::delete('/templates/{template}', [TemplateController::class, 'destroy'])->name('templates.destroy');

    Route::post('/folders', [FolderController::class, 'store'])->name('folders.store');
    Route::patch('/folders/{folder}', [FolderController::class, 'update'])->name('folders.update');
    Route::delete('/folders/{folder}', [FolderController::class, 'destroy'])->name('folders.destroy');
});

/*
 * The Document Server's own way in. Outside the session group on purpose: it
 * arrives with no cookie, and a signature stands in for the workspace check
 * that a person's request gets.
 */
Route::prefix('documents/office')->name('documents.office.')->group(function () {
    Route::get('/{document}/download', [OfficeController::class, 'download'])->name('download');
    // No session and no CSRF token on purpose: this is a container calling in.
    // The signed URL and the JWT do the work a cookie would.
    Route::post('/{document}/callback', [OfficeController::class, 'callback'])->name('callback');
});
