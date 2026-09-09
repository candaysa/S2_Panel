<?php

use App\Modules\Rcon\App\Http\Controllers\RconController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Rcon module API routes (C11)
|--------------------------------------------------------------------------
|
| Passwords are stored encrypted in the panel database (rcon_settings) –
| the Swiftly admin_servers table is never modified. Every endpoint
| requires the admin.rcon flag; the owner always passes via RequireFlag.
|
*/

Route::prefix('api/rcon')->middleware('steam.auth')->group(function (): void {
    // Deliberately NOT behind rcon.verified: this is the only place a
    // password can be fixed, and Settings > Servers (the page that calls
    // these) is the same - gating the fix path would deadlock the panel
    // against itself.
    Route::get('settings', [RconController::class, 'listSettings'])
        ->middleware('flag:admin.rcon')
        ->name('rcon.settings.index');
    Route::post('settings', [RconController::class, 'saveSettings'])
        ->middleware('flag:admin.rcon')
        ->name('rcon.settings.save');
    Route::delete('settings/{serverId}', [RconController::class, 'removeSettings'])
        ->middleware('flag:admin.rcon')
        ->name('rcon.settings.remove');
});

// Every route below actually issues (or lifts) a punishment via a console
// command - see RequireRconVerified / RconVerificationService for why an
// online server's RCON not being verified blocks this whole group.
Route::prefix('api/rcon')->middleware(['steam.auth', 'rcon.verified'])->group(function (): void {
    Route::get('{serverId}/history', [RconController::class, 'history'])
        ->middleware('flag:admin.rcon')
        ->name('rcon.history');
    Route::post('{serverId}/command', [RconController::class, 'command'])
        ->middleware('flag:admin.rcon')
        ->name('rcon.command');
    Route::post('{serverId}/kick', [RconController::class, 'kick'])
        ->middleware('flag:admin.rcon')
        ->name('rcon.kick');
    Route::post('{serverId}/ban', [RconController::class, 'ban'])
        ->middleware('flag:admin.rcon')
        ->name('rcon.ban');
    Route::post('{serverId}/mute', [RconController::class, 'mute'])
        ->middleware('flag:admin.rcon')
        ->name('rcon.mute');
    Route::post('{serverId}/gag', [RconController::class, 'gag'])
        ->middleware('flag:admin.rcon')
        ->name('rcon.gag');
    Route::post('{serverId}/warn', [RconController::class, 'warn'])
        ->middleware('flag:admin.rcon')
        ->name('rcon.warn');
    Route::post('{serverId}/slay', [RconController::class, 'slay'])
        ->middleware('flag:admin.rcon')
        ->name('rcon.slay');

    // Lifting a punishment: unban/unmute/ungag/unwarn, one route, the
    // action validated against a whitelist in the controller.
    Route::post('{serverId}/{action}', [RconController::class, 'lift'])
        ->middleware('flag:admin.rcon')
        ->whereIn('action', ['unban', 'unmute', 'ungag', 'unwarn'])
        ->name('rcon.lift');
});