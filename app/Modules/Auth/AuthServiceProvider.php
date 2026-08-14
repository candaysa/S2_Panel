<?php

namespace App\Modules\Auth;

use App\Support\ModuleServiceProvider;

class AuthServiceProvider extends ModuleServiceProvider
{
    public function moduleKey(): string
    {
        return 'auth';
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