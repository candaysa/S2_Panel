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

Route::get('/', fn () => redirect()->route('dashboard'));

// Public on purpose - no admin exists yet when this runs. InstallLock keeps
// it reachable only while the panel isn't installed (see InstallController).
Route::view('/install', 'install.index')->name('install.page');

// PWA manifest. Served by a route rather than a static file because the
// name, icon and theme colour are all owner-configurable in Settings - a
// checked-in manifest.json would show "S2 Panel" on someone else's install.
Route::get('/manifest.webmanifest', function () {
    $settings = app(\App\Modules\Settings\App\Services\SettingService::class);
    $name = $settings->get('site_name') ?: 'S2 Panel';
    $icon = $settings->get('logo') ? asset($settings->get('logo')) : asset('images/logo.png');

    return response()->json([
        'name' => $name,
        'short_name' => \Illuminate\Support\Str::limit($name, 12, ''),
        'description' => 'Admin panel for Counter-Strike 2 servers.',
        'start_url' => '/dashboard',
        'scope' => '/',
        'display' => 'standalone',
        'orientation' => 'any',
        'background_color' => '#0c0c0e',
        'theme_color' => $settings->get('brand_color') ?: '#00ffe3',
        'icons' => [
            ['src' => $icon, 'sizes' => '192x192', 'type' => 'image/png', 'purpose' => 'any'],
            ['src' => $icon, 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'any'],
            ['src' => $icon, 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'maskable'],
        ],
    ])->header('Content-Type', 'application/manifest+json');
})->name('pwa.manifest');

Route::get('/login', function (Request $request) {
    if (Auth::check()) {
        return redirect()->route('dashboard');
    }

    return view('auth.login');
})->name('login');

// Public read-only pages, reachable without a Steam session - a visitor
// should be able to see server status, aggregate stats and the leaderboard
// before deciding to log in. Each view fetches its data from an API route
// that is public for exactly the same reason (see the matching module's
// Routes/api.php); nothing mutable lives behind these.
Route::view('/dashboard', 'dashboard')->name('dashboard');
Route::view('/servers', 'servers.index')->name('servers.page');
Route::view('/stats', 'stats.index')->name('stats.page');
Route::view('/ranks', 'ranks.index')->name('ranks.page');

// Public player profile. The SteamID is only passed through to the page so
// its Alpine component can fetch /api/ranks/{steam}; that endpoint does the
// real validation and 404s on anything malformed, so nothing here trusts it.
Route::get('/players/{steam}', fn (string $steam) => view('players.show', ['steam' => $steam]))
    ->where('steam', '[A-Za-z0-9:_\-\[\]]{1,64}')
    ->name('players.show');

Route::middleware('steam.auth')->group(function (): void {
    // Open to any logged-in session - no flag required. Their APIs enforce
    // the exact same rule (see Vip/Skin/Report/Appeal Routes/api.php); the
    // sidebar hides these for guests but never for a plain logged-in player.
    Route::view('/vip', 'vip.index')->name('vip.page');
    Route::view('/skins', 'skins.index')->name('skins.page');
    Route::view('/reports', 'reports.index')->name('reports.page');
    Route::view('/appeals', 'appeals.index')->name('appeals.page');

    // Staff pages - each one's API additionally requires a specific flag
    // (see the matching Routes/api.php); the page shell itself only needs a
    // session so a signed-in non-staff user gets the API's 403 rather than a
    // login redirect they've already passed.
    Route::view('/admins', 'admin.index')->name('admins.page');
    Route::view('/groups', 'admin.groups')->name('groups.page');
    Route::view('/bans', 'bans.index')->name('bans.page');
    Route::view('/rcon', 'rcon.index')->name('rcon.page');
    Route::view('/audit', 'audit.index')->name('audit.page');
    Route::view('/cheat-check', 'cheatcheck.index')->name('cheatcheck.page');

    Route::middleware('owner.only')->group(function (): void {
        Route::view('/health', 'health.index')->name('health.page');
        Route::view('/webhooks', 'webhooks.index')->name('webhooks.page');
        Route::view('/modules', 'modules.index')->name('modules.page');
        Route::view('/plugins', 'plugins.index')->name('plugins.page');
        Route::view('/settings', 'settings.index')->name('settings.page');
    });
});
