<?php

namespace Tests\Feature;

use App\Modules\Install\App\Services\ConnectionProbe;
use App\Modules\Settings\App\Services\SettingService;
use App\Support\PanelBackup;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;
use ZipArchive;

class PanelBackupTest extends TestCase
{
    use RefreshDatabase;

    private string $envFile;

    protected function setUp(): void
    {
        // See InstallTest::setUp()'s comment for the full explanation: the
        // restore-backup route lives under the Install module, which
        // phpunit.xml's suite-wide INSTALLED=true computes as disabled at
        // boot (config/modules.php reads raw env('INSTALLED'), not
        // config('app.installed')) - every test below calling
        // config()->set('app.installed', false) only ever undid the
        // per-request half of that, never the route registration itself.
        // The raw env vars have to be overridden before parent::setUp()
        // boots the app; tearDown() restores them for later test classes.
        putenv('INSTALLED=false');
        $_ENV['INSTALLED'] = 'false';
        $_SERVER['INSTALLED'] = 'false';

        parent::setUp();

        // Same isolation as SettingsTest/InstallTest - never touch the real
        // public web root or the real .env from a test run.
        config()->set('settings.upload_path', storage_path('framework/testing/uploads'));
        $this->envFile = tempnam(sys_get_temp_dir(), 's2panel_env_');
        config()->set('install.env_path', $this->envFile);
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

    /**
     * Hand-builds a backup.zip in exactly the format PanelBackup::restore()
     * expects. There is no export path in the panel itself to generate one
     * from any more (Settings > Backup was removed) - restore is the only
     * half of this format that still exists in production code, so the
     * fixture for it now lives here instead.
     *
     * @param  array<string, mixed>  $manifestOverrides
     */
    private function buildBackupZip(array $manifestOverrides = []): UploadedFile
    {
        $manifest = array_replace_recursive([
            'version' => PanelBackup::FORMAT_VERSION,
            'created_at' => now()->toIso8601String(),
            'app_url' => config('app.url'),
            'locale' => 'en',
            'database' => [
                'panel' => ['host' => '127.0.0.1', 'port' => 3306, 'database' => 'panel', 'username' => 'root', 'password' => ''],
                'swiftly' => ['host' => '127.0.0.1', 'port' => 3306, 'database' => 'swiftly', 'username' => 'root', 'password' => ''],
                'ranks' => ['host' => '127.0.0.1', 'port' => 3306, 'database' => 'ranks', 'username' => 'root', 'password' => ''],
                'weaponskins' => ['host' => '127.0.0.1', 'port' => 3306, 'database' => 'weaponskins', 'username' => 'root', 'password' => ''],
                'vip' => ['host' => '127.0.0.1', 'port' => 3306, 'database' => 'vip', 'username' => 'root', 'password' => ''],
            ],
            'steam' => ['api_key' => 'test-key', 'client_id' => null, 'client_secret' => null, 'callback_url' => null],
            'owner_steam_id' => '76561198000000000',
            'modules' => [],
        ], $manifestOverrides);

        $path = tempnam(sys_get_temp_dir(), 'backup_zip_');
        $zip = new ZipArchive();
        $zip->open($path, ZipArchive::OVERWRITE);
        $zip->addFromString('manifest.json', json_encode($manifest));

        // The `settings` table's `value` column is a raw JSON-encoded string
        // under the hood (Setting model casts it 'json' on the Eloquent
        // side) - importData() below inserts these rows with the query
        // builder directly, bypassing that cast, so each value has to
        // already be pre-encoded exactly as it would sit in the column.
        $settingsRows = app(SettingService::class)->all();
        $zip->addFromString('data/settings.json', json_encode(
            collect($settingsRows)->map(fn ($value, $key) => ['key' => $key, 'value' => json_encode($value)])->values()
        ));

        // Whatever plugin_installs currently holds (often nothing) - lets
        // test_restore_replaces_the_whole_wizard_on_success assert this
        // table actually came back from the archive, not merely that a row
        // inserted straight into the pre-restore DB happened to survive
        // untouched because the zip never mentioned this table at all.
        $zip->addFromString('data/plugin_installs.json', json_encode(
            DB::table('plugin_installs')->get()->map(fn ($row) => (array) $row)->values()
        ));

        $zip->close();

        return new UploadedFile($path, 'backup.zip', 'application/zip', null, true);
    }

    public function test_restore_requires_a_file(): void
    {
        config()->set('app.installed', false);

        $this->postJson('/api/install/restore-backup', [])
            ->assertStatus(422)
            ->assertJsonPath('message', 'validation_failed');
    }

    public function test_restore_rejects_an_invalid_zip(): void
    {
        config()->set('app.installed', false);

        $garbage = tempnam(sys_get_temp_dir(), 'not_a_zip_');
        file_put_contents($garbage, 'not actually a zip file');
        $file = new UploadedFile($garbage, 'backup.zip', 'application/zip', null, true);

        $this->post('/api/install/restore-backup', ['backup' => $file])
            ->assertStatus(422)
            ->assertJsonPath('message', 'invalid_zip_file');
    }

    public function test_restore_rejects_a_zip_without_a_manifest(): void
    {
        config()->set('app.installed', false);

        $emptyZipPath = tempnam(sys_get_temp_dir(), 'empty_zip_');
        $zip = new ZipArchive();
        $zip->open($emptyZipPath, ZipArchive::OVERWRITE);
        $zip->addFromString('readme.txt', 'nothing to see here');
        $zip->close();
        $file = new UploadedFile($emptyZipPath, 'backup.zip', 'application/zip', null, true);

        $this->post('/api/install/restore-backup', ['backup' => $file])
            ->assertStatus(422)
            ->assertJsonPath('message', 'backup_manifest_missing');
    }

    public function test_restore_rejects_an_incompatible_manifest_version(): void
    {
        config()->set('app.installed', false);

        $file = $this->buildBackupZip(['version' => 999]);

        $this->post('/api/install/restore-backup', ['backup' => $file])
            ->assertStatus(422)
            ->assertJsonPath('message', 'backup_manifest_invalid');
    }

    public function test_restore_reports_unreachable_database_connections(): void
    {
        config()->set('app.installed', false);

        $this->mock(ConnectionProbe::class)->shouldReceive('isHealthy')->andReturn(false);

        $file = $this->buildBackupZip();

        $this->post('/api/install/restore-backup', ['backup' => $file])
            ->assertStatus(422)
            ->assertJsonPath('message', 'database_connection_failed')
            ->assertJsonPath('errors.connections', ['panel', 'swiftly', 'ranks', 'weaponskins', 'vip']);

        $this->assertStringNotContainsString('INSTALLED=true', $this->envContents());
    }

    public function test_restore_replaces_the_whole_wizard_on_success(): void
    {
        config()->set('app.installed', false);

        app(SettingService::class)->set('site_name', 'Original Name');
        DB::table('plugin_installs')->insert([
            'key' => 'sampleplug',
            'name' => 'Sample Plugin',
            'version' => '1.0.0',
            'provider_class' => 'App\\Modules\\Sampleplug\\SampleplugServiceProvider',
            'enabled' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $file = $this->buildBackupZip([
            'owner_steam_id' => '76561198000000123',
            'database' => ['panel' => ['database' => 'restored_panel_db']],
        ]);

        $this->mock(ConnectionProbe::class)->shouldReceive('isHealthy')->andReturn(true);

        $response = $this->post('/api/install/restore-backup', ['backup' => $file]);

        $response->assertOk()
            ->assertJsonPath('data.locale', 'en');

        $restoredTables = $response->json('data.restored_tables');
        $this->assertContains('settings', $restoredTables);
        $this->assertContains('plugin_installs', $restoredTables);

        $pendingPlugins = $response->json('data.pending_plugins');
        $this->assertSame('sampleplug', $pendingPlugins[0]['key']);

        $contents = $this->envContents();
        $this->assertStringContainsString('INSTALLED=true', $contents);
        $this->assertStringContainsString('OWNER_STEAM_ID=76561198000000123', $contents);
        $this->assertStringContainsString('DB_DATABASE=restored_panel_db', $contents);

        $this->assertSame('Original Name', app(SettingService::class)->get('site_name'));
    }

    /**
     * restoreUploads() used to copy every entry under uploads/** verbatim
     * into public_path('uploads') - a "backup.zip" is reachable by anyone
     * who gets to a freshly deployed panel first (this runs pre-owner), so
     * an uploads/shell.php entry would have planted a web shell in the
     * public web root. It must now be held to the exact same whitelist the
     * panel's own logo/favicon upload endpoint enforces.
     */
    public function test_restore_rejects_disallowed_upload_entries(): void
    {
        config()->set('app.installed', false);

        $manifest = [
            'version' => PanelBackup::FORMAT_VERSION,
            'database' => [
                'panel' => ['host' => '127.0.0.1', 'port' => 3306, 'database' => 'panel', 'username' => 'root', 'password' => ''],
                'swiftly' => ['host' => '127.0.0.1', 'port' => 3306, 'database' => 'swiftly', 'username' => 'root', 'password' => ''],
                'ranks' => ['host' => '127.0.0.1', 'port' => 3306, 'database' => 'ranks', 'username' => 'root', 'password' => ''],
                'weaponskins' => ['host' => '127.0.0.1', 'port' => 3306, 'database' => 'weaponskins', 'username' => 'root', 'password' => ''],
                'vip' => ['host' => '127.0.0.1', 'port' => 3306, 'database' => 'vip', 'username' => 'root', 'password' => ''],
            ],
            'owner_steam_id' => '76561198000000000',
        ];

        $path = tempnam(sys_get_temp_dir(), 'backup_zip_');
        $zip = new ZipArchive();
        $zip->open($path, ZipArchive::OVERWRITE);
        $zip->addFromString('manifest.json', json_encode($manifest));
        $zip->addFromString('uploads/shell.php', '<?php echo "pwned"; ?>');
        $zip->addFromString('uploads/logo.png', 'fake-png-bytes');
        $zip->close();

        $file = new UploadedFile($path, 'backup.zip', 'application/zip', null, true);

        $this->mock(ConnectionProbe::class)->shouldReceive('isHealthy')->andReturn(true);

        $response = $this->post('/api/install/restore-backup', ['backup' => $file]);

        $response->assertOk()
            ->assertJsonPath('data.skipped_uploads', ['shell.php']);

        $uploadPath = (string) config('settings.upload_path');
        $this->assertFileDoesNotExist($uploadPath.'/shell.php');
        $this->assertFileExists($uploadPath.'/logo.png');
    }

    /**
     * rcon_settings.password/webhooks.url are encrypted with APP_KEY. This
     * backup format has no export path of its own (see PanelBackup's
     * docblock) - an archive built from a different install's DB dump
     * carries ciphertext encrypted under that install's key, which this
     * one's APP_KEY cannot decrypt. Restoring it verbatim would sit fine
     * until the first read, then throw. It must be caught and cleared here.
     */
    public function test_restore_clears_a_secret_it_cannot_decrypt_with_this_installs_key(): void
    {
        config()->set('app.installed', false);

        $manifest = [
            'version' => PanelBackup::FORMAT_VERSION,
            'database' => [
                'panel' => ['host' => '127.0.0.1', 'port' => 3306, 'database' => 'panel', 'username' => 'root', 'password' => ''],
                'swiftly' => ['host' => '127.0.0.1', 'port' => 3306, 'database' => 'swiftly', 'username' => 'root', 'password' => ''],
                'ranks' => ['host' => '127.0.0.1', 'port' => 3306, 'database' => 'ranks', 'username' => 'root', 'password' => ''],
                'weaponskins' => ['host' => '127.0.0.1', 'port' => 3306, 'database' => 'weaponskins', 'username' => 'root', 'password' => ''],
                'vip' => ['host' => '127.0.0.1', 'port' => 3306, 'database' => 'vip', 'username' => 'root', 'password' => ''],
            ],
            'owner_steam_id' => '76561198000000000',
        ];

        $path = tempnam(sys_get_temp_dir(), 'backup_zip_');
        $zip = new ZipArchive();
        $zip->open($path, ZipArchive::OVERWRITE);
        $zip->addFromString('manifest.json', json_encode($manifest));
        $zip->addFromString('data/rcon_settings.json', json_encode([
            // Ciphertext from a run with a different APP_KEY - opaque
            // gibberish to this install, standing in for "some other
            // install's export". A real payload is base64 JSON, but any
            // string this APP_KEY cannot decrypt exercises the same path.
            ['id' => 1, 'server_id' => 1, 'password' => 'not-valid-ciphertext'],
        ]));
        $zip->close();

        $file = new UploadedFile($path, 'backup.zip', 'application/zip', null, true);

        $this->mock(ConnectionProbe::class)->shouldReceive('isHealthy')->andReturn(true);

        $response = $this->post('/api/install/restore-backup', ['backup' => $file]);

        $response->assertOk()
            ->assertJsonPath('data.secrets_cleared', ['rcon_settings#1']);

        // The row is dropped entirely, not inserted with a blank password:
        // that column is NOT NULL, and a password-less RCON entry cannot do
        // anything anyway - the owner recreates it instead.
        $this->assertSame(0, DB::table('rcon_settings')->count());
    }
}
