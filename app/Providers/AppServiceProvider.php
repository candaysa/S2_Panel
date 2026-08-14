<?php

namespace App\Providers;

use App\Support\ModuleRegistry;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Singleton: every module's ServiceProvider (register() and boot(),
        // ~2 calls each) asks this whether it's enabled. One instance per
        // request means the module_toggles override lookup happens once
        // (then serves from its own in-memory copy) instead of once per
        // call. AppServiceProvider is always the first provider registered
        // (bootstrap/providers.php), so this is bound before anything else
        // can ask for it.
        $this->app->singleton(ModuleRegistry::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // General API protection: 120 requests/minute per IP (or user id).
        RateLimiter::for('api', function (Request $request) {
            return Limit::perMinute(120)->by($request->user()?->id ?? $request->ip());
        });

        // Authentication endpoints (Steam redirect + callback) get a much
        // tighter budget to slow down brute-force / abuse attempts.
        RateLimiter::for('auth', function (Request $request) {
            return Limit::perMinute(15)->by($request->ip());
        });
    }
}