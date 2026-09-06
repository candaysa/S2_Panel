<?php

namespace App\Modules\Plugins;

use App\Support\ModuleServiceProvider;

/**
 * Third-party plugin management (the "Plugins" tab). Always enabled - this
 * is how installed plugins get discovered and booted in the first place,
 * so it can never be the thing that's switched off (same reasoning as
 * auth/install/modules).
 */
class PluginsServiceProvider extends ModuleServiceProvider
{
    public function moduleKey(): string
    {
        return 'plugins';
    }

    protected function registerModule(): void
    {
        //
    }

    protected function bootModule(): void
    {
        //
    }
}
