<?php

namespace App\Modules\Stats\App\Console;

use App\Modules\Stats\App\Services\StatsService;
use Illuminate\Console\Command;

/**
 * Sample all servers via A2S into server_stats (C12). Scheduled every
 * 5 minutes while the module is enabled (bootstrap/app.php).
 */
class CollectServerStats extends Command
{
    protected $signature = 'stats:collect';

    protected $description = 'Sample A2S player counts into server_stats';

    public function handle(StatsService $stats): int
    {
        $recorded = $stats->collect();

        $this->info("Recorded {$recorded} server sample(s).");

        return self::SUCCESS;
    }
}