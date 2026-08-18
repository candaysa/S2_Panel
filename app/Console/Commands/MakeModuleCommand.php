<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * Scaffolds a built-in module.
 *
 * Adding one by hand meant touching four files in the right order and
 * getting a naming convention right in three of them - miss the config
 * entry and the module silently never loads, miss the .env line and it
 * loads but is off. None of that is interesting work, so this does it.
 *
 *   php artisan make:module Trophy
 *   php artisan make:module Trophy --toggleable
 *
 * What it does NOT do is wire a page into the sidebar or write i18n
 * strings. Those are choices about the product, not boilerplate, and the
 * generated provider's docblock points at them.
 */
class MakeModuleCommand extends Command
{
    protected $signature = 'make:module
                            {name : StudlyCase module name, e.g. Trophy}
                            {--toggleable : Also let the owner switch it off from the Modules tab}';

    protected $description = 'Scaffold a new built-in module under app/Modules';

    public function handle(): int
    {
        $studly = Str::studly($this->argument('name'));
        $key = Str::snake($studly);
        $path = app_path("Modules/{$studly}");

        if (is_dir($path)) {
            $this->components->error("app/Modules/{$studly} already exists.");

            return self::FAILURE;
        }

        if (isset(config('modules.modules')[$key])) {
            $this->components->error("A module with key [{$key}] is already registered in config/modules.php.");

            return self::FAILURE;
        }

        foreach (['App/Http/Controllers', 'App/Models', 'App/Services', 'Database/Migrations', 'Routes'] as $dir) {
            mkdir("{$path}/{$dir}", 0755, true);
        }

        file_put_contents("{$path}/{$studly}ServiceProvider.php", $this->provider($studly, $key));
        file_put_contents("{$path}/Routes/api.php", $this->routes($studly, $key));
        file_put_contents("{$path}/App/Http/Controllers/{$studly}Controller.php", $this->controller($studly, $key));

        $this->components->info("Created app/Modules/{$studly}");

        $this->registerInConfig($studly, $key);
        $this->registerInEnvExample($key);

        if ($this->option('toggleable')) {
            $this->components->warn(
                "Add '{$key}' to ModuleRegistry::toggleable() and a modules.items.{$key} entry ".
                'to each app/Modules/I18n/lang/*/messages.php to finish the Modules tab wiring.'
            );
        }

        $this->newLine();
        $this->components->bulletList([
            "Set MODULE_".Str::upper($key)."=true in .env",
            'Run: php artisan config:clear',
            "Routes live in app/Modules/{$studly}/Routes/api.php",
            'See docs/module-development.md for pages, migrations and access gates',
        ]);

        return self::SUCCESS;
    }

    /**
     * The provider is registered automatically - bootstrap/providers.php
     * derives its list from this file - so the config entry is the only
     * place a new module has to be declared.
     */
    private function registerInConfig(string $studly, string $key): void
    {
        $path = config_path('modules.php');
        $config = (string) file_get_contents($path);
        $env = 'MODULE_'.Str::upper($key);

        $entry = <<<PHP

                '{$key}' => [
                    'enabled' => env('{$env}', false),
                    'provider' => App\\Modules\\{$studly}\\{$studly}ServiceProvider::class,
                    'depends' => ['auth'],
                ],

        PHP;

        // Before the closing bracket of the 'modules' array.
        $marker = "\n    ],\n\n];";

        if (! str_contains($config, $marker)) {
            $this->components->warn("Could not edit config/modules.php automatically - add the '{$key}' entry by hand.");

            return;
        }

        $config = str_replace($marker, rtrim($entry, "\n")."\n".$marker, $config);
        file_put_contents($path, $config);

        $this->components->info("Registered '{$key}' in config/modules.php");
    }

    private function registerInEnvExample(string $key): void
    {
        $path = base_path('.env.example');
        $line = 'MODULE_'.Str::upper($key).'=true';

        if (! is_file($path)) {
            return;
        }

        $contents = (string) file_get_contents($path);

        if (str_contains($contents, $line)) {
            return;
        }

        // After the last existing MODULE_ line, so the block stays together.
        $lines = explode("\n", $contents);
        $last = 0;

        foreach ($lines as $i => $text) {
            if (str_starts_with($text, 'MODULE_')) {
                $last = $i;
            }
        }

        if ($last === 0) {
            return;
        }

        array_splice($lines, $last + 1, 0, $line);
        file_put_contents($path, implode("\n", $lines));

        $this->components->info("Added {$line} to .env.example");
    }

    private function provider(string $studly, string $key): string
    {
        $env = 'MODULE_'.Str::upper($key);

        return <<<PHP
        <?php

        namespace App\\Modules\\{$studly};

        use App\\Support\\ModuleServiceProvider;

        /**
         * The {$studly} module.
         *
         * The base class handles the enable/disable gate, loads
         * Routes/api.php (inside the "api" middleware group) while enabled,
         * and loads Database/Migrations always - so a disabled module never
         * leaves orphaned tables behind.
         *
         * Still to wire up by hand, because each is a product decision
         * rather than boilerplate:
         *   - a Blade page + a route in routes/web.php, gated with
         *     middleware('module:{$key}') so the page disappears with the
         *     module rather than 404ing every fetch behind it
         *   - a sidebar entry in resources/views/components/layout/sidebar.blade.php
         *   - i18n strings in each app/Modules/I18n/lang/<locale>/messages.php
         *
         * See docs/module-development.md.
         */
        class {$studly}ServiceProvider extends ModuleServiceProvider
        {
            public function moduleKey(): string
            {
                // Matches config/modules.php and the {$env} env var.
                return '{$key}';
            }

            protected function registerModule(): void
            {
                // Container bindings, if any. Runs only while enabled.
            }

            protected function bootModule(): void
            {
                // Usually empty - routes and migrations are already wired.
            }
        }

        PHP;
    }

    private function routes(string $studly, string $key): string
    {
        return <<<PHP
        <?php

        use App\\Modules\\{$studly}\\App\\Http\\Controllers\\{$studly}Controller;
        use Illuminate\\Support\\Facades\\Route;

        /*
        |--------------------------------------------------------------------------
        | {$studly} module API routes
        |--------------------------------------------------------------------------
        |
        | Loaded automatically while the module is enabled, inside the panel's
        | "api" middleware group (security headers, CSRF, rate limiting).
        |
        | Pick the narrowest gate that fits, and mirror it on the page route in
        | routes/web.php so the nav can never promise more than the API grants:
        |   'steam.auth'          - any logged-in player
        |   'flag:admin.generic'  - any admin
        |   'flag:admin.root'     - root admins (the owner always passes)
        |   'owner.only'          - the panel owner alone
        |
        */

        Route::prefix('api/{$key}')->middleware(['steam.auth'])->group(function (): void {
            Route::get('/', [{$studly}Controller::class, 'index'])->name('{$key}.index');
        });

        PHP;
    }

    private function controller(string $studly, string $key): string
    {
        return <<<PHP
        <?php

        namespace App\\Modules\\{$studly}\\App\\Http\\Controllers;

        use App\\Support\\Api;
        use Illuminate\\Http\\JsonResponse;

        /**
         * GET /api/{$key}
         *
         * Api::success()/Api::error() rather than response()->json() - every
         * endpoint in the panel answers in the same {data, meta, message}
         * shape, which is what the frontend's fetchJson() helpers expect.
         */
        class {$studly}Controller
        {
            public function index(): JsonResponse
            {
                return Api::success([]);
            }
        }

        PHP;
    }
}
