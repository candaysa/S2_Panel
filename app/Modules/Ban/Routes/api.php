<?php

use App\Modules\Ban\App\Http\Controllers\BanController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Ban module API routes
|--------------------------------------------------------------------------
|
| Punishment records are read-only panel data. Access requires the steam
| session plus any moderation flag – the owner always passes via RequireFlag.
|
*/

Route::prefix('api/bans')->middleware(['steam.auth', 'flag:admin.ban,admin.mute,admin.kick,admin.generic'])->group(function (): void {
    Route::get('/', [BanController::class, 'index'])->name('ban.index');
});