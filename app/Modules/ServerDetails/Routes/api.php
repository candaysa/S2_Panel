<?php

use App\Modules\ServerDetails\App\Http\Controllers\ServerDetailsController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Server Details module API routes (C21)
|--------------------------------------------------------------------------
|
| Public reads, same rule as the Server module's own list/show: server
| population and player names are exactly what a visitor decides whether
| to log in to play from, not privileged data.
|
*/

Route::prefix('api/server-details')->group(function (): void {
    Route::get('{id}/stats', [ServerDetailsController::class, 'stats'])->name('server-details.stats');
    Route::get('{id}/players', [ServerDetailsController::class, 'players'])->name('server-details.players');
});
