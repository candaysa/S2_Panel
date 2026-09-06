<?php

use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes (JSON)
|--------------------------------------------------------------------------
|
| withRouting(api: ...) in bootstrap/app.php already prefixes every route in
| this file with "api/" and applies the "api" middleware group - an extra
| Route::prefix('api') here would double it to "api/api/...". Module routes
| are registered separately by their own ServiceProviders under
| app/Modules/*, which is why this file only ever holds the one cross-module
| route below; add "steam.auth" (or a flag) to it explicitly unless the
| endpoint is meant to be public.
|
*/

// Public: a visitor should see server status and activity before deciding
// to log in. Not a module route either way - the dashboard reads across
// several modules and has to render when any of them is off. See
// DashboardController, which gates its own sensitive sections (ban/mute
// detail) behind the same flag their module's API requires.
Route::get('dashboard', [App\Http\Controllers\DashboardController::class, 'index'])->name('dashboard.summary');