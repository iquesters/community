<?php

/*
|--------------------------------------------------------------------------
| Community API v1  →  /api/community/v1/...
|--------------------------------------------------------------------------
| Loaded by foundation's ModuleApi (declared in composer.json extra.platform.api),
| which adds the /api/community/v1 prefix, the api middleware, the rate limit
| and the "api.community.v1." name prefix.
*/

use Illuminate\Support\Facades\Route;
use Iquesters\Community\Http\Controllers\Api\MemberImportApiController;

Route::middleware(['auth:sanctum', 'ensure.admin'])->group(function () {
    Route::get('directory/fields', [MemberImportApiController::class, 'fields'])->name('directory.fields');
    Route::get('organisations', [MemberImportApiController::class, 'organisations'])->name('organisations');

    Route::prefix('member-records/import')->name('member-records.import.')->group(function () {
        Route::post('preview', [MemberImportApiController::class, 'preview'])->name('preview');
        Route::post('{token}', [MemberImportApiController::class, 'import'])
            ->where('token', '[0-9A-HJKMNP-TV-Za-hjkmnp-tv-z]{26}')
            ->name('store');
    });
});
