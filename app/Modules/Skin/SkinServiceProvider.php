<?php

namespace App\Modules\Skin;

use App\Support\ModuleServiceProvider;

class SkinServiceProvider extends ModuleServiceProvider
{
    public function moduleKey(): string
    {
        return 'skin';
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