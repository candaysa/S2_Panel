<?php

use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes (JSON)
|--------------------------------------------------------------------------
|
| Module routes are registered by their own ServiceProviders under
| app/Modules/*. Group them with the "steam.auth" middleware unless the
| endpoint is explicitly public (login/callback/install).
|
*/

Route::prefix('api')->middleware('steam.auth')->group(function (): void {
    // Not a module route: the dashboard reads across several modules and has
    // to render when any of them is off. See DashboardController.
    Route::get('dashboard', [App\Http\Controllers\DashboardController::class, 'index'])->name('dashboard.summary');
});