<?php

namespace App\Modules\Stats;

use App\Modules\Stats\App\Console\CollectServerStats;
use App\Support\ModuleServiceProvider;

class StatsServiceProvider extends ModuleServiceProvider
{
    public function moduleKey(): string
    {
        return 'stats';
    }

    protected function registerModule(): void
    {
        //
    }

    protected function bootModule(): void
    {
        $this->commands([CollectServerStats::class]);
    }
}