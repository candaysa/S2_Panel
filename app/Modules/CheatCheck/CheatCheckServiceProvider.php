<?php

namespace App\Modules\CheatCheck;

use App\Support\ModuleServiceProvider;
use Illuminate\Support\Facades\Route;

class CheatCheckServiceProvider extends ModuleServiceProvider
{
    public function moduleKey(): string
    {
        return 'cheat_check';
    }

    protected function registerModule(): void
    {
        //
    }

    /**
     * The scanner talks to the panel without a session: it fetches its own
     * source over plain HTTPS and posts results back with an API key. Those
     * two endpoints therefore live outside the "api" middleware group (no
     * CSRF token exists on either side of that exchange) and are loaded
     * here instead of from Routes/api.php.
     */
    protected function bootModule(): void
    {
        Route::middleware([
            'throttle:60,1',
            \App\Http\Middleware\SecurityHeaders::class,
            \App\Http\Middleware\InstallLock::class,
        ])->group($this->modulePath().'/Routes/scanner.php');
    }
}
