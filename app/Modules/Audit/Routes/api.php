<?php

use App\Modules\Audit\App\Http\Controllers\AdminLogController;
use App\Modules\Audit\App\Http\Controllers\AuditController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Audit module API routes
|--------------------------------------------------------------------------
|
| Two separate logs, one gate. /api/audit is the panel's own trail (what
| someone did in the panel); /api/audit/admin-log is the admin plugin's
| in-game record (what an admin did on a server). Both are sensitive -
| between them they describe every moderation decision taken, including
| ones taken against the person reading - so both are owner and admin.root
| only (RequireFlag lets the owner through; see
| app/Http/Middleware/RequireFlag).
|
*/

Route::prefix('api/audit')->middleware(['steam.auth', 'flag:admin.root'])->group(function (): void {
    Route::get('/', [AuditController::class, 'index'])->name('audit.index');

    // Before the {id} route below, which would otherwise swallow it - the
    // whereNumber() constraint makes that impossible, but ordering it first
    // keeps the two independent of that detail.
    Route::get('admin-log', [AdminLogController::class, 'index'])->name('audit.admin-log.index');
    Route::get('admin-log/filters', [AdminLogController::class, 'filters'])->name('audit.admin-log.filters');

    Route::get('{id}', [AuditController::class, 'show'])->name('audit.show')->whereNumber('id');
});