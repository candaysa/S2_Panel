<?php

use App\Modules\Audit\App\Http\Controllers\AuditController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Audit module API routes
|--------------------------------------------------------------------------
|
| The audit trail is sensitive – owner and admin.root flagged users only
| (RequireFlag lets the owner through; see app/Http/Middleware/RequireFlag).
|
*/

Route::prefix('api/audit')->middleware(['steam.auth', 'flag:admin.root'])->group(function (): void {
    Route::get('/', [AuditController::class, 'index'])->name('audit.index');
    Route::get('{id}', [AuditController::class, 'show'])->name('audit.show')->whereNumber('id');
});