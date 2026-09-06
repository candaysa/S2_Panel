<?php

use App\Modules\Admin\App\Http\Controllers\AdminController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Admin module API routes
|--------------------------------------------------------------------------
|
| Managing admins is a root-level capability: steam.auth + the admin.root
| flag (RequireFlag lets the owner through as well). Reading the group list
| uses the same gate – the groups themselves are not sensitive, but keeping
| a single rule is simpler.
|
*/

Route::prefix('api/admin')->middleware(['steam.auth', 'flag:admin.root'])->group(function (): void {
    Route::get('/', [AdminController::class, 'index'])->name('admin.index');
    Route::post('/', [AdminController::class, 'store'])->name('admin.store');
    Route::put('{id}', [AdminController::class, 'update'])->name('admin.update')->whereNumber('id');
    Route::delete('{id}', [AdminController::class, 'destroy'])->name('admin.destroy')->whereNumber('id');

    // group name is the primary key Swiftly itself uses (admin_admins.groups
    // stores names, not ids), so it's the route parameter rather than a
    // surrogate id - {id} above is numeric-constrained precisely so it
    // never swallows a group name here.
    Route::get('groups', [AdminController::class, 'groups'])->name('admin.groups');
    Route::post('groups', [AdminController::class, 'storeGroup'])->name('admin.groups.store');
    Route::put('groups/{name}', [AdminController::class, 'updateGroup'])->name('admin.groups.update');
    Route::delete('groups/{name}', [AdminController::class, 'destroyGroup'])->name('admin.groups.destroy');
});