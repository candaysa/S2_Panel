<?php

namespace Tests\Feature;

use App\Modules\Install\App\Services\ConnectionProbe;
use App\Support\SteamId;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InstallTest extends TestCase
{
    use RefreshDatabase;

    private string $envFile;

    protected function setUp(): void
    {
        // phpunit.xml sets INSTALLED=true globally (env vars persist for
        // the whole PHPUnit process, not just this test - most of the
        // suite runs against an already-installed panel). config('app.
        // installed', false) below undoes that for InstallLock, which
        // checks it per-request - but config('modules.modules.install.
        // enabled') decides whether /api/install/* routes are registered
        // AT ALL, computed once from raw env('INSTALLED') when the app
        // boots (config/modules.php: 'enabled' => ! env('INSTALLED',
        // false)), before this method's own config()->set() calls ever
        // run. Left at its phpunit.xml default, every route in this suite
        // was simply never registered - a 404 that looked exactly like the
        // right behavior for the wrong reason. The raw env vars have to be
        // overridden before parent::setUp() boots the app; tearDown()
        // restores them so later test classes in this same process still
        // see the suite-wide default.
        putenv('INSTALLED=false');
        $_ENV['INSTALLED'] = 'false';
        $_SERVER['INSTALLED'] = 'false';

        parent::setUp();

        // Point the installer at a throw-away env file so the real .env
        // is never touched by tests.
        $this->envFile = tempnam(sys_get_temp_dir(), 's2panel_env_');
        config()->set('install.env_path', $this->envFile);

        // Per-request half of the above - see test_install_routes_are_
        // unreachable_once_installed below, which overrides this back to
        // true on purpose to exercise InstallLock's own runtime check.
        config()->set('app.installed', false);
    }

    protected function tearDown(): void
    {
        @unlink($this->envFile);

        putenv('INSTALLED=true');
        $_ENV['INSTALLED'] = 'true';
        $_SERVER['INSTALLED'] = 'true';

        parent::tearDown();
    }

    private function envContents(): string
    {
        return (string) file_get_contents($this->envFile);
    }

    public function test_status_reports_not_installed(): void
    {
        config()->set('app.installed', false);

        $this->getJson('/api/install/status')
            ->assertOk()
            ->assertJsonPath('data.installed', false);
    }

    public function test_install_page_renders_while_not_installed(): void
    {
        $this->get('/install')
            ->assertOk()
            ->assertSee('Panel Setup');
    }

    public function test_locale_requires_a_supported_value(): void
    {
        $this->postJson('/api/install/locale', ['locale' => 'xx'])
            ->assertStatus(422)
            ->assertJsonPath('message', 'validation_failed');
    }

    public function test_locale_sets_session_and_default_locale_setting(): void
    {
        $this->postJson('/api/install/locale', ['locale' => 'tr'])
            ->assertOk()
            ->assertJsonPath('data.locale', 'tr');

        $this->assertSame('tr', session('locale'));
        $this->assertSame('tr', app(\App\Modules\Settings\App\Services\SettingService::class)->get('default_locale'));
    }

    public function test_database_validates_required_fields(): void
    {
        $this->postJson('/api/install/database', [])
            ->assertStatus(422)
            ->assertJsonPath('message', 'validation_failed');
    }

    /**
     * The wizard now asks for one set of credentials and writes it to
     * every plugin connection (see InstallController::database() -
     * "the panel treats all Swiftly plugin data as living in one shared
     * database"), not five separate connection blocks. This used to submit
     * one payload per connection under that connection's own name
     * (`panel`, `swiftly`, ...), which the current single `connection.*`
     * validation rule set rejects outright as missing required fields.
     */
    public function test_database_writes_credentials_when_connections_are_reachable(): void
    {
        // The controller probes the one connection through ConnectionProbe;
        // in tests the probe is mocked so no real database is ever touched.
        $this->mock(ConnectionProbe::class)
            ->shouldReceive('isHealthy')
            ->andReturn(true);

        $payload = [
            'connection' => [
                'host' => '127.0.0.1',
                'port' => 3306,
                'database' => 'db_shared',
                'username' => 'root',
                'password' => 'secret',
            ],
        ];

        $this->postJson('/api/install/database', $payload)
            ->assertOk()
            ->assertJsonPath('meta.connections', ['panel', 'swiftly', 'ranks', 'weaponskins', 'vip']);

        $contents = $this->envContents();

        $this->assertStringContainsString('DB_CONNECTION=panel', $contents);
        $this->assertStringContainsString('DB_DATABASE=db_shared', $contents);
        $this->assertStringContainsString('SWIFTLY_DB_DATABASE=db_shared', $contents);
        $this->assertStringContainsString('RANKS_DB_DATABASE=db_shared', $contents);
        $this->assertStringContainsString('WEAPONSKINS_DB_DATABASE=db_shared', $contents);
        $this->assertStringContainsString('VIP_DB_DATABASE=db_shared', $contents);
    }

    public function test_steam_requires_valid_owner_steam_id(): void
    {
        // api_key is required too (Validator::make below) - omitting it
        // used to make this 422 for the wrong reason: the validator's own
        // "api_key is required" fired before the SteamId check this test
        // means to exercise ever ran, so errors.owner_steam_id was never
        // set at all.
        $this->postJson('/api/install/steam', ['api_key' => 'ABC123', 'owner_steam_id' => 'nope'])
            ->assertStatus(422)
            ->assertJsonPath('errors.owner_steam_id', 'invalid_steam_id');
    }

    /**
     * client_id/client_secret/callback_url are no longer accepted here -
     * see InstallController::steam()'s docblock ("two values, because
     * Steam OpenID 2.0 genuinely needs no more"). STEAM_CALLBACK_URL is now
     * an optional, install-time-unmanaged override read straight from .env
     * by config/services.php, defaulting to APP_URL there - not something
     * this endpoint writes at all.
     */
    public function test_steam_writes_owner_and_steam_settings(): void
    {
        $ownerId = SteamId::parse('STEAM_0:1:1234567')->steamId64();

        $this->postJson('/api/install/steam', [
            'api_key' => 'ABC123',
            'owner_steam_id' => $ownerId,
        ])->assertOk();

        $contents = $this->envContents();

        $this->assertStringContainsString("OWNER_STEAM_ID={$ownerId}", $contents);
        $this->assertStringContainsString('STEAM_API_KEY=ABC123', $contents);
    }

    // There used to be a POST /api/install/modules wizard step here - it no
    // longer exists (see routes/api.php: the current flow is locale ->
    // database -> servers/rcon -> steam -> complete). Module on/off is
    // owner-facing runtime state now (PUT /api/modules/{key} -
    // ModuleRegistry::setOverride(), covered by ModuleToggleTest), not
    // something the installer writes to .env. This test posted to a route
    // that has not existed for a while and, until the Install-module-
    // registration fix above, silently got a 404 indistinguishable from
    // every other test in this class - which is exactly how a real
    // regression here would have gone unnoticed too.

    public function test_complete_sets_installed_flag(): void
    {
        $this->postJson('/api/install/complete')
            ->assertOk()
            ->assertJsonPath('data.installed', true);

        $this->assertStringContainsString('INSTALLED=true', $this->envContents());
    }

    /**
     * Regression test for a critical access-control bug: InstallLock used
     * to let every request through as soon as "installed" was true, BEFORE
     * checking whether it targeted the installer routes. That left
     * /api/install/* (DB credentials, OWNER_STEAM_ID, module toggles)
     * reachable with no authentication on an already-installed panel.
     */
    public function test_install_routes_are_unreachable_once_installed(): void
    {
        config()->set('app.installed', true);
        config()->set('app.owner_steam_id', '76561198000000001');

        $this->getJson('/api/install/status')->assertStatus(404);

        $this->postJson('/api/install/steam', [
            'owner_steam_id' => '76561198999999999',
        ])->assertStatus(404);

        $this->assertStringNotContainsString('76561198999999999', $this->envContents());

        $this->postJson('/api/install/database', [])->assertStatus(404);
        $this->postJson('/api/install/modules', ['admin' => true])->assertStatus(404);
        $this->postJson('/api/install/complete')->assertStatus(404);
        $this->postJson('/api/install/locale', ['locale' => 'tr'])->assertStatus(404);
        $this->get('/install')->assertStatus(404);
    }

    public function test_install_routes_stay_reachable_while_not_installed(): void
    {
        config()->set('app.installed', false);

        $this->getJson('/api/install/status')->assertOk();
    }
}