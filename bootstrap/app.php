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
            'module' => \App\Http\Middleware\RequireModule::class,
            'rcon.verified' => \App\Http\Middleware\RequireRconVerified::class,
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
        // Whether a module is on is asked at due-time, not here, and via
        // ModuleRegistry rather than config(). Two reasons: config() only
        // knows the .env value, so a module switched off at runtime from
        // the Modules tab kept its jobs running even though its routes and
        // pages were gone; and doing the registry's DB lookup inline here
        // would put that query on the boot path of every artisan command,
        // schedule-related or not. skip() runs only when the task is
        // otherwise about to fire.
        $disabled = fn (string $module): \Closure => fn (): bool => ! app(\App\Support\ModuleRegistry::class)->isEnabled($module);

        // Health sweep every 5 minutes (C16): database liveness + RCON
        // auth probes, owner alert on state changes to "down".
        $schedule->command('health:check')
            ->everyFiveMinutes()
            ->withoutOverlapping()
            ->skip($disabled('health'));

        // Keeps Bans/Admins/Ranks off the critical path of a live Steam API
        // call - see WarmSteamProfileCache. Hourly, well inside the 12h
        // cache window it is refreshing, so a real page load only ever
        // needs a genuinely new player's profile fetched live, not
        // whichever page happens to load first after the cache expires.
        $schedule->command('steam:warm-profiles')->hourly()->withoutOverlapping();

        // Server Details activity chart (C21): a population sample every 5
        // minutes, pruned once a day rather than on every tick - see
        // SampleServerStats and ServerDetailsService::prune().
        $schedule->command('server-details:sample')
            ->everyFiveMinutes()
            ->withoutOverlapping()
            ->skip($disabled('server_details'));

        $schedule->call(fn () => app(\App\Modules\ServerDetails\App\Services\ServerDetailsService::class)->prune())
            ->name('server-details:prune')
            ->daily()
            ->withoutOverlapping()
            ->skip($disabled('server_details'));
    })->create();