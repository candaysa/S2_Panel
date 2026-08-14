<?php

namespace App\Modules\Modules;

use App\Support\ModuleServiceProvider;

/**
 * Owner-facing module management ("Modules" tab). Always enabled - this is
 * how the owner turns other modules on/off at runtime, so it can never be
 * the thing that's switched off (same reasoning as auth/install).
 */
class ModulesServiceProvider extends ModuleServiceProvider
{
    public function moduleKey(): string
    {
        return 'modules';
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
