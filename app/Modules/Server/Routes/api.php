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
| Public - status/player counts are exactly what a visitor decides whether
| to log in to play from, and neither field ever carries RCON credentials
| (those live in the Rcon module's own encrypted table).
|
*/

Route::prefix('api/servers')->group(function (): void {
    Route::get('/', [ServerController::class, 'index'])->name('server.index');
    Route::get('{id}', [ServerController::class, 'show'])->name('server.show');
});