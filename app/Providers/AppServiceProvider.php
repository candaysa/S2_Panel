<?php

namespace App\Providers;

use App\Modules\Plugins\App\Services\PluginManager;
use App\Support\ModuleRegistry;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use SocialiteProviders\Manager\SocialiteWasCalled;
use SocialiteProviders\Steam\Provider as SteamProvider;
use Throwable;

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

        $this->registerInstalledPlugins();
    }

    /**
     * Third-party plugins (see PluginManager) aren't compiled into
     * bootstrap/providers.php like the panel's own built-in modules - they
     * are discovered from the plugin_installs table and registered here,
     * dynamically, on every request. This has to run during THIS request's
     * register() phase (not boot()) so each plugin's own register() *and*
     * boot() both run through Laravel's normal provider lifecycle exactly
     * like every other provider.
     *
     * Raw query builder (not the PluginInstall Eloquent model): Eloquent's
     * connection resolver isn't wired up until DatabaseServiceProvider's
     * boot() runs, which hasn't happened yet at this point in the register
     * pass. The query builder only needs the "db" manager, which - being a
     * framework service registered before any app provider - is already
     * available. Either way, this must tolerate plugin_installs not
     * existing yet (fresh checkout, pre-migrate): any failure here just
     * means no plugins load for this request, never a 500.
     */
    private function registerInstalledPlugins(): void
    {
        try {
            $plugins = DB::table('plugin_installs')
                ->where('enabled', true)
                ->get(['key', 'provider_class']);
        } catch (Throwable) {
            return;
        }

        foreach ($plugins as $plugin) {
            if (! is_string($plugin->provider_class) || ! class_exists($plugin->provider_class)) {
                continue;
            }

            try {
                // See PluginManager::activateInRegistry() - this is what
                // makes ModuleServiceProvider::moduleEnabled() (which only
                // knows about config('modules.modules')) treat this plugin
                // as enabled, exactly like a built-in module.
                PluginManager::activateInRegistry($plugin->key, $plugin->provider_class);
                $this->app->register($plugin->provider_class);
            } catch (Throwable) {
                // A broken/removed plugin must never take the whole panel
                // down - skip it and let the other providers register.
            }
        }
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Socialite ships no "steam" driver - socialiteproviders/steam adds
        // one, but only if something listens for SocialiteWasCalled and
        // extends the manager. Nothing did, so every login attempt died with
        // "Driver [steam] not supported" the moment anyone clicked through:
        // a 500 on /api/auth/redirect that made the panel impossible to sign
        // into at all. The package documents an $listen entry on the old
        // EventServiceProvider, which this skeleton does not have.
        Event::listen(function (SocialiteWasCalled $event): void {
            $event->extendSocialite('steam', SteamProvider::class);
        });

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