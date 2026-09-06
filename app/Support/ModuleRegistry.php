<?php

namespace App\Support;

use App\Modules\Modules\App\Models\ModuleToggle;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Central access point for module status and provider resolution.
 *
 * Modules are declared in config/modules.php and gated by their MODULE_*
 * env var. A curated subset (toggleable()) can additionally be overridden
 * at runtime via the owner-facing "Modules" tab: an override row in
 * module_toggles always wins over the env default, so flipping a module
 * on/off never requires editing .env or restarting the app.
 *
 * The override lookup runs from as early as a ServiceProvider's register()
 * phase (see ModuleServiceProvider), so it must tolerate the module_toggles
 * table not existing yet (fresh checkout, pre-migrate) - any failure there
 * just falls back to the env-driven config default.
 */
class ModuleRegistry
{
    private const OVERRIDE_CACHE_KEY = 'module_registry.overrides';

    private const OVERRIDE_CACHE_TTL = 300;

    /** @var array<string, bool>|null */
    private ?array $overrides = null;

    /**
     * Module keys the owner is allowed to flip via PUT /api/modules/{key}.
     *
     * The rule is "features a server can genuinely run without": every entry
     * here backs a sidebar page that some installs simply have no plugin
     * for, so leaving it switched on only advertises a feature that cannot
     * work. Everything absent stays purely env/config-driven - which keeps a
     * UI toggle from ever being able to disable core plumbing (auth,
     * install, settings, the Modules tab itself) or the data other pages
     * read through (admin, ban, server).
     *
     * @return array<int, string>
     */
    public function toggleable(): array
    {
        return ['vip', 'skin', 'rank', 'report', 'appeal', 'rcon', 'cheat_check', 'server_details'];
    }

    /**
     * Enabled modules that declare $key as a dependency.
     *
     * Switching a module off is not a local decision - turning RCON off also
     * takes the server health checks that run through it - so the Modules
     * tab shows this before asking rather than letting the owner discover it
     * from a log line afterwards. See checkDependencies().
     *
     * @return array<int, string>
     */
    public function dependents(string $key): array
    {
        $out = [];

        foreach ($this->all() as $module => $definition) {
            if ($module === $key || ! $definition['enabled']) {
                continue;
            }

            if (in_array($key, $definition['depends'] ?? [], true)) {
                $out[] = $module;
            }
        }

        return $out;
    }

    /**
     * @return array<int, string> enabled module keys
     */
    public function enabledModules(): array
    {
        return array_keys(array_filter(
            $this->all(),
            fn (array $module): bool => (bool) $module['enabled'],
        ));
    }

    /**
     * @return array<int, class-string> ServiceProvider classes of enabled modules
     */
    public function providers(): array
    {
        return array_values(array_map(
            fn (array $module): string => $module['provider'],
            array_filter(
                $this->all(),
                fn (array $module): bool => (bool) $module['enabled'],
            ),
        ));
    }

    public function isEnabled(string $key): bool
    {
        return (bool) ($this->all()[$key]['enabled'] ?? false);
    }

    /**
     * @return array<string, array{enabled: bool, provider: class-string, depends: array<int, string>}>
     */
    public function all(): array
    {
        $modules = (array) config('modules.modules', []);

        foreach ($this->overrides() as $key => $enabled) {
            if (isset($modules[$key])) {
                $modules[$key]['enabled'] = $enabled;
            }
        }

        return $modules;
    }

    /**
     * Resolve a module's provider class if it is enabled, otherwise null.
     *
     * @return class-string|null
     */
    public function resolve(string $key): ?string
    {
        $module = $this->all()[$key] ?? null;

        return $module && $module['enabled'] ? $module['provider'] : null;
    }

    /**
     * Persist an on/off override for a toggleable module and make it take
     * effect immediately (no cache staleness, no restart).
     *
     * @throws \InvalidArgumentException if $key is not in toggleable()
     */
    public function setOverride(string $key, bool $enabled, ?int $updatedBy = null): void
    {
        if (! in_array($key, $this->toggleable(), true)) {
            throw new \InvalidArgumentException("Module [{$key}] is not toggleable.");
        }

        ModuleToggle::query()->updateOrCreate(
            ['module_key' => $key],
            ['enabled' => $enabled, 'updated_by' => $updatedBy],
        );

        Cache::forget(self::OVERRIDE_CACHE_KEY);
        $this->overrides = null;
    }

    /**
     * @return array<string, bool>
     */
    private function overrides(): array
    {
        if ($this->overrides !== null) {
            return $this->overrides;
        }

        $fetch = fn (): array => ModuleToggle::query()->pluck('enabled', 'module_key')
            ->map(fn ($enabled): bool => (bool) $enabled)
            ->all();

        try {
            return $this->overrides = Cache::remember(self::OVERRIDE_CACHE_KEY, self::OVERRIDE_CACHE_TTL, $fetch);
        } catch (Throwable) {
            // 'cache' is a deferred binding, and this lookup runs from a
            // ServiceProvider's own register() phase (see
            // ModuleServiceProvider::moduleEnabled()) - early enough,
            // reliably on every single request, that the container hasn't
            // resolved it yet ("Target class [cache] does not exist").
            // 'db' has no such problem this early (AppServiceProvider
            // already queries it directly above), so read straight from
            // the table instead of going without an override for the rest
            // of the request. $this->overrides deliberately is not set to
            // [] on total failure below: memoizing an empty result here
            // used to mean a real, saved module_toggles row was silently
            // ignored for an entire request the moment this lookup failed
            // once early in boot - which it always did - leaving every
            // module stuck at its env default no matter what an owner
            // toggled.
            try {
                return $this->overrides = $fetch();
            } catch (Throwable) {
                // module_toggles genuinely does not exist yet (fresh
                // checkout, pre-migrate) - fall back to env-driven config
                // defaults for this call only.
                return [];
            }
        }
    }

    /**
     * Log a warning listing every module whose dependencies are disabled.
     *
     * Called from the base provider boot() once per application lifecycle.
     */
    public function checkDependencies(): void
    {
        foreach ($this->all() as $key => $module) {
            if (! $module['enabled']) {
                continue;
            }

            foreach ($module['depends'] as $dependency) {
                if (! $this->isEnabled($dependency)) {
                    Log::warning("Module [{$key}] depends on disabled module [{$dependency}]");
                }
            }
        }
    }
}
