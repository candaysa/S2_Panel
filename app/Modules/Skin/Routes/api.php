<?php

use App\Modules\Skin\App\Http\Controllers\SkinController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Skin module API routes
|--------------------------------------------------------------------------
|
| Loadout reads and the static catalog are visible to any authenticated
| session; write/delete mutations require admin.root (owner bypasses via
| RequireFlag).
|
*/

Route::prefix('api/skins')->middleware('steam.auth')->group(function (): void {
    Route::get('catalog/{type}', [SkinController::class, 'catalog'])->name('skin.catalog');
    Route::get('{steamid}', [SkinController::class, 'show'])->name('skin.show');

    Route::middleware('flag:admin.root')->group(function (): void {
        Route::put('{steamid}/{slot}', [SkinController::class, 'store'])->name('skin.store');
        Route::delete('{steamid}/{slot}', [SkinController::class, 'destroy'])->name('skin.destroy');
    });
});