<?php

use App\Modules\Report\App\Http\Controllers\ReportController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Report module API routes (C8)
|--------------------------------------------------------------------------
|
| Tickets are panel-owned data. Any authenticated session can open a
| ticket (player report or admin application) and manage their own; staff
| (admin.generic) see/manage everything; closing a ticket requires the
| superadmin flag (admin.root). The owner always passes via RequireFlag.
|
*/

Route::prefix('api/reports')->middleware('steam.auth')->group(function (): void {
    Route::get('/', [ReportController::class, 'index'])->name('report.index');
    Route::post('/', [ReportController::class, 'store'])->name('report.store');
    Route::get('{id}', [ReportController::class, 'show'])->name('report.show');
    Route::post('{id}/reply', [ReportController::class, 'reply'])->name('report.reply');
    Route::post('{id}/close', [ReportController::class, 'close'])->middleware('flag:admin.root')->name('report.close');
    Route::delete('{id}', [ReportController::class, 'destroy'])->middleware('flag:admin.generic')->name('report.destroy');
});