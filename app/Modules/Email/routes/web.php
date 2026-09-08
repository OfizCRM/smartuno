<?php

use App\Modules\Email\Http\Controllers\AttachmentController;
use App\Modules\Email\Http\Controllers\MailboxController;
use Illuminate\Support\Facades\Route;

Route::middleware(['web', 'client-app'])->prefix('app/settings/mailbox')->name('client.mailbox.')->group(function () {
    Route::get('/', [MailboxController::class, 'index'])->name('index');
    Route::post('/', [MailboxController::class, 'store'])->name('store');
    Route::post('/test', [MailboxController::class, 'test'])->name('test');
    Route::delete('/', [MailboxController::class, 'destroy'])->name('destroy');
});

Route::middleware(['web', 'client-app'])->prefix('app/inbox')->name('client.email.')->group(function () {
    // Streamed through the app, never a public storage URL — see the controller.
    Route::get('/conversations/{conversation}/messages/{message}/attachments/{index}', [AttachmentController::class, 'show'])
        ->whereNumber('index')
        ->name('attachment');
});
