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
| Any authenticated session may list servers.
|
*/

Route::prefix('api/servers')->middleware('steam.auth')->group(function (): void {
    Route::get('/', [ServerController::class, 'index'])->name('server.index');
    Route::get('{id}', [ServerController::class, 'show'])->name('server.show');
});