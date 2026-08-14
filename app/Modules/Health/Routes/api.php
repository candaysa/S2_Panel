<?php

use App\Modules\Health\App\Http\Controllers\HealthController;
use App\Modules\Health\App\Http\Controllers\NotificationController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Health module API routes (C16)
|--------------------------------------------------------------------------
|
| Registered only while the module is enabled (see ModuleServiceProvider).
| Health status and the owner notification center are strictly owner-only
| (steam.auth + owner.only) – monitoring data must never leak to staff.
|
*/

Route::prefix('api/health')->middleware(['steam.auth', 'owner.only'])->group(function (): void {
    Route::get('/', [HealthController::class, 'status'])->name('health.status');
});

Route::prefix('api/notifications')->middleware(['steam.auth', 'owner.only'])->group(function (): void {
    Route::get('/', [NotificationController::class, 'index'])->name('notifications.index');
    Route::post('{id}/read', [NotificationController::class, 'markRead'])->name('notifications.read');
    Route::post('read-all', [NotificationController::class, 'markAllRead'])->name('notifications.read-all');
});