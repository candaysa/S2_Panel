<?php

use App\Modules\Appeal\App\Http\Controllers\AppealController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Appeal module API routes (C9)
|--------------------------------------------------------------------------
|
| Appeals are panel-owned data. Any authenticated player with an active
| ban can file one PENDING appeal; players manage their own appeals, staff
| (admin.generic) see everything. Deciding an appeal requires the superadmin
| flag (admin.root) – it is the panel-side decision for unbanning.
|
*/

Route::prefix('api/appeals')->middleware('steam.auth')->group(function (): void {
    Route::get('/', [AppealController::class, 'index'])->name('appeal.index');
    Route::post('/', [AppealController::class, 'store'])->name('appeal.store');
    Route::get('{id}', [AppealController::class, 'show'])->name('appeal.show');
    Route::post('{id}/decide', [AppealController::class, 'decide'])->middleware('flag:admin.root')->name('appeal.decide');
});