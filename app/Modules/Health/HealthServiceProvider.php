<?php

namespace App\Modules\Health;

use App\Modules\Health\App\Console\RunHealthCheck;
use App\Support\ModuleServiceProvider;

class HealthServiceProvider extends ModuleServiceProvider
{
    public function moduleKey(): string
    {
        return 'health';
    }

    protected function registerModule(): void
    {
        //
    }

    protected function bootModule(): void
    {
        $this->commands([RunHealthCheck::class]);
    }
}