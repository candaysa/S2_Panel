<?php

use App\Modules\Rank\App\Http\Controllers\RankController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Rank module API routes
|--------------------------------------------------------------------------
|
| The leaderboard and player profiles are visible to every authenticated
| session; editing points is restricted to admin.root (the owner bypasses
| via RequireFlag).
|
*/

Route::prefix('api/ranks')->middleware('steam.auth')->group(function (): void {
    Route::get('/', [RankController::class, 'index'])->name('rank.index');
    Route::get('{steamid}', [RankController::class, 'show'])->name('rank.show');
    Route::patch('{steamid}/points', [RankController::class, 'updatePoints'])
        ->middleware('flag:admin.root')
        ->name('rank.points');
});