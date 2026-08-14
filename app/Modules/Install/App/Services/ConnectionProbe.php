<?php

namespace App\Modules\Install\App\Services;

use App\Support\Connection;

/**
 * Thin wrapper around Connection::isHealthy so the installer's probe can be
 * faked in tests (the wizard must never touch a real database during CI).
 */
class ConnectionProbe
{
    public function isHealthy(string $connection): bool
    {
        return Connection::isHealthy($connection);
    }
}