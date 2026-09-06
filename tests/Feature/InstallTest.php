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
        parent::setUp();

        // Point the installer at a throw-away env file so the real .env
        // is never touched by tests.
        $this->envFile = tempnam(sys_get_temp_dir(), 's2panel_env_');
        config()->set('install.env_path', $this->envFile);

        // phpunit.xml sets INSTALLED=true globally (most of the suite tests
        // an already-installed panel). This suite exercises the installer
        // itself, so it must run against an uninstalled panel - otherwise
        // InstallLock correctly 404s every /api/install/* route (see
        // test_install_routes_are_unreachable_once_installed below, which
        // overrides this back to true on purpose).
        config()->set('app.installed', false);
    }

    protected function tearDown(): void
    {
        @unlink($this->envFile);
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

    public function test_database_writes_credentials_when_connections_are_reachable(): void
    {
        // The controller probes each connection through ConnectionProbe; in
        // tests the probe is mocked so no real database is ever touched.
        $this->mock(ConnectionProbe::class)
            ->shouldReceive('isHealthy')
            ->andReturn(true);

        // The submitted payload still has to be structurally valid.
        $payload = [];
        foreach (['panel', 'swiftly', 'ranks', 'weaponskins', 'vip'] as $connection) {
            $payload[$connection] = [
                'host' => '127.0.0.1',
                'port' => 3306,
                'database' => "db_{$connection}",
                'username' => 'root',
                'password' => 'secret',
            ];
        }

        $this->postJson('/api/install/database', $payload)
            ->assertOk()
            ->assertJsonPath('meta.connections', ['panel', 'swiftly', 'ranks', 'weaponskins', 'vip']);

        $contents = $this->envContents();

        $this->assertStringContainsString('DB_CONNECTION=panel', $contents);
        $this->assertStringContainsString('DB_DATABASE=db_panel', $contents);
        $this->assertStringContainsString('SWIFTLY_DB_DATABASE=db_swiftly', $contents);
        $this->assertStringContainsString('RANKS_DB_DATABASE=db_ranks', $contents);
        $this->assertStringContainsString('WEAPONSKINS_DB_DATABASE=db_weaponskins', $contents);
        $this->assertStringContainsString('VIP_DB_DATABASE=db_vip', $contents);
    }

    public function test_steam_requires_valid_owner_steam_id(): void
    {
        $this->postJson('/api/install/steam', ['owner_steam_id' => 'nope'])
            ->assertStatus(422)
            ->assertJsonPath('errors.owner_steam_id', 'invalid_steam_id');
    }

    public function test_steam_writes_owner_and_steam_settings(): void
    {
        $ownerId = SteamId::parse('STEAM_0:1:1234567')->steamId64();

        $this->postJson('/api/install/steam', [
            'api_key' => 'ABC123',
            'client_id' => 'client',
            'client_secret' => 'secret',
            'callback_url' => 'https://panel.example.com/api/auth/callback',
            'owner_steam_id' => $ownerId,
        ])->assertOk();

        $contents = $this->envContents();

        $this->assertStringContainsString("OWNER_STEAM_ID={$ownerId}", $contents);
        $this->assertStringContainsString('STEAM_API_KEY=ABC123', $contents);
        $this->assertStringContainsString('STEAM_CALLBACK_URL=https://panel.example.com/api/auth/callback', $contents);
    }

    public function test_steam_defaults_callback_to_app_url(): void
    {
        config()->set('app.url', 'http://localhost:8000');

        $this->postJson('/api/install/steam', [
            'owner_steam_id' => SteamId::parse('STEAM_0:1:1234567')->steamId64(),
        ])->assertOk();

        $this->assertStringContainsString(
            'STEAM_CALLBACK_URL=http://localhost:8000/api/auth/callback',
            $this->envContents(),
        );
    }

    public function test_modules_writes_toggles_including_false(): void
    {
        $this->postJson('/api/install/modules', [
            'admin' => true,
            'ban' => true,
            'vip' => false,
        ])->assertOk();

        $contents = $this->envContents();

        $this->assertStringContainsString('MODULE_ADMIN=true', $contents);
        $this->assertStringContainsString('MODULE_BAN=true', $contents);
        $this->assertStringContainsString('MODULE_VIP=false', $contents);
        $this->assertStringNotContainsString('MODULE_AUTH', $contents);
        $this->assertStringNotContainsString('MODULE_INSTALL', $contents);
    }

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