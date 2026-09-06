<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Minimal liveness probe for any configured database connection.
 *
 * Used by the Health module to detect lost database connectivity without
 * expensive queries: a single "SELECT 1" round-trip per connection.
 */
final class Connection
{
    public static function isHealthy(string $connection): bool
    {
        try {
            DB::connection($connection)->selectOne('SELECT 1 AS ok');

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Probe every named connection that lives in config/database.php and
     * report failures as a list of connection names.
     *
     * @param  array<int, string>|null  $only  restrict to these connections
     * @return array<string, bool> connection => healthy
     */
    public static function probeAll(?array $only = null): array
    {
        $connections = $only ?? array_keys(config('database.connections', []));
        $result = [];

        foreach ($connections as $connection) {
            $result[$connection] = self::isHealthy($connection);
        }

        return $result;
    }
}