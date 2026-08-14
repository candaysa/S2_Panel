<?php

use App\Modules\Stats\App\Http\Controllers\StatsController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Stats module API routes (C12)
|--------------------------------------------------------------------------
|
| Dashboard totals (across ranks/admin/panel tables), player profile from
| the plugin's rank_base and per-server A2S history from the panel-owned
| server_stats table. Any authenticated session may read stats.
|
*/

Route::prefix('api/stats')->middleware('steam.auth')->group(function (): void {
    Route::get('dashboard', [StatsController::class, 'dashboard'])->name('stats.dashboard');
    Route::get('player/{steamid}', [StatsController::class, 'player'])->name('stats.player');
    Route::get('servers/{id}/history', [StatsController::class, 'history'])->name('stats.servers.history');
});
