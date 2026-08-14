<?php

namespace App\Modules\Install;

use App\Support\ModuleServiceProvider;

class InstallServiceProvider extends ModuleServiceProvider
{
    public function moduleKey(): string
    {
        return 'install';
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