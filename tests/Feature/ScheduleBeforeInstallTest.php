<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * Every scheduled task reads the panel's database, which does not exist
 * until the install wizard creates its tables. With a cron line already in
 * place (install.sh writes one), running them before install only logs a
 * failure every few minutes - health:check, for one, tried a database
 * with empty credentials until setup finished.
 */
class ScheduleBeforeInstallTest extends TestCase
{
    public function test_nothing_is_scheduled_before_install(): void
    {
        config(['app.installed' => false]);

        $this->artisan('schedule:list')
            ->doesntExpectOutputToContain('health:check')
            ->assertSuccessful();
    }

    public function test_the_tasks_are_scheduled_once_installed(): void
    {
        config(['app.installed' => true]);

        $this->artisan('schedule:list')
            ->expectsOutputToContain('health:check')
            ->assertSuccessful();
    }
}
