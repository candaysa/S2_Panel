<?php

namespace App\Modules\Admin;

use App\Support\ModuleServiceProvider;

class AdminServiceProvider extends ModuleServiceProvider
{
    public function moduleKey(): string
    {
        return 'admin';
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