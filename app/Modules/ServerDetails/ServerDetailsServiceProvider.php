<?php

namespace App\Modules\ServerDetails;

use App\Support\ModuleServiceProvider;

class ServerDetailsServiceProvider extends ModuleServiceProvider
{
    public function moduleKey(): string
    {
        return 'server_details';
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
