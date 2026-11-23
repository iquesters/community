<?php

use Illuminate\Support\Facades\Route;
use Iquesters\Community\Http\Controllers\MemberImportController;

Route::middleware('web')->group(function () {
    // Admin only. (Organisation admins come with the tenant policy in a later step.)
    Route::middleware(['auth', 'ensure.admin'])
        ->prefix('community/members/import')
        ->name('community.members.import.')
        ->group(function () {
            Route::get('/', [MemberImportController::class, 'index'])->name('index');
            Route::get('/template', [MemberImportController::class, 'template'])->name('template');
            Route::post('/preview', [MemberImportController::class, 'preview'])->name('preview');
            Route::post('/{token}', [MemberImportController::class, 'store'])
                ->where('token', '[0-9A-HJKMNP-TV-Za-hjkmnp-tv-z]{26}')
                ->name('store');
        });
});
