<?php

/*
|--------------------------------------------------------------------------
| Service providers
|--------------------------------------------------------------------------
|
| Built-in module providers are derived from config/modules.php rather than
| listed a second time here. Keeping two lists in sync by hand had exactly
| one failure mode and it was silent: a module registered in the config but
| forgotten in this file simply never loaded, with no error to explain why.
|
| This file is read before the framework boots, so config() and a loaded
| .env are both unavailable - which is fine, because only the `provider`
| class names are wanted here. Whether a module is *enabled* is decided per
| request by ModuleServiceProvider, not by which providers get registered
| (see App\Support\ModuleServiceProvider::moduleEnabled()). The env() calls
| inside the required file therefore just return their defaults at this
| point, and nothing reads them.
|
| Third-party plugins are not here either: they are discovered from the
| plugin_installs table and registered dynamically - see
| AppServiceProvider::registerInstalledPlugins().
|
*/

$modules = require __DIR__.'/../config/modules.php';

return array_merge(
    // First, always: every module provider asks it for the ModuleRegistry
    // singleton during its own register() phase.
    [App\Providers\AppServiceProvider::class],
    array_values(array_map(
        static fn (array $module): string => $module['provider'],
        $modules['modules'],
    )),
);
