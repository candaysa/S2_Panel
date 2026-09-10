<?php

namespace Tests\Feature;

use App\Modules\Settings\App\Services\SettingService;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;
use Throwable;

/**
 * Until the install wizard's database step there is no panel database at all
 * (install.sh creates none), yet the wizard's own pages read settings. Before
 * install a read therefore falls back to the default; after install the same
 * failure is an outage and must surface, not silently reset every setting.
 *
 * Deliberately not RefreshDatabase: these tests point the default connection
 * at a port nothing listens on, and there is no database to refresh.
 */
class SettingServiceBeforeInstallTest extends TestCase
{
    private ?string $defaultConnection = null;

    protected function tearDown(): void
    {
        if ($this->defaultConnection !== null) {
            config(['database.default' => $this->defaultConnection]);
        }

        parent::tearDown();
    }

    private function pointDefaultConnectionAtNothing(): void
    {
        $this->defaultConnection = config('database.default');

        config([
            'database.connections.nowhere' => [
                'driver' => 'mysql',
                'host' => '127.0.0.1',
                'port' => 1,
                'database' => 'nowhere',
                'username' => 'nobody',
                'password' => '',
            ],
            'database.default' => 'nowhere',
        ]);
        DB::purge('nowhere');
    }

    public function test_a_read_falls_back_to_the_default_before_install(): void
    {
        config(['app.installed' => false]);
        $this->pointDefaultConnectionAtNothing();

        $this->assertSame(
            config('settings.defaults.site_name'),
            app(SettingService::class)->get('site_name'),
        );
    }

    public function test_a_database_failure_is_not_hidden_once_installed(): void
    {
        config(['app.installed' => true]);
        $this->pointDefaultConnectionAtNothing();

        $this->expectException(Throwable::class);

        app(SettingService::class)->get('site_name');
    }
}
