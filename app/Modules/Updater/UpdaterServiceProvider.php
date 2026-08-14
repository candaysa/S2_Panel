<?php

namespace App\Modules\Updater;

use App\Support\ModuleServiceProvider;

class UpdaterServiceProvider extends ModuleServiceProvider
{
    public function moduleKey(): string
    {
        return 'updater';
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
