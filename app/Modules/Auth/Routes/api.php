<?php

use App\Modules\Auth\App\Http\Controllers\AuthController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Auth module API routes
|--------------------------------------------------------------------------
|
| redirect + callback are public (Steam hosts the browser flow);
| logout + me require an authenticated session.
|
*/

Route::prefix('api/auth')->middleware('throttle:auth')->group(function (): void {
    Route::get('redirect', [AuthController::class, 'redirect'])->name('auth.redirect');
    Route::match(['get', 'post'], 'callback', [AuthController::class, 'callback'])->name('auth.callback');

    Route::middleware('steam.auth')->group(function (): void {
        Route::post('logout', [AuthController::class, 'logout'])->name('auth.logout');
        Route::get('me', [AuthController::class, 'me'])->name('auth.me');
    });
});