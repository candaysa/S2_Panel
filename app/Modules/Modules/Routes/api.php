<?php

use App\Modules\Modules\App\Http\Controllers\ModuleController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Module management API routes
|--------------------------------------------------------------------------
|
| Owner-only, same gate as the Settings module - this controls which
| features are even reachable, so it is at least as sensitive as site
| identity.
|
*/

Route::prefix('api/modules')->middleware(['steam.auth', 'owner.only'])->group(function (): void {
    Route::get('/', [ModuleController::class, 'index'])->name('modules.index');
    Route::put('{key}', [ModuleController::class, 'update'])->name('modules.update');
});
