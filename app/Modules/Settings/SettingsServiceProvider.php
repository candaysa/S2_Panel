<?php

namespace App\Modules\Settings;

use App\Support\ModuleServiceProvider;

class SettingsServiceProvider extends ModuleServiceProvider
{
    public function moduleKey(): string
    {
        return 'settings';
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