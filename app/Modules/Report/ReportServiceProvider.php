<?php

namespace App\Modules\Report;

use App\Support\ModuleServiceProvider;

class ReportServiceProvider extends ModuleServiceProvider
{
    public function moduleKey(): string
    {
        return 'report';
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