<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Web routes (Blade + Alpine pages)
|--------------------------------------------------------------------------
|
| These render the app shell; the pages themselves fetch their data from
| the JSON API (routes/api.php + app/Modules/*) via Alpine's fetch(), which
| carries the same session cookie automatically (same-origin request).
| InstallLock and SecurityHeaders already apply through the "web" group
| (see bootstrap/app.php).
|
*/

Route::get('/', function () {
    return redirect()->to(Auth::check() ? route('dashboard') : route('login'));
});

// Public on purpose - no admin exists yet when this runs. InstallLock keeps
// it reachable only while the panel isn't installed (see InstallController).
Route::view('/install', 'install.index')->name('install.page');

Route::get('/login', function (Request $request) {
    if (Auth::check()) {
        return redirect()->route('dashboard');
    }

    return view('auth.login');
})->name('login');

Route::middleware('steam.auth')->group(function (): void {
    Route::view('/dashboard', 'dashboard')->name('dashboard');
    Route::view('/admins', 'admin.index')->name('admins.page');
    Route::view('/groups', 'admin.groups')->name('groups.page');
    Route::view('/servers', 'servers.index')->name('servers.page');
    Route::view('/bans', 'bans.index')->name('bans.page');
    Route::view('/reports', 'reports.index')->name('reports.page');
    Route::view('/vip', 'vip.index')->name('vip.page');
    Route::view('/ranks', 'ranks.index')->name('ranks.page');
    Route::view('/skins', 'skins.index')->name('skins.page');
    Route::view('/rcon', 'rcon.index')->name('rcon.page');
    Route::view('/audit', 'audit.index')->name('audit.page');
    Route::view('/stats', 'stats.index')->name('stats.page');
    Route::view('/appeals', 'appeals.index')->name('appeals.page');

    Route::middleware('owner.only')->group(function (): void {
        Route::view('/health', 'health.index')->name('health.page');
        Route::view('/webhooks', 'webhooks.index')->name('webhooks.page');
        Route::view('/modules', 'modules.index')->name('modules.page');
        Route::view('/plugins', 'plugins.index')->name('plugins.page');
        Route::view('/settings', 'settings.index')->name('settings.page');
    });
});
