<?php

namespace Tests\Feature;

use App\Models\User;
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

        // phpunit.xml sets INSTALLED=true globally (this file also covers
        // Settings > Backup download, which must run against an *installed*
        // panel). Only the restore-backup tests below need to flip this -
        // see InstallTest's setUp() for the same distinction.
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
     * @param  array<string, mixed>  $manifestOverrides
     */
    private function buildBackupZip(array $manifestOverrides = []): UploadedFile
    {
        $path = app(PanelBackup::class)->create();

        if ($manifestOverrides !== []) {
            $zip = new ZipArchive();
            $zip->open($path);
            $manifest = json_decode((string) $zip->getFromName('manifest.json'), true);
            $manifest = array_replace_recursive($manifest, $manifestOverrides);
            $zip->deleteName('manifest.json');
            $zip->addFromString('manifest.json', json_encode($manifest));
            $zip->close();
        }

        return new UploadedFile($path, 'backup.zip', 'application/zip', null, true);
    }

    public function test_create_produces_a_zip_with_manifest_and_table_data(): void
    {
        app(SettingService::class)->set('site_name', 'My Backed Up Panel');

        $path = app(PanelBackup::class)->create();
        $this->assertFileExists($path);

        $zip = new ZipArchive();
        $this->assertTrue($zip->open($path) === true);

        $manifest = json_decode((string) $zip->getFromName('manifest.json'), true);
        $this->assertSame(PanelBackup::FORMAT_VERSION, $manifest['version']);
        $this->assertArrayHasKey('panel', $manifest['database']);
        $this->assertArrayHasKey('swiftly', $manifest['database']);

        $settingsRows = json_decode((string) $zip->getFromName('data/settings.json'), true);
        $this->assertContains('site_name', array_column($settingsRows, 'key'));

        $zip->close();
        @unlink($path);
    }

    public function test_create_includes_uploaded_logo(): void
    {
        $uploadDir = (string) config('settings.upload_path');
        @mkdir($uploadDir, 0755, true);
        file_put_contents($uploadDir.'/logo.png', 'fake-png-bytes');

        $path = app(PanelBackup::class)->create();

        $zip = new ZipArchive();
        $zip->open($path);
        $this->assertSame('fake-png-bytes', $zip->getFromName('uploads/logo.png'));
        $zip->close();
        @unlink($path);
    }

    public function test_backup_download_requires_owner(): void
    {
        $this->getJson('/api/settings/backup')->assertStatus(401);

        $this->actingAs(User::factory()->create())
            ->getJson('/api/settings/backup')
            ->assertStatus(403);
    }

    public function test_backup_download_streams_a_zip(): void
    {
        $response = $this->actingAs(User::factory()->owner()->create())
            ->get('/api/settings/backup');

        $response->assertOk();
        $this->assertStringContainsString('backup.zip', (string) $response->headers->get('content-disposition'));
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
