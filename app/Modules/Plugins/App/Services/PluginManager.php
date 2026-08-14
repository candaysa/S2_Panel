<?php

namespace App\Modules\Plugins\App\Services;

use App\Modules\Audit\App\Services\AuditService;
use App\Modules\Plugins\App\Models\PluginInstall;
use App\Support\ModuleServiceProvider;
use App\Support\SafeZip;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Illuminate\Support\Collection;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/**
 * Installs/removes third-party plugins uploaded as a .zip (the "Plugins"
 * tab). A plugin is structurally identical to one of the panel's own
 * built-in modules - same directory layout, same ModuleServiceProvider
 * base class, same enable-gate and route-loading behaviour - the only
 * difference is where it's registered from:
 *
 *   built-in module -> bootstrap/providers.php (static, compiled in)
 *   installed plugin -> plugin_installs row, registered dynamically at
 *                        boot by AppServiceProvider::register()
 *
 * Trust model: a plugin is PHP code the owner chooses to install, exactly
 * like a WordPress plugin or a WHMCS module - this system validates its
 * *structure* (manifest, key uniqueness, safe extraction, that its
 * provider actually extends ModuleServiceProvider) but cannot and does not
 * attempt to sandbox what an installed plugin's code does once it runs.
 * Installing a plugin is an owner-only, fully-trusted action by design.
 */
class PluginManager
{
    private const MAX_ZIP_BYTES = 20 * 1024 * 1024;

    private const KEY_PATTERN = '/^[a-z][a-z0-9_]{1,39}$/';

    public function __construct(private readonly AuditService $audit)
    {
    }

    /**
     * @return Collection<int, PluginInstall>
     */
    public function all(): Collection
    {
        return PluginInstall::query()->orderBy('name')->get();
    }

    /**
     * Reserved keys: every built-in module (config/modules.php) plus every
     * already-installed plugin. A new plugin may never collide with either.
     *
     * @return array<int, string>
     */
    public function reservedKeys(): array
    {
        return array_values(array_unique(array_merge(
            array_keys((array) config('modules.modules', [])),
            PluginInstall::query()->pluck('key')->all(),
        )));
    }

    public function install(UploadedFile $file, ?int $installedBy = null): PluginInstall
    {
        if ($file->getSize() > self::MAX_ZIP_BYTES) {
            throw new InvalidArgumentException('zip_too_large');
        }

        $workDir = storage_path('app/plugin-install/'.Str::random(20));
        File::ensureDirectoryExists($workDir);

        try {
            $moduleRoot = $this->extract($file, $workDir);
            $manifest = $this->readManifest($moduleRoot);
            $key = $this->validateKey($manifest['key']);
            $studly = Str::studly($key);
            $providerClass = "App\\Modules\\{$studly}\\{$studly}ServiceProvider";
            $destination = app_path("Modules/{$studly}");

            if (File::isDirectory($destination)) {
                throw new InvalidArgumentException('plugin_already_installed');
            }

            File::moveDirectory($moduleRoot, $destination);

            // Freshly-extracted classes under App\Modules\{Studly}\* are
            // covered by Composer's existing "App\" -> "app/" PSR-4 mapping
            // in the common case, but a production deploy running
            // --classmap-authoritative would only pick them up after the
            // next `composer dump-autoload`. Registering the namespace on
            // the live ClassLoader makes the plugin resolvable immediately
            // regardless of how the autoloader was optimized.
            $this->registerAutoloadNamespace("App\\Modules\\{$studly}\\", $destination);

            if (! class_exists($providerClass)) {
                throw new InvalidArgumentException('provider_class_not_found');
            }

            if (! is_subclass_of($providerClass, ModuleServiceProvider::class)) {
                throw new InvalidArgumentException('provider_must_extend_module_service_provider');
            }

            $plugin = PluginInstall::query()->create([
                'key' => $key,
                'name' => $manifest['name'],
                'version' => $manifest['version'] ?? null,
                'author' => $manifest['author'] ?? null,
                'description' => $manifest['description'] ?? null,
                'provider_class' => $providerClass,
                'enabled' => true,
                'installed_by' => $installedBy,
            ]);

            // Live for the rest of THIS request too, not just future ones.
            try {
                self::activateInRegistry($key, $providerClass);
                app()->register($providerClass);
            } catch (Throwable) {
                // Non-fatal: the row is saved, AppServiceProvider will pick
                // it up correctly on the very next request either way.
            }

            $this->audit->log('plugin.installed', 'plugin', $key, [
                'name' => $plugin->name,
                'version' => $plugin->version,
            ]);

            return $plugin;
        } catch (Throwable $e) {
            // Never leave a half-installed plugin directory behind.
            if (isset($destination) && File::isDirectory($destination) && ! isset($plugin)) {
                File::deleteDirectory($destination);
            }

            throw $e;
        } finally {
            File::deleteDirectory($workDir);
        }
    }

    public function setEnabled(string $key, bool $enabled): PluginInstall
    {
        $plugin = PluginInstall::query()->find($key);

        if ($plugin === null) {
            throw new InvalidArgumentException('plugin_not_found');
        }

        $plugin->update(['enabled' => $enabled]);

        $this->audit->log($enabled ? 'plugin.enabled' : 'plugin.disabled', 'plugin', $key);

        return $plugin;
    }

    public function uninstall(string $key): void
    {
        $plugin = PluginInstall::query()->find($key);

        if ($plugin === null) {
            throw new InvalidArgumentException('plugin_not_found');
        }

        $studly = Str::studly($key);
        $path = app_path("Modules/{$studly}");

        if (File::isDirectory($path)) {
            File::deleteDirectory($path);
        }

        $plugin->delete();

        $this->audit->log('plugin.uninstalled', 'plugin', $key, ['name' => $plugin->name]);
    }

    private function extract(UploadedFile $file, string $workDir): string
    {
        $extractTo = $workDir.'/extracted';
        SafeZip::extract((string) $file->getRealPath(), $extractTo);

        return SafeZip::flattenSingleTopLevelDirectory($extractTo);
    }

    /**
     * @return array{key: string, name: string, version?: string, author?: string, description?: string}
     */
    private function readManifest(string $moduleRoot): array
    {
        $path = $moduleRoot.'/plugin.json';

        if (! File::exists($path)) {
            throw new InvalidArgumentException('manifest_missing');
        }

        $manifest = json_decode((string) File::get($path), true);

        if (! is_array($manifest)) {
            throw new InvalidArgumentException('manifest_invalid_json');
        }

        foreach (['key', 'name'] as $required) {
            if (empty($manifest[$required]) || ! is_string($manifest[$required])) {
                throw new InvalidArgumentException("manifest_missing_{$required}");
            }
        }

        return $manifest;
    }

    private function validateKey(string $key): string
    {
        $key = trim($key);

        if (preg_match(self::KEY_PATTERN, $key) !== 1) {
            throw new InvalidArgumentException('invalid_plugin_key');
        }

        if (in_array($key, $this->reservedKeys(), true)) {
            throw new InvalidArgumentException('plugin_key_taken');
        }

        return $key;
    }

    /**
     * ModuleServiceProvider (the base class every plugin's provider
     * extends, same as every built-in module) gates register()/boot()
     * through ModuleRegistry::isEnabled(), which only reads
     * config('modules.modules') - it has no idea plugin_installs exists.
     * Injecting a matching entry there is what makes a plugin "enabled"
     * from the base class's point of view, exactly as if it had been a
     * built-in module all along. AppServiceProvider::registerInstalledPlugins()
     * does the same thing for every request after this one.
     */
    public static function activateInRegistry(string $key, string $providerClass): void
    {
        config(["modules.modules.{$key}" => [
            'enabled' => true,
            'provider' => $providerClass,
            'depends' => [],
        ]]);
    }

    private function registerAutoloadNamespace(string $prefix, string $path): void
    {
        $autoloadFile = base_path('vendor/autoload.php');

        if (! File::exists($autoloadFile)) {
            return;
        }

        // Composer's generated autoload.php returns the same ClassLoader
        // singleton on every include (it guards itself against
        // double-initialization), so this is safe to call on every install.
        $loader = require $autoloadFile;

        if (! is_object($loader) || ! method_exists($loader, 'addPsr4')) {
            throw new RuntimeException('composer_autoloader_unavailable');
        }

        $loader->addPsr4($prefix, $path.'/');
    }
}
