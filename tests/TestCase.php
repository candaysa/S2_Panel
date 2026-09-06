<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * Plugin connections (swiftly/ranks/weaponskins/vip) point at real MySQL
     * in .env. Tests never touch those; every named connection is re-pointed
     * at an in-memory sqlite database instead. Plugin tables are created by
     * Tests\Support\CreatesPluginTables inside the tests that need them.
     */
    protected function setUp(): void
    {
        parent::setUp();

        foreach (['swiftly', 'ranks', 'weaponskins', 'vip'] as $connection) {
            config()->set("database.connections.{$connection}", [
                'driver' => 'sqlite',
                'database' => ':memory:',
                'prefix' => '',
                'foreign_key_constraints' => true,
            ]);
        }
    }
}