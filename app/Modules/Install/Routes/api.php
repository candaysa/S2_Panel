<?php

use App\Modules\Install\App\Http\Controllers\InstallController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Install module API routes
|--------------------------------------------------------------------------
|
| Public on purpose – the installer must run before any authentication
| exists. The InstallLock middleware exempts "api/install/*" while the
| panel is not installed.
|
*/

Route::prefix('api/install')->group(function (): void {
    Route::get('status', [InstallController::class, 'status'])->name('install.status');
    Route::post('locale', [InstallController::class, 'locale'])->name('install.locale');
    Route::post('database', [InstallController::class, 'database'])->name('install.database');
    Route::post('steam', [InstallController::class, 'steam'])->name('install.steam');
    Route::post('complete', [InstallController::class, 'complete'])->name('install.complete');
    Route::post('restore-backup', [InstallController::class, 'restoreBackup'])->name('install.restore-backup');
});