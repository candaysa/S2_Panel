<?php

use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes (JSON)
|--------------------------------------------------------------------------
|
| Module routes are registered by their own ServiceProviders under
| app/Modules/*. Group them with the "steam.auth" middleware unless the
| endpoint is explicitly public (login/callback/install).
|
*/

Route::prefix('api')->group(function (): void {
    //
});