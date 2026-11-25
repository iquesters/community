<?php

use Illuminate\Support\Facades\Route;
use Iquesters\Community\Http\Controllers\ApplicationAdminController;
use Iquesters\Community\Http\Controllers\ApplicationController;
use Iquesters\Community\Http\Controllers\ApplicationProofController;
use Iquesters\Community\Http\Controllers\MemberDetailsController;
use Iquesters\Community\Http\Controllers\MemberImportController;
use Iquesters\Community\Http\Controllers\SchoolRecordController;

$ulid = '[0-9A-HJKMNP-TV-Za-hjkmnp-tv-z]{26}';

Route::middleware('web')->group(function () use ($ulid) {
    // Any signed-in user: apply for membership and follow the application.
    Route::middleware('auth')->prefix('community')->group(function () use ($ulid) {
        // "Membership": apply and follow the application; the member's home once approved.
        Route::get('/membership', [ApplicationController::class, 'show'])->name('community.membership');
        // Members only (others are sent to Membership).
        Route::get('/directory', [ApplicationController::class, 'directoryPage'])->name('community.directory');
        Route::get('/directory/data', [ApplicationController::class, 'directoryData'])->name('community.directory.data');
        // Multiple organisations: the choice form on Membership.
        Route::get('/membership/organisations', [ApplicationController::class, 'organisationOptions'])->name('community.membership.organisations');
        Route::post('/membership/organisation', [ApplicationController::class, 'chooseOrganisation'])->name('community.membership.organisation');
        // The application form (form schema) posts here.
        Route::post('/membership', [ApplicationController::class, 'store'])->name('community.membership.apply');
        // A member edits their own details (their approved membership; checked in the controller).
        Route::get('/membership/details/{uid}', [MemberDetailsController::class, 'edit'])->where('uid', $ulid)->name('community.membership.details');
        Route::put('/membership/details/{uid}', [MemberDetailsController::class, 'update'])->where('uid', $ulid)->name('community.membership.details.update');
        // Old address of the apply page.
        Route::get('/apply', [ApplicationController::class, 'legacy'])->name('community.apply.show');
        // The applicant or a platform admin (checked in the controller).
        Route::get('/proofs/{uid}', [ApplicationProofController::class, 'download'])
            ->where('uid', $ulid)
            ->name('community.proofs.download');
    });

    // Super-admins and organisation admins; each controller limits them to their organisations.
    Route::middleware(['auth', 'ensure.community.manager'])->prefix('community')->group(function () use ($ulid) {
        Route::prefix('members/import')->name('community.members.import.')->group(function () use ($ulid) {
            Route::get('/', [MemberImportController::class, 'index'])->name('index');
            Route::get('/template', [MemberImportController::class, 'template'])->name('template');
            Route::get('/organisations', [MemberImportController::class, 'organisations'])->name('organisations');
            Route::get('/{token}', [MemberImportController::class, 'show'])->where('token', $ulid)->name('show');
            Route::post('/preview', [MemberImportController::class, 'preview'])->name('preview');
            Route::post('/{token}', [MemberImportController::class, 'store'])->where('token', $ulid)->name('store');
        });

        // A member's school records (Members table record section and its edit page).
        Route::prefix('members/{uid}')->where(['uid' => $ulid])->name('community.members.school.')->group(function () {
            Route::get('/school', [SchoolRecordController::class, 'section'])->name('section');
            Route::get('/school/edit', [SchoolRecordController::class, 'edit'])->name('edit');
            Route::put('/school-records', [SchoolRecordController::class, 'update'])->name('update');
        });

        // An organisation's school levels (ICSE, ISC…).
        Route::get('/school-levels', [SchoolRecordController::class, 'levelsIndex'])->name('community.school-levels.index');
        Route::get('/school-levels/{uid}', [SchoolRecordController::class, 'levelsEdit'])->where('uid', $ulid)->name('community.school-levels.edit');
        Route::put('/school-levels/{uid}', [SchoolRecordController::class, 'levelsUpdate'])->where('uid', $ulid)->name('community.school-levels.update');

        Route::prefix('applications')->name('community.applications.')->group(function () use ($ulid) {
            Route::get('/', [ApplicationAdminController::class, 'index'])->name('index');
            // The generic table's rows (data_url) and records, and the record's HTML sections.
            Route::get('/data', [ApplicationAdminController::class, 'data'])->name('data');
            Route::get('/data/{uid}', [ApplicationAdminController::class, 'record'])->where('uid', $ulid)->name('record');
            Route::get('/{uid}/proofs', [ApplicationAdminController::class, 'proofs'])->where('uid', $ulid)->name('proofs');
            Route::get('/{uid}/history', [ApplicationAdminController::class, 'history'])->where('uid', $ulid)->name('history');
            Route::get('/{uid}', [ApplicationAdminController::class, 'show'])->where('uid', $ulid)->name('show');
            Route::post('/{uid}/decision', [ApplicationAdminController::class, 'decide'])->where('uid', $ulid)->name('decide');
            // Options of the decision form's "Matching records".
            Route::get('/{uid}/matches', [ApplicationAdminController::class, 'matches'])->where('uid', $ulid)->name('matches');
        });
    });
});
