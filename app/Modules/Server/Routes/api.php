<?php

use App\Modules\Server\App\Http\Controllers\ServerController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Server module API routes (C10)
|--------------------------------------------------------------------------
|
| Server list + live A2S state. The list is read from the Swiftly
| admin_servers table; live hostname/map/players come from A2S queries.
| Reads are public - status/player counts are exactly what a visitor decides
| whether to log in to play from, and neither field ever carries RCON
| credentials (those live in the Rcon module's own encrypted table).
|
| Mutations are owner-only: adding writes a row into the plugin's own table
| and removing deletes one, so this is not something to hand to every admin.
|
*/

Route::prefix('api/servers')->group(function (): void {
    Route::get('/', [ServerController::class, 'index'])->name('server.index');
    Route::get('{id}', [ServerController::class, 'show'])->name('server.show');

    Route::middleware(['steam.auth', 'owner.only'])->group(function (): void {
        Route::post('/', [ServerController::class, 'store'])->name('server.store');
        Route::put('{id}/visibility', [ServerController::class, 'visibility'])->name('server.visibility');
        Route::delete('{id}', [ServerController::class, 'destroy'])->name('server.destroy');
    });
});
