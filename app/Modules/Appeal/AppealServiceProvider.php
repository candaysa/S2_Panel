<?php

namespace App\Modules\Appeal;

use App\Support\ModuleServiceProvider;

class AppealServiceProvider extends ModuleServiceProvider
{
    public function moduleKey(): string
    {
        return 'appeal';
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