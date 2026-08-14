<?php

namespace App\Support;

use App\Modules\Install\App\Services\ConnectionProbe;
use App\Modules\Install\App\Services\EnvWriter;
use App\Modules\Settings\App\Services\SettingService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;
use ZipArchive;

/**
 * Builds and restores "backup.zip" — a portable snapshot of everything the
 * install wizard would otherwise ask for by hand (database connections,
 * Steam credentials, the owner's SteamID, module toggles) plus every table
 * the panel itself owns and its logo/favicon uploads.
 *
 * Deliberately NOT included: third-party plugin *code*. Restoring PHP from
 * an unauthenticated pre-install endpoint (see InstallLock — this runs
 * before any owner exists) would mean executing arbitrary code from
 * whatever .zip was uploaded first; installing a plugin is meant to stay a
 * conscious, owner-only action (see PluginManager). The backup still
 * records which plugins were installed (data/plugin_installs.json) purely
 * for the owner's reference — see pendingPlugins() in the restore summary.
 *
 * Format (version 1):
 *   manifest.json        – database/steam/owner/locale/module config
 *   data/{table}.json     – every row of a whitelisted panel-owned table
 *   uploads/**             – logo/favicon files (public_path('uploads'))
 */
class PanelBackup
{
    public const FORMAT_VERSION = 1;

    private const DB_CONNECTIONS = ['panel', 'swiftly', 'ranks', 'weaponskins', 'vip'];

    /**
     * Every table a built-in module owns. Kept as an explicit whitelist
     * (not "every table in the schema") so restore only ever inserts into
     * tables it knows the shape of — see restore()'s data import step.
     *
     * @var array<int, string>
     */
    private const TABLES = [
        'settings',
        'module_toggles',
        'plugin_installs',
        'panel_logs',
        'appeals',
        'webhooks',
        'webhook_deliveries',
        'rcon_settings',
        'reports',
        'report_replies',
        'server_stats',
        'health_checks',
        'notifications',
    ];

    public function __construct(
        private readonly SettingService $settings,
        private readonly ConnectionProbe $probe,
    ) {
    }

    /**
     * Builds backup.zip in a scratch directory and returns its path. The
     * caller is responsible for streaming/deleting it.
     */
    public function create(): string
    {
        $workDir = storage_path('app/backup-export/'.Str::random(20));
        File::ensureDirectoryExists($workDir);
        $zipPath = $workDir.'/backup.zip';

        $zip = new ZipArchive();

        if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('backup_zip_create_failed');
        }

        $zip->addFromString('manifest.json', json_encode($this->buildManifest(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        foreach (self::TABLES as $table) {
            $rows = $this->tableExists($table) ? DB::table($table)->get()->toArray() : [];
            $zip->addFromString("data/{$table}.json", json_encode($rows, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        }

        $uploadPath = (string) config('settings.upload_path');

        if (is_dir($uploadPath)) {
            foreach (File::allFiles($uploadPath) as $file) {
                $zip->addFile($file->getPathname(), 'uploads/'.$file->getRelativePathname());
            }
        }

        $zip->close();

        return $zipPath;
    }

    /**
     * Restores a full panel from an uploaded backup.zip during the install
     * wizard. Only reachable while INSTALLED is still false (InstallLock),
     * matching the rest of the installer's "first visitor configures the
     * panel" trust model.
     *
     * @return array{restored_tables: array<int, string>, pending_plugins: array<int, array<string, mixed>>, locale: string}
     */
    public function restore(UploadedFile $file): array
    {
        $workDir = storage_path('app/backup-restore/'.Str::random(20));
        $extractTo = $workDir.'/extracted';

        try {
            SafeZip::extract((string) $file->getRealPath(), $extractTo);

            $manifest = $this->readManifest($extractTo);
            $this->writeEnv($manifest);
            $this->probeConnections($manifest);

            Artisan::call('migrate', ['--force' => true]);

            $restoredTables = $this->importData($extractTo);
            $this->restoreUploads($extractTo);

            (new EnvWriter($this->envPath()))->set(['INSTALLED' => true]);

            return [
                'restored_tables' => $restoredTables,
                'pending_plugins' => $this->pendingPlugins(),
                'locale' => (string) ($manifest['locale'] ?? 'en'),
            ];
        } finally {
            File::deleteDirectory($workDir);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function buildManifest(): array
    {
        // Same exclusion list as InstallController::modules() - these are
        // always-on core plumbing, never installer/backup-selectable.
        $modules = collect(config('modules.modules', []))
            ->except(['auth', 'install', 'modules', 'plugins'])
            ->map(fn (array $module) => (bool) ($module['enabled'] ?? true))
            ->all();

        $database = [];

        foreach (self::DB_CONNECTIONS as $connection) {
            $config = (array) config("database.connections.{$connection}", []);
            $database[$connection] = [
                'host' => $config['host'] ?? '127.0.0.1',
                'port' => $config['port'] ?? 3306,
                'database' => $config['database'] ?? '',
                'username' => $config['username'] ?? 'root',
                'password' => $config['password'] ?? '',
            ];
        }

        return [
            'version' => self::FORMAT_VERSION,
            'created_at' => now()->toIso8601String(),
            'app_url' => config('app.url'),
            'locale' => $this->settings->get('default_locale') ?: config('app.locale'),
            'database' => $database,
            'steam' => [
                'api_key' => config('services.steam.api_key'),
                'client_id' => config('services.steam.client_id'),
                'client_secret' => config('services.steam.client_secret'),
                'callback_url' => config('services.steam.redirect'),
            ],
            'owner_steam_id' => config('app.owner_steam_id'),
            'modules' => $modules,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function readManifest(string $extractTo): array
    {
        $path = $extractTo.'/manifest.json';

        if (! File::exists($path)) {
            throw new PanelBackupException('backup_manifest_missing');
        }

        $manifest = json_decode((string) File::get($path), true);

        if (! is_array($manifest) || (int) ($manifest['version'] ?? 0) !== self::FORMAT_VERSION) {
            throw new PanelBackupException('backup_manifest_invalid');
        }

        foreach (self::DB_CONNECTIONS as $connection) {
            if (! isset($manifest['database'][$connection]) || ! is_array($manifest['database'][$connection])) {
                throw new PanelBackupException('backup_manifest_invalid');
            }
        }

        return $manifest;
    }

    /**
     * @param  array<string, mixed>  $manifest
     */
    private function writeEnv(array $manifest): void
    {
        $values = [];

        foreach (self::DB_CONNECTIONS as $connection) {
            $data = (array) $manifest['database'][$connection];
            $prefix = $connection === 'panel' ? 'DB_' : strtoupper($connection).'_DB_';

            $values[$prefix.'HOST'] = $data['host'] ?? '127.0.0.1';
            $values[$prefix.'PORT'] = $data['port'] ?? 3306;
            $values[$prefix.'DATABASE'] = $data['database'] ?? '';
            $values[$prefix.'USERNAME'] = $data['username'] ?? 'root';
            $values[$prefix.'PASSWORD'] = $data['password'] ?? '';
        }

        $values['DB_CONNECTION'] = 'panel';

        $steam = (array) ($manifest['steam'] ?? []);
        $values['STEAM_API_KEY'] = $steam['api_key'] ?? null;
        $values['STEAM_CLIENT_ID'] = $steam['client_id'] ?? null;
        $values['STEAM_CLIENT_SECRET'] = $steam['client_secret'] ?? null;
        $values['STEAM_CALLBACK_URL'] = $steam['callback_url'] ?? (config('app.url').'/api/auth/callback');
        $values['OWNER_STEAM_ID'] = $manifest['owner_steam_id'] ?? null;

        foreach ((array) ($manifest['modules'] ?? []) as $key => $enabled) {
            $values['MODULE_'.strtoupper((string) $key)] = (bool) $enabled;
        }

        (new EnvWriter($this->envPath()))->set($values);

        foreach (self::DB_CONNECTIONS as $connection) {
            $data = (array) $manifest['database'][$connection];
            config()->set("database.connections.{$connection}", [
                'driver' => 'mysql',
                'host' => $data['host'] ?? '127.0.0.1',
                'port' => (int) ($data['port'] ?? 3306),
                'database' => $data['database'] ?? '',
                'username' => $data['username'] ?? 'root',
                'password' => $data['password'] ?? '',
                'charset' => 'utf8mb4',
                'collation' => 'utf8mb4_unicode_ci',
                'prefix' => '',
                'strict' => true,
                'engine' => null,
            ]);
        }

        $locale = (string) ($manifest['locale'] ?? 'en');
        $this->settings->set('default_locale', $locale);
    }

    /**
     * @param  array<string, mixed>  $manifest
     */
    private function probeConnections(array $manifest): void
    {
        $failures = [];

        foreach (self::DB_CONNECTIONS as $connection) {
            DB::purge($connection);

            if (! $this->probe->isHealthy($connection)) {
                $failures[] = $connection;
            }
        }

        if ($failures !== []) {
            throw new PanelBackupException('database_connection_failed', ['connections' => $failures]);
        }
    }

    /**
     * @return array<int, string>
     */
    private function importData(string $extractTo): array
    {
        $restored = [];

        foreach (self::TABLES as $table) {
            $path = $extractTo."/data/{$table}.json";

            if (! File::exists($path) || ! $this->tableExists($table)) {
                continue;
            }

            $rows = json_decode((string) File::get($path), true);

            if (! is_array($rows)) {
                continue;
            }

            try {
                DB::table($table)->truncate();

                foreach (array_chunk($rows, 200) as $chunk) {
                    if ($chunk !== []) {
                        DB::table($table)->insert($chunk);
                    }
                }

                $restored[] = $table;
            } catch (Throwable) {
                // One malformed table must never abort the whole restore -
                // the owner still ends up with a working, if partial, panel.
            }
        }

        return $restored;
    }

    private function restoreUploads(string $extractTo): void
    {
        $source = $extractTo.'/uploads';

        if (! File::isDirectory($source)) {
            return;
        }

        $destination = (string) config('settings.upload_path');
        File::ensureDirectoryExists($destination);
        File::copyDirectory($source, $destination);
    }

    /**
     * Plugins recorded in the restored data need their code re-uploaded via
     * the Plugins tab (see class docblock) — surfaced to the frontend so
     * the owner knows exactly what to do next instead of silently ending
     * up with dead rows.
     *
     * @return array<int, array<string, mixed>>
     */
    private function pendingPlugins(): array
    {
        if (! $this->tableExists('plugin_installs')) {
            return [];
        }

        return DB::table('plugin_installs')
            ->select(['key', 'name', 'version'])
            ->get()
            ->map(fn ($row) => (array) $row)
            ->all();
    }

    private function tableExists(string $table): bool
    {
        try {
            return DB::getSchemaBuilder()->hasTable($table);
        } catch (Throwable) {
            return false;
        }
    }

    private function envPath(): string
    {
        return (string) config('install.env_path', base_path('.env'));
    }
}
