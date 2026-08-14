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
| server_stats table. Public - aggregate counts and per-player K/D carry no
| PII beyond what the leaderboard already shows.
|
*/

Route::prefix('api/stats')->group(function (): void {
    Route::get('dashboard', [StatsController::class, 'dashboard'])->name('stats.dashboard');
    Route::get('player/{steamid}', [StatsController::class, 'player'])->name('stats.player');
    Route::get('servers/{id}/history', [StatsController::class, 'history'])->name('stats.servers.history');
});
