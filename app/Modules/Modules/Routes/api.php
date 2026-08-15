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
    // Must be registered before the {key} wildcard below, or "admin-plugin"
    // would be swallowed as a module key instead.
    Route::put('admin-plugin', [ModuleController::class, 'updateAdminPlugin'])->name('modules.admin-plugin');
    Route::put('{key}', [ModuleController::class, 'update'])->name('modules.update');
});
