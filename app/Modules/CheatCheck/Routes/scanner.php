<?php

use App\Modules\CheatCheck\App\Http\Controllers\ScannerController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Cheat check scanner routes (C18)
|--------------------------------------------------------------------------
|
| Reached by PowerShell on the player's machine, never by a browser: there
| is no session and no CSRF token on either call. Both are authenticated by
| what they carry instead – the single-use token in the path, and the shared
| API key header on the result callback.
|
| The download path is spelled ".ps1" so the whole thing reads as one plain
| command the player can paste: irm 'https://<panel>/checkcheat.ps1/<token>' | iex
|
*/

Route::get('/checkcheat.ps1/{token}', [ScannerController::class, 'script'])
    ->where('token', '[A-Za-z0-9]{1,64}')
    ->name('cheat_check.script');

Route::post('/api/cheat-check/results', [ScannerController::class, 'results'])
    ->name('cheat_check.results');
