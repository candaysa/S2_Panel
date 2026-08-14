<?php

use App\Modules\CheatCheck\App\Http\Controllers\CheatCheckController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Cheat check module API routes (C18)
|--------------------------------------------------------------------------
|
| Staff-only. Starting a check hands out a link a player runs on their own
| machine, so issuing and reading them stays behind admin.generic; deleting
| a finished scan (it holds the player's raw log) requires admin.root.
|
| The scanner's own two endpoints are NOT here – they carry no session and
| are registered in Routes/scanner.php instead.
|
*/

Route::prefix('api/cheat-check')->middleware(['steam.auth', 'flag:admin.generic'])->group(function (): void {
    Route::get('/', [CheatCheckController::class, 'index'])->name('cheat_check.index');
    Route::post('/', [CheatCheckController::class, 'store'])->name('cheat_check.store');
    Route::get('{id}', [CheatCheckController::class, 'show'])->name('cheat_check.show');
    Route::delete('{id}', [CheatCheckController::class, 'destroy'])->middleware('flag:admin.root')->name('cheat_check.destroy');
});
