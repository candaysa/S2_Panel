<?php

namespace App\Modules\Rank;

use App\Support\ModuleServiceProvider;

class RankServiceProvider extends ModuleServiceProvider
{
    public function moduleKey(): string
    {
        return 'rank';
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