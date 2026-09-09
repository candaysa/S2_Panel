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

    // Not public like show() above: report reasons and reporter identity
    // are moderation data, same tier Report/Appeal already gate their own
    // "see everything" views behind (admin.generic; owner bypasses via
    // RequireFlag).
    Route::get('{steamid}/activity', [RankController::class, 'activity'])
        ->middleware(['steam.auth', 'flag:admin.generic'])
        ->name('rank.activity');

    // Staff notes - same gate as activity() above, not public.
    Route::post('{steamid}/notes', [RankController::class, 'storeNote'])
        ->middleware(['steam.auth', 'flag:admin.generic'])
        ->name('rank.notes.store');
    Route::delete('notes/{id}', [RankController::class, 'destroyNote'])
        ->middleware(['steam.auth', 'flag:admin.generic'])
        ->whereNumber('id')
        ->name('rank.notes.destroy');

    Route::patch('{steamid}/points', [RankController::class, 'updatePoints'])
        ->middleware(['steam.auth', 'flag:admin.root'])
        ->name('rank.points');
});