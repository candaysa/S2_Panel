<?php

namespace App\Modules\Install\App\Services;

/**
 * The one place an install is declared finished - shared by the wizard's
 * last step and by restoring a backup.zip, which skips the wizard entirely.
 *
 * Before the wizard's database step there is no panel database at all, so
 * a fresh install runs its sessions and cache on files (see .env.example
 * and install.sh). Those are fine for a handful of wizard requests and a
 * poor fit afterwards: a `schedule:run` cron line added as root - which is
 * how most people add one - writes cache files the web server's user then
 * cannot overwrite. So once the database exists and the install is done,
 * both move to the database, which is what an installed panel has always
 * run on.
 *
 * Only the pre-install file default is promoted. A driver someone chose on
 * purpose (redis, memcached, array) is theirs and is left exactly as set.
 */
class InstallFinaliser
{
    public function markInstalled(string $envPath): void
    {
        $values = ['INSTALLED' => true];

        if (config('session.driver') === 'file') {
            $values['SESSION_DRIVER'] = 'database';
        }

        if (config('cache.default') === 'file') {
            $values['CACHE_STORE'] = 'database';
        }

        (new EnvWriter($envPath))->set($values);
    }
}
