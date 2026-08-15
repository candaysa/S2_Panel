<?php

use App\Modules\Skin\App\Http\Controllers\SkinController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Skin module API routes
|--------------------------------------------------------------------------
|
| Every route needs a session; SkinController::authorized() then checks
| that the target steamid is the caller's own, or that the caller has
| admin.root (or is the owner) for support/moderation access to anyone's.
|
*/

Route::prefix('api/skins')->middleware('steam.auth')->group(function (): void {
    Route::get('catalog/weapons', [SkinController::class, 'catalogWeapons'])->name('skin.catalog.weapons');
    Route::get('catalog/weapons/{weapon}/paints', [SkinController::class, 'catalogPaintkits'])->name('skin.catalog.paints');
    Route::get('catalog/knives', [SkinController::class, 'catalogKnives'])->name('skin.catalog.knives');
    Route::get('catalog/gloves', [SkinController::class, 'catalogGloves'])->name('skin.catalog.gloves');
    Route::get('catalog/agents', [SkinController::class, 'catalogAgents'])->name('skin.catalog.agents');
    Route::get('catalog/music', [SkinController::class, 'catalogMusic'])->name('skin.catalog.music');

    Route::get('{steamid}', [SkinController::class, 'show'])->name('skin.show');
    Route::put('{steamid}/{slot}', [SkinController::class, 'store'])->name('skin.store');
    Route::delete('{steamid}/{slot}', [SkinController::class, 'destroy'])->name('skin.destroy');
});
