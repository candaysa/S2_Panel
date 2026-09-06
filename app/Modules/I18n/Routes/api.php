<?php

use App\Modules\I18n\App\Http\Controllers\I18nController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| I18n module API routes
|--------------------------------------------------------------------------
|
| Public on purpose: the SPA fetches locales and message sets before the
| user logs in, and the session locale must be selectable from the login
| screen.
|
*/

Route::prefix('api/i18n')->group(function (): void {
    Route::get('locales', [I18nController::class, 'index'])->name('i18n.locales');
    Route::get('{locale}', [I18nController::class, 'show'])->name('i18n.show')->where('locale', '[a-z]{2}');
    Route::put('locale', [I18nController::class, 'setLocale'])->name('i18n.set');
});