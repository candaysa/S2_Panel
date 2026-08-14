<?php

namespace App\Modules\Vip;

use App\Support\ModuleServiceProvider;

class VipServiceProvider extends ModuleServiceProvider
{
    public function moduleKey(): string
    {
        return 'vip';
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