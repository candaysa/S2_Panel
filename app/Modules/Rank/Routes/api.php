<?php

use App\Modules\Rank\App\Http\Controllers\RankController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Rank module API routes
|--------------------------------------------------------------------------
|
| The leaderboard and player profiles are public - it is exactly what a
| visitor would want to see before logging in. Editing points still
| requires a session plus admin.root (the owner bypasses via RequireFlag).
|
*/

Route::prefix('api/ranks')->group(function (): void {
    Route::get('/', [RankController::class, 'index'])->name('rank.index');
    Route::get('{steamid}', [RankController::class, 'show'])->name('rank.show');
    Route::patch('{steamid}/points', [RankController::class, 'updatePoints'])
        ->middleware(['steam.auth', 'flag:admin.root'])
        ->name('rank.points');
});