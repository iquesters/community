<?php

use Illuminate\Support\Facades\Route;
use Iquesters\Community\Http\Controllers\ApplicationAdminController;
use Iquesters\Community\Http\Controllers\ApplicationController;
use Iquesters\Community\Http\Controllers\ApplicationProofController;
use Iquesters\Community\Http\Controllers\MemberImportController;

$ulid = '[0-9A-HJKMNP-TV-Za-hjkmnp-tv-z]{26}';

Route::middleware('web')->group(function () use ($ulid) {
    // Any signed-in user: apply for membership and follow the application.
    Route::middleware('auth')->prefix('community')->group(function () use ($ulid) {
        Route::get('/apply', [ApplicationController::class, 'show'])->name('community.apply.show');
        Route::post('/apply', [ApplicationController::class, 'store'])->name('community.apply.store');
        // The applicant or a platform admin (checked in the controller).
        Route::get('/proofs/{uid}', [ApplicationProofController::class, 'download'])
            ->where('uid', $ulid)
            ->name('community.proofs.download');
    });

    // Admin only. (Organisation admins come with the tenant policy in a later step.)
    Route::middleware(['auth', 'ensure.admin'])->prefix('community')->group(function () use ($ulid) {
        Route::prefix('members/import')->name('community.members.import.')->group(function () use ($ulid) {
            Route::get('/', [MemberImportController::class, 'index'])->name('index');
            Route::get('/template', [MemberImportController::class, 'template'])->name('template');
            Route::post('/preview', [MemberImportController::class, 'preview'])->name('preview');
            Route::post('/{token}', [MemberImportController::class, 'store'])->where('token', $ulid)->name('store');
        });

        Route::prefix('applications')->name('community.applications.')->group(function () use ($ulid) {
            Route::get('/', [ApplicationAdminController::class, 'index'])->name('index');
            Route::get('/{uid}', [ApplicationAdminController::class, 'show'])->where('uid', $ulid)->name('show');
            Route::post('/{uid}/decision', [ApplicationAdminController::class, 'decide'])->where('uid', $ulid)->name('decide');
        });
    });
});
