<?php

namespace App\Support;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;

/**
 * Base class for every module ServiceProvider (app/Modules/*).
 *
 * Provides the enable/disable gate, the standard module directory layout
 * and dependency checks. Modules extending this class only implement the
 * two small hooks and a module key.
 *
 * Directory layout (per module):
 *   Routes/api.php        — API routes (loaded only when enabled)
 *   Database/Migrations   — panel-only migrations (always loaded)
 *   app/Http/Controllers
 *   app/Models
 *   app/Services
 */
abstract class ModuleServiceProvider extends ServiceProvider
{
    /**
     * Module key as declared in config/modules.php (e.g. "ban").
     */
    abstract public function moduleKey(): string;

    /**
     * Called while the module is enabled. Register bindings here.
     */
    abstract protected function registerModule(): void;

    /**
     * Called while the module is enabled. May load routes/views/etc.
     */
    abstract protected function bootModule(): void;

    public function register(): void
    {
        if (! $this->moduleEnabled()) {
            return;
        }

        $this->registerModule();
    }

    public function boot(): void
    {
        // Panel-only migrations always run – closing a module must never
        // leave panel tables behind or block other modules.
        $this->loadMigrationsFrom($this->modulePath().'/Database/Migrations');

        if (! $this->moduleEnabled()) {
            return;
        }

        $this->checkDependencies();

        // Module routes always run inside the "api" middleware group so the
        // global security layer (SecurityHeaders, StripHtmlComments,
        // InstallLock, CSRF, rate limiting) applies to them as well.
        Route::middleware('api')->group(function (): void {
            $this->loadRoutesFrom($this->modulePath().'/Routes/api.php');
        });

        $this->bootModule();
    }

    protected function moduleEnabled(): bool
    {
        return app(ModuleRegistry::class)->isEnabled($this->moduleKey());
    }

    protected function modulePath(): string
    {
        return app_path('Modules/'.Str::studly($this->moduleKey()));
    }

    protected function checkDependencies(): void
    {
        $module = config("modules.modules.{$this->moduleKey()}", []);

        foreach ($module['depends'] ?? [] as $dependency) {
            if (! app(ModuleRegistry::class)->isEnabled($dependency)) {
                report(new \RuntimeException(
                    "Module [{$this->moduleKey()}] requires module [{$dependency}] to be enabled."
                ));
            }
        }
    }
}