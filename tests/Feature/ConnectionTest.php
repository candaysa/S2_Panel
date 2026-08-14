<?php

namespace Tests\Feature;

use App\Support\Connection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ConnectionTest extends TestCase
{
    use RefreshDatabase;

    public function test_is_healthy_for_sqlite_connection(): void
    {
        $this->assertTrue(Connection::isHealthy('sqlite'));
    }

    public function test_is_healthy_for_unknown_connection_returns_false(): void
    {
        $this->assertFalse(Connection::isHealthy('this-connection-does-not-exist'));
    }

    public function test_probe_all_reports_known_connections(): void
    {
        $probe = Connection::probeAll(['sqlite', 'missing']);

        $this->assertTrue($probe['sqlite']);
        $this->assertFalse($probe['missing']);
    }
}