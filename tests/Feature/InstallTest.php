<?php

namespace Tests\Feature;

use App\Modules\I18n\App\Http\Controllers\I18nController;
use App\Modules\Install\App\Services\ConnectionProbe;
use App\Modules\Install\App\Services\PanelDatabase;
use App\Modules\Settings\App\Services\SettingService;
use App\Support\SteamId;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Mockery\MockInterface;
use Tests\Support\AssertsAlpineIntegrity;
use Tests\TestCase;

class InstallTest extends TestCase
{
    use AssertsAlpineIntegrity;
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

    /**
     * The whole wizard is one x-data attribute, so a stray double quote in it
     * breaks every step while the page still returns 200. PageScriptsTest
     * sweeps every page of an installed panel and so can never reach this
     * one; it only exists before install. Checked in every locale, since
     * what gets rendered into the attribute differs per translation.
     */
    public function test_install_page_alpine_survives_every_locale(): void
    {
        foreach (I18nController::locales() as $locale) {
            $html = $this->withSession(['locale' => $locale])->get('/install')->assertOk()->getContent();

            $this->assertAlpineIntact("/install [{$locale}]", $html);
        }
    }

    /**
     * The other way this page breaks, which the rendered check above cannot
     * see: a translation echoed into a JS string literal as '{{ __(...) }}'.
     * Blade escapes the apostrophe to &#039;, which keeps the HTML attribute
     * perfectly intact - and then the HTML parser decodes it back to ' before
     * Alpine reads the expression, ending the JS string early. So the markup
     * is clean and the script is broken, in exactly the locales whose text
     * has an apostrophe (English "application's", French "d'une", Italian
     * "un'altra"). Verified: a single such literal passes the rendered check
     * in every locale. @js() emits a literal with the quotes escaped, which
     * is the only safe way to put a translation there - so the source is
     * checked for the unsafe form instead.
     */
    public function test_install_page_puts_no_translation_in_a_quoted_js_literal(): void
    {
        $source = (string) file_get_contents(resource_path('views/install/index.blade.php'));

        $this->assertDoesNotMatchRegularExpression(
            "/'\\{\\{\\s*__\\(/",
            $source,
            "install/index.blade.php echoes a translation inside '...' in its x-data - use @js(__(...)) instead",
        );
    }

    /**
     * The premise of the whole install flow: install.sh creates no database,
     * so the wizard has to load - and the steps before the database one have
     * to work - with no database reachable at all. Settings reads (site name,
     * favicon, brand colour, default locale) and SetLocale's table check are
     * the pieces that used to assume one.
     */
    public function test_wizard_loads_and_takes_the_language_step_with_no_database(): void
    {
        $default = config('database.default');

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

        try {
            $this->get('/install')->assertOk();
            $this->getJson('/api/install/status')->assertOk();
            $this->postJson('/api/install/locale', ['locale' => 'tr', 'site_name' => 'X'])->assertOk();
        } finally {
            // RefreshDatabase rolls back "the default connection" at teardown
            // and resolves that name then, not now - leaving it on nowhere
            // would fail the teardown rather than this test.
            config(['database.default' => $default]);
        }
    }

    public function test_locale_requires_a_supported_value(): void
    {
        $this->postJson('/api/install/locale', ['locale' => 'xx'])
            ->assertStatus(422)
            ->assertJsonPath('message', 'validation_failed');
    }

    /**
     * The language step comes before the database step, and on a fresh
     * install there is no panel database yet - so it cannot write settings.
     * It holds both values in the session; the database step persists them
     * the moment it has created the settings table.
     */
    public function test_locale_step_holds_its_values_until_the_database_exists(): void
    {
        $this->postJson('/api/install/locale', ['locale' => 'tr', 'site_name' => 'Anatolia CS'])
            ->assertOk()
            ->assertJsonPath('data.locale', 'tr');

        $this->assertSame('tr', session('locale'));
        $this->assertDatabaseMissing('settings', ['key' => 'default_locale']);
        $this->assertDatabaseMissing('settings', ['key' => 'site_name']);

        $this->fakeUsableDatabase();
        $this->postJson('/api/install/database', $this->databasePayload('cs2_plugins'))->assertOk();

        $settings = app(SettingService::class);
        $this->assertSame('tr', $settings->get('default_locale'));
        $this->assertSame('Anatolia CS', $settings->get('site_name'));
    }

    public function test_database_validates_required_fields(): void
    {
        $this->postJson('/api/install/database', [])
            ->assertStatus(422)
            ->assertJsonPath('message', 'validation_failed');
    }

    /**
     * Probe mocked (no real server) and the table creation mocked (the test
     * suite's own default connection is sqlite, and the credentials below
     * point at no real MySQL) - what is under test is what the step decides
     * and writes, not Laravel's migrator.
     *
     * @param  array<int, string>  $foreignMigrations
     */
    private function fakeUsableDatabase(array $foreignMigrations = []): MockInterface
    {
        $this->mock(ConnectionProbe::class)
            ->shouldReceive('isHealthy')
            ->andReturn(true);

        return $this->mock(PanelDatabase::class, function (MockInterface $mock) use ($foreignMigrations): void {
            $mock->shouldReceive('foreignMigrations')->andReturn($foreignMigrations);
            // Allowed unless a test says otherwise - individual tests
            // override this with once()/never()/andThrow().
            $mock->shouldReceive('migrate')->byDefault();
        });
    }

    /**
     * @return array{connection: array<string, mixed>}
     */
    private function databasePayload(string $database): array
    {
        return [
            'connection' => [
                'host' => '10.0.0.9',
                'port' => 3307,
                'database' => $database,
                'username' => 'cs2_user',
                'password' => 'cs2_pass',
            ],
        ];
    }

    /**
     * One database for everything: the panel's own connection and all four
     * plugin connections are pointed at what was typed, and the panel's
     * tables are created there - there is no other database for them.
     */
    public function test_database_step_creates_the_panels_tables_in_the_given_database(): void
    {
        $this->fakeUsableDatabase()
            ->shouldReceive('migrate')->once()->with('panel');

        $this->postJson('/api/install/database', $this->databasePayload('cs2_plugins'))
            ->assertOk()
            ->assertJsonPath('meta.connections', ['panel', 'swiftly', 'ranks', 'weaponskins', 'vip']);

        $contents = $this->envContents();

        // Anchored: SWIFTLY_DB_DATABASE=... ends with the same text, so a
        // bare substring check would pass even if DB_DATABASE were missing.
        $this->assertMatchesRegularExpression('/^DB_CONNECTION=panel\r?$/m', $contents);
        $this->assertMatchesRegularExpression('/^DB_HOST=10\.0\.0\.9\r?$/m', $contents);
        $this->assertMatchesRegularExpression('/^DB_PORT=3307\r?$/m', $contents);
        $this->assertMatchesRegularExpression('/^DB_DATABASE=cs2_plugins\r?$/m', $contents);
        $this->assertMatchesRegularExpression('/^DB_USERNAME=cs2_user\r?$/m', $contents);

        foreach (['SWIFTLY', 'RANKS', 'WEAPONSKINS', 'VIP'] as $plugin) {
            $this->assertMatchesRegularExpression("/^{$plugin}_DB_DATABASE=cs2_plugins\r?$/m", $contents);
        }
    }

    /**
     * A database another web app has already migrated into - an older panel,
     * as found on a real install - is refused before anything is created or
     * written, and the operator is told which migrations are in the way.
     */
    public function test_database_step_refuses_a_database_holding_another_apps_migrations(): void
    {
        $this->fakeUsableDatabase(['2024_05_04_142211_create_all_tables', '2014_10_12_000000_create_users_table'])
            ->shouldNotReceive('migrate');

        $this->postJson('/api/install/database', $this->databasePayload('cs2_plugins'))
            ->assertStatus(422)
            ->assertJsonPath('message', 'database_in_use_by_another_app')
            ->assertJsonPath('errors.migrations.0', '2024_05_04_142211_create_all_tables');

        $this->assertSame('', $this->envContents());
    }

    /**
     * Tables are created before anything is written to .env, so a failure -
     * a missing CREATE privilege, a clashing table name - leaves the wizard
     * exactly where it was and shows the database's own reason.
     */
    public function test_a_failed_migration_leaves_env_untouched_and_reports_why(): void
    {
        $this->fakeUsableDatabase()
            ->shouldReceive('migrate')
            ->andThrow(new \RuntimeException("SQLSTATE[42S01]: Base table or view already exists: 1050 Table 'users' already exists"));

        $this->postJson('/api/install/database', $this->databasePayload('cs2_plugins'))
            ->assertStatus(422)
            ->assertJsonPath('message', 'panel_migration_failed')
            ->assertJsonFragment(['reason' => ["SQLSTATE[42S01]: Base table or view already exists: 1050 Table 'users' already exists"]]);

        $this->assertSame('', $this->envContents());
        $this->assertDatabaseMissing('settings', ['key' => 'admin_plugin']);
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

    /**
     * The owner field takes what an owner can actually find - the link in
     * their profile's address bar - and stores the SteamID64 it points at,
     * so every ownership check downstream keeps seeing the same thing.
     */
    public function test_steam_step_accepts_the_owners_profile_link(): void
    {
        $this->postJson('/api/install/steam', [
            'api_key' => 'ABC123',
            'owner_steam_id' => 'https://steamcommunity.com/profiles/76561198000000042/',
        ])->assertOk();

        $this->assertMatchesRegularExpression('/^OWNER_STEAM_ID=76561198000000042\r?$/m', $this->envContents());
    }

    /**
     * A custom /id/ link carries no ID, so it is looked up through Steam with
     * the key from the same form - and a key Steam refuses is reported as
     * such, not as a bad profile link.
     */
    public function test_steam_step_resolves_a_custom_link_and_reports_a_rejected_key(): void
    {
        Http::fake([
            'api.steampowered.com/*' => Http::sequence()
                ->push(['response' => ['success' => 1, 'steamid' => '76561198000000077']])
                ->push('Forbidden', 403),
        ]);

        $this->postJson('/api/install/steam', [
            'api_key' => 'GOODKEY',
            'owner_steam_id' => 'https://steamcommunity.com/id/anatolia_owner',
        ])->assertOk();

        $this->assertMatchesRegularExpression('/^OWNER_STEAM_ID=76561198000000077\r?$/m', $this->envContents());

        $this->postJson('/api/install/steam', [
            'api_key' => 'BADKEY',
            'owner_steam_id' => 'https://steamcommunity.com/id/anatolia_owner',
        ])
            ->assertStatus(422)
            ->assertJsonPath('errors.owner_steam_id', 'steam_api_key_rejected');
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
     * A fresh install runs its sessions and cache on files, because there is
     * no panel database until the wizard's database step. Finishing moves
     * both onto the database, which is what an installed panel runs on - a
     * root cron `schedule:run` writing file cache the web user cannot then
     * overwrite is the classic way the file setup goes wrong later.
     */
    public function test_complete_moves_file_sessions_and_cache_to_the_database(): void
    {
        config(['session.driver' => 'file', 'cache.default' => 'file']);

        $this->postJson('/api/install/complete')->assertOk();

        $contents = $this->envContents();
        $this->assertMatchesRegularExpression('/^SESSION_DRIVER=database\r?$/m', $contents);
        $this->assertMatchesRegularExpression('/^CACHE_STORE=database\r?$/m', $contents);
    }

    /**
     * Only the pre-install file default is promoted - anything else was
     * chosen on purpose. (array rather than redis here: the test request
     * itself runs on whatever driver this sets, and redis would need a
     * server.)
     */
    public function test_complete_leaves_a_non_file_driver_alone(): void
    {
        config(['session.driver' => 'array', 'cache.default' => 'array']);

        $this->postJson('/api/install/complete')->assertOk();

        $contents = $this->envContents();
        $this->assertStringNotContainsString('SESSION_DRIVER', $contents);
        $this->assertStringNotContainsString('CACHE_STORE', $contents);
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