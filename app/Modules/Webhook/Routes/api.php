<?php

use App\Modules\Webhook\App\Http\Controllers\WebhookController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Webhook module API routes (C17)
|--------------------------------------------------------------------------
|
| Registered only while the module is enabled (see ModuleServiceProvider).
| Management is strictly owner-only – a webhook URL embeds a Discord
| token, so it must never be reachable by staff.
|
*/

Route::prefix('api/webhooks')->middleware(['steam.auth', 'owner.only'])->group(function (): void {
    Route::get('/', [WebhookController::class, 'index'])->name('webhooks.index');
    Route::post('/', [WebhookController::class, 'store'])->name('webhooks.store');
    Route::put('{id}', [WebhookController::class, 'update'])->name('webhooks.update');
    Route::delete('{id}', [WebhookController::class, 'destroy'])->name('webhooks.destroy');
    Route::post('{id}/test', [WebhookController::class, 'test'])->name('webhooks.test');
});