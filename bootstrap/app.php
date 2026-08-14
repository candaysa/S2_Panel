<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'install.lock' => \App\Http\Middleware\InstallLock::class,
            'steam.auth' => \App\Http\Middleware\SteamAuth::class,
            'owner.only' => \App\Http\Middleware\OwnerOnly::class,
            'flag' => \App\Http\Middleware\RequireFlag::class,
        ]);

        // The panel is a session-cookie JSON SPA: Steam OpenID signs into a
        // session, every later request authenticates through that cookie.
        // So the "api" group carries the full session stack + CSRF + rate
        // limiting – the same protection the web group gets.
        $middleware->group('api', [
            \Illuminate\Cookie\Middleware\EncryptCookies::class,
            \Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse::class,
            \Illuminate\Session\Middleware\StartSession::class,
            \Illuminate\View\Middleware\ShareErrorsFromSession::class,
            \Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class,
            'throttle:api',
            \Illuminate\Routing\Middleware\SubstituteBindings::class,
            \App\Http\Middleware\SetLocale::class,
            \App\Http\Middleware\SecurityHeaders::class,
            \App\Http\Middleware\StripHtmlComments::class,
            \App\Http\Middleware\InstallLock::class,
        ]);

        // Web group keeps Laravel's defaults, plus the locale resolver and
        // the security layer, plus the install lock (redirects to /install
        // while not installed).
        $middleware->appendToGroup('web', [
            \App\Http\Middleware\SetLocale::class,
            \App\Http\Middleware\SecurityHeaders::class,
            \App\Http\Middleware\StripHtmlComments::class,
            \App\Http\Middleware\InstallLock::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })
    ->withSchedule(function (\Illuminate\Console\Scheduling\Schedule $schedule): void {
        // Sample A2S player counts every 5 minutes (C12). Gated on the
        // module config so a disabled Stats module never schedules work.
        if (config('modules.modules.stats.enabled', false)) {
            $schedule->command('stats:collect')->everyFiveMinutes();
        }

        // Health sweep every 5 minutes (C16): database liveness + RCON
        // auth probes, owner alert on state changes to "down".
        if (config('modules.modules.health.enabled', false)) {
            $schedule->command('health:check')->everyFiveMinutes();
        }
    })->create();