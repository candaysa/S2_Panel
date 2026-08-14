<?php

use App\Modules\Vip\App\Http\Controllers\VipController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Vip module API routes
|--------------------------------------------------------------------------
|
| Reads VIPCore's vip_users/vip_servers tables (https://github.com/
| SwiftlyS2-Plugins/VIPCore). Listing is visible to every authenticated
| session; granting/revoking VIP requires admin.vip (the owner bypasses
| via RequireFlag).
|
*/

Route::prefix('api/vip')->middleware('steam.auth')->group(function (): void {
    Route::get('/', [VipController::class, 'index'])->name('vip.index');
    Route::get('servers', [VipController::class, 'servers'])->name('vip.servers');
    Route::get('{steamid}', [VipController::class, 'show'])->name('vip.show');

    Route::middleware('flag:admin.vip')->group(function (): void {
        Route::post('/', [VipController::class, 'grant'])->name('vip.grant');
        Route::delete('{steamid}/{group}', [VipController::class, 'revoke'])->name('vip.revoke');
    });
});
