<?php

use App\Modules\Updater\App\Http\Controllers\UpdateController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Updater module API routes
|--------------------------------------------------------------------------
|
| Owner-only, without exception: install replaces the panel's own code, so
| it is the single most privileged thing the panel can do. Rate-limited
| separately from the general API because each install pulls a bundle from
| GitHub and rewrites the install directory.
|
*/

Route::prefix('api/update')->middleware(['steam.auth', 'owner.only'])->group(function (): void {
    Route::get('status', [UpdateController::class, 'status'])->name('update.status');

    // One limiter shared by all three (Laravel keys throttle:N,M on the user,
    // not the route): a single update is install + finalise, and a failed one
    // plus a retry plus a roll-back must not lock the owner out mid-recovery.
    Route::middleware('throttle:10,10')->group(function (): void {
        Route::post('install', [UpdateController::class, 'install'])->name('update.install');
        Route::post('finalise', [UpdateController::class, 'finalise'])->name('update.finalise');
        Route::post('rollback', [UpdateController::class, 'rollback'])->name('update.rollback');
    });
});
