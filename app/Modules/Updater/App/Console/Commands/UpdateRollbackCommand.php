<?php

namespace App\Modules\Updater\App\Console\Commands;

use App\Modules\Updater\App\Services\UpdateInstaller;
use Illuminate\Console\Command;
use RuntimeException;

/**
 * The way back when an update died half way and the panel itself is out of
 * reach - maintenance mode left on, or the new code failing to boot.
 * Settings > Updates has the same button; this is for when that page will
 * not load. Safe to run at any point: the rollback is idempotent.
 */
class UpdateRollbackCommand extends Command
{
    protected $signature = 'panel:update-rollback';

    protected $description = 'Restore the previous panel release after an interrupted or failed update';

    public function handle(UpdateInstaller $installer): int
    {
        $pending = $installer->pending();

        if ($pending === null) {
            $this->info('No update in progress - nothing to roll back.');

            return self::SUCCESS;
        }

        // Every file put back is written by whoever runs this. As root, those
        // come back root-owned, and the next update from the panel fails its
        // "install directory writable" check on them.
        if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
            $this->warn('Running as root: restored files will be owned by root. Prefer: sudo -u www-data php artisan panel:update-rollback');
            $this->warn('If you continue, run "chown -R www-data:www-data" on the install directory afterwards.');

            if (! $this->confirm('Continue as root?', false)) {
                return self::FAILURE;
            }
        }

        $this->line("Rolling back the update to {$pending['version']} (stopped at: {$pending['stage']})...");
        try {
            $installer->rollBack();
        } catch (RuntimeException $e) {
            $this->error($e->getMessage() === 'update_in_progress'
                ? 'An update request is still running - wait for it to finish, then try again.'
                : 'Roll-back failed: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->info('Previous release restored and maintenance mode lifted.');

        return self::SUCCESS;
    }
}
