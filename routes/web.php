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

// Pages whose feature is owner-toggleable carry module:<key> alongside
// whatever flag gate they already had. A module's API routes vanish when it
// is switched off, but these Blade pages are registered by the app itself
// and used to outlive it - the page rendered and every fetch behind it 404'd.
// See App\Http\Middleware\RequireModule.
//
// Public read-only pages, reachable without a Steam session - a visitor
// should be able to see server status, aggregate stats and the leaderboard
// before deciding to log in. Each view fetches its data from an API route
// that is public for exactly the same reason (see the matching module's
// Routes/api.php); nothing mutable lives behind these.
Route::view('/dashboard', 'dashboard')->name('dashboard');
Route::view('/ranks', 'ranks.index')->middleware('module:rank')->name('ranks.page');

// Drill-down from the dashboard's server list - same public-read rule as
// everything else in this block, since it shows nothing the list itself
// does not already (map, population, IP), just with history added.
Route::get('/servers/{id}', fn (string $id) => view('server-details.show', ['serverId' => $id]))
    ->where('id', '[0-9]+')
    ->middleware('module:server_details')
    ->name('server-details.page');

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
    Route::view('/vip', 'vip.index')->middleware('module:vip')->name('vip.page');

    // Own SteamID64 resolved server-side once here - the page has no
    // "look up another player" mode, so it never needs one from the client.
    Route::get('/skins', fn () => view('skins.index', [
        'ownSteamId' => \App\Support\SteamId::parse((string) auth()->user()->steam_id)->steamId64(),
    ]))->middleware('module:skin')->name('skins.page');

    // Reports, admin applications, and ban appeals share one page (a
    // category dropdown switches between them); canDecide is resolved
    // server-side once here rather than re-derived per fetch client-side.
    Route::get('/tickets', fn () => view('tickets.index', [
        'canDecide' => \App\Support\TicketAccess::canDecide(auth()->user()),
    ]))->middleware('module:report,appeal')->name('tickets.page');

    // Bans is read-only here (nothing is created or lifted from this page)
    // and every player is already named on it, so there is no extra
    // exposure in letting any logged-in player look their own record up -
    // same "auth" tier as vip/skins/tickets above, not staff-gated.
    Route::view('/bans', 'bans.index')->name('bans.page');

    // Staff pages. The page itself now carries the same flag its API
    // requires, so a signed-in player cannot open an RCON console or an
    // admin list at all - previously the shell rendered for anyone with a
    // session and only the data was withheld, which showed players the
    // shape of tools they have no business seeing. Each gate mirrors the
    // matching Routes/api.php exactly; the sidebar hides these for the
    // same set.
    Route::get('/admins', fn () => view('admin.index', [
        'adminPlugin' => app(\App\Modules\Settings\App\Services\SettingService::class)->get('admin_plugin', 'cs2_admin'),
    ]))->middleware('flag:admin.root')->name('admins.page');
    Route::view('/groups', 'admin.groups')->middleware('flag:admin.root')->name('groups.page');
    Route::view('/rcon', 'rcon.index')->middleware(['module:rcon', 'flag:admin.rcon'])->name('rcon.page');
    Route::view('/audit', 'audit.index')->middleware(['module:audit', 'flag:admin.root'])->name('audit.page');
    Route::view('/cheat-check', 'cheatcheck.index')->middleware(['module:cheat_check', 'flag:admin.generic'])->name('cheatcheck.page');

    Route::middleware('owner.only')->group(function (): void {
        Route::view('/webhooks', 'webhooks.index')->name('webhooks.page');
        Route::view('/modules', 'modules.index')->name('modules.page');
        Route::view('/settings', 'settings.index')->name('settings.page');
        Route::view('/settings/design', 'settings.design')->name('settings.design.page');
        Route::view('/settings/tickets', 'settings.tickets')->name('settings.tickets.page');
        Route::view('/settings/servers', 'settings.servers')->name('settings.servers.page');
    });
});
