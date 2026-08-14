<?php

namespace App\Modules\Server;

use App\Support\ModuleServiceProvider;

class ServerServiceProvider extends ModuleServiceProvider
{
    public function moduleKey(): string
    {
        return 'server';
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