<?php

use App\Modules\Settings\App\Http\Controllers\SettingsController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Settings module API routes
|--------------------------------------------------------------------------
|
| Every endpoint is owner-only – site identity is a privileged store and
| uploads land in the public web root.
|
*/

Route::prefix('api/settings')->middleware(['steam.auth', 'owner.only'])->group(function (): void {
    Route::get('/', [SettingsController::class, 'index'])->name('settings.index');
    Route::put('/', [SettingsController::class, 'update'])->name('settings.update');
    Route::post('logo', [SettingsController::class, 'uploadLogo'])->name('settings.logo');
    Route::post('favicon', [SettingsController::class, 'uploadFavicon'])->name('settings.favicon');
    Route::post('brand-color/reset', [SettingsController::class, 'resetBrandColor'])->name('settings.brand-color.reset');
    Route::put('theme', [SettingsController::class, 'updateTheme'])->name('settings.theme.update');
    Route::post('theme/reset', [SettingsController::class, 'resetTheme'])->name('settings.theme.reset');
    Route::get('backup', [SettingsController::class, 'backup'])->name('settings.backup');
});