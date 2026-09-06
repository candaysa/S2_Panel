<?php

use App\Modules\Plugins\App\Http\Controllers\PluginController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Plugin management API routes
|--------------------------------------------------------------------------
|
| Owner-only - installing a plugin is arbitrary PHP code execution by
| design (see PluginManager's docblock), at least as sensitive as Settings.
|
*/

Route::prefix('api/plugins')->middleware(['steam.auth', 'owner.only'])->group(function (): void {
    Route::get('/', [PluginController::class, 'index'])->name('plugins.index');
    Route::post('/', [PluginController::class, 'store'])->name('plugins.store');
    Route::put('{key}', [PluginController::class, 'update'])->name('plugins.update');
    Route::delete('{key}', [PluginController::class, 'destroy'])->name('plugins.destroy');
});
