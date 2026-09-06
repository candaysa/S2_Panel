<?php

use App\Modules\Ban\App\Http\Controllers\BanController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Ban module API routes
|--------------------------------------------------------------------------
|
| Punishment records are read-only panel data - nothing here creates or
| lifts a ban, that still happens in-game or via RCON. Any logged-in
| player may read the list, same tier as VIP/Skins/Tickets; no moderation
| flag required.
|
*/

Route::prefix('api/bans')->middleware(['steam.auth'])->group(function (): void {
    Route::get('/', [BanController::class, 'index'])->name('ban.index');
});