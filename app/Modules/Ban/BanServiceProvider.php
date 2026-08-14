<?php

namespace App\Modules\Ban;

use App\Support\ModuleServiceProvider;

class BanServiceProvider extends ModuleServiceProvider
{
    public function moduleKey(): string
    {
        return 'ban';
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