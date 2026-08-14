<?php

namespace App\Modules\Health\App\Console;

use App\Modules\Health\App\Services\HealthService;
use Illuminate\Console\Command;

/**
 * Run a full health sweep (C16). Scheduled every 5 minutes while the
 * module is enabled (bootstrap/app.php). Exits 1 when any component is
 * down, so external cron monitoring can react too.
 */
class RunHealthCheck extends Command
{
    protected $signature = 'health:check';

    protected $description = 'Probe databases and RCON servers, alert the owner on failures';

    public function handle(HealthService $health): int
    {
        $results = $health->check();

        $down = 0;

        foreach ($results as $result) {
            if ($result['status'] === 'down') {
                $down++;
                $this->error($result['component'].': '.($result['message'] ?? 'down'));
            } else {
                $this->info($result['component'].': ok');
            }
        }

        $this->line("Checked ".count($results)." component(s), {$down} down.");

        return $down > 0 ? self::FAILURE : self::SUCCESS;
    }
}