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
}
