<?php

namespace App\Modules\Audit;

use App\Support\ModuleServiceProvider;

class AuditServiceProvider extends ModuleServiceProvider
{
    public function moduleKey(): string
    {
        return 'audit';
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