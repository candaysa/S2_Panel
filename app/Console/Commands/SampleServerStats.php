<?php

namespace App\Console\Commands;

use App\Modules\ServerDetails\App\Services\ServerDetailsService;
use Illuminate\Console\Command;

/**
 * Records one population sample per server, every 5 minutes (see
 * bootstrap/app.php) - the data the Server Details activity chart reads.
 * Pruning old samples is a separate, once-daily schedule entry (see
 * ServerDetailsService::prune()) - not worth paying a delete scan's cost
 * on every 5-minute tick when it only ever finds rows to remove once a day.
 */
class SampleServerStats extends Command
{
    protected $signature = 'server-details:sample';

    protected $description = 'Record a player-count sample for every server';

    public function handle(ServerDetailsService $details): int
    {
        $details->sampleAll();

        return self::SUCCESS;
    }
}
