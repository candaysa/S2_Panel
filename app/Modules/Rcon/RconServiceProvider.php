<?php

namespace App\Modules\Rcon;

use App\Support\ModuleServiceProvider;

class RconServiceProvider extends ModuleServiceProvider
{
    public function moduleKey(): string
    {
        return 'rcon';
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