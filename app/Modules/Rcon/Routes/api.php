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
    Route::get('settings', [RconController::class, 'listSettings'])
        ->middleware('flag:admin.rcon')
        ->name('rcon.settings.index');
    Route::post('settings', [RconController::class, 'saveSettings'])
        ->middleware('flag:admin.rcon')
        ->name('rcon.settings.save');
    Route::delete('settings/{serverId}', [RconController::class, 'removeSettings'])
        ->middleware('flag:admin.rcon')
        ->name('rcon.settings.remove');

    Route::post('{serverId}/command', [RconController::class, 'command'])
        ->middleware('flag:admin.rcon')
        ->name('rcon.command');
    Route::post('{serverId}/kick', [RconController::class, 'kick'])
        ->middleware('flag:admin.rcon')
        ->name('rcon.kick');
    Route::post('{serverId}/ban', [RconController::class, 'ban'])
        ->middleware('flag:admin.rcon')
        ->name('rcon.ban');
    Route::post('{serverId}/slay', [RconController::class, 'slay'])
        ->middleware('flag:admin.rcon')
        ->name('rcon.slay');
});