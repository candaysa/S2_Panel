<?php

namespace App\Support;

use App\Modules\Install\App\Services\ConnectionProbe;
use App\Modules\Install\App\Services\EnvWriter;
use App\Modules\Install\App\Services\InstallFinaliser;
use App\Modules\Settings\App\Services\SettingService;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Throwable;

/**
 * Restores a panel from a "backup.zip" during the install wizard - a
 * portable snapshot of everything the wizard would otherwise ask for by
 * hand (database connections, Steam credentials, the owner's SteamID,
 * module toggles) plus every table the panel itself owns and its
 * logo/favicon uploads. There is no export path in the panel itself
 * (Settings > Backup was removed); a backup.zip in this format has to come
 * from wherever the install it is restoring from was backed up.
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

    /**
     * table => column whose value is encrypted at rest with APP_KEY (see
     * RconSetting/Webhook). This backup format has no export path of its
     * own (see class docblock) - whatever produced the archive dumped these
     * columns as raw ciphertext, encrypted under whatever APP_KEY that
     * install had. A fresh install generates its own APP_KEY, so restoring
     * that ciphertext verbatim here would not fail loudly at restore time -
     * it would sit in a column neither model treats as nullable until the
     * first read (opening RCON settings, sending a webhook), which throws a
     * DecryptException straight through Eloquent's own cast. Both columns
     * are also each row's entire reason to exist - a password-less RCON
     * entry or URL-less webhook cannot do anything either way - so
     * importData() below drops the row instead of inserting it blank, and
     * reports which ones so the owner knows to recreate them.
     *
     * @var array<string, string>
     */
    private const ENCRYPTED_COLUMNS = [
        'rcon_settings' => 'password',
        'webhooks' => 'url',
    ];

    public function __construct(
        private readonly SettingService $settings,
        private readonly ConnectionProbe $probe,
    ) {
    }

    /**
     * Restores a full panel from an uploaded backup.zip during the install
     * wizard. Only reachable while INSTALLED is still false (InstallLock),
     * matching the rest of the installer's "first visitor configures the
     * panel" trust model.
     *
     * @return array{restored_tables: array<int, string>, pending_plugins: array<int, array<string, mixed>>, locale: string, skipped_uploads: array<int, string>, secrets_cleared: array<int, string>}
     */
    public function restore(UploadedFile $file): array
    {
        $workDir = storage_path('app/backup-restore/'.Str::random(20));
        $extractTo = $workDir.'/extracted';

        // writeEnv() below overwrites the live .env before anything has
        // confirmed the credentials in the archive actually work, and the
        // steps after it (connection probe, migrate, import) are all
        // failable. Without this snapshot a restore that died on a bad DB
        // host left the panel pointed at those unusable credentials: the
        // wizard reported the error, but every request from then on booted
        // against a database that was never reachable, including the retry.
        // Keeping the previous file lets a failed restore leave the install
        // exactly as it found it.
        $envPath = $this->envPath();
        $envBefore = is_file($envPath) ? (string) File::get($envPath) : null;

        try {
            SafeZip::extract((string) $file->getRealPath(), $extractTo);

            $manifest = $this->readManifest($extractTo);
            $this->writeEnv($manifest);
            $this->probeConnections($manifest);

            Artisan::call('migrate', ['--force' => true]);

            // After migrate, not inside writeEnv() where it used to be: on a
            // fresh install there is no panel database until this restore
            // points at one, so the settings table does not exist before the
            // line above. It still lands before importData() on purpose -
            // that replaces the settings table wholesale, so a locale the
            // backup's own settings carry wins over this manifest default.
            $this->settings->set('default_locale', (string) ($manifest['locale'] ?? 'en'));

            [$restoredTables, $secretsCleared] = $this->importData($extractTo);
            $skippedUploads = $this->restoreUploads($extractTo);

            app(InstallFinaliser::class)->markInstalled($envPath);

            return [
                'restored_tables' => $restoredTables,
                'pending_plugins' => $this->pendingPlugins(),
                'locale' => (string) ($manifest['locale'] ?? 'en'),
                'skipped_uploads' => $skippedUploads,
                // Rows dropped entirely because their secret was encrypted
                // under a different install's APP_KEY and could not be
                // decrypted with this one - "table#id", e.g.
                // "rcon_settings#3". The owner needs to recreate these.
                'secrets_cleared' => $secretsCleared,
            ];
        } catch (Throwable $e) {
            // Only the env file is rolled back. Rows already imported are
            // left alone deliberately: the panel is still uninstalled
            // (INSTALLED is written last, on success only), so the wizard
            // is reachable and a retry re-imports over them - whereas
            // restoring the previous .env is what keeps that retry able to
            // boot at all.
            if ($envBefore !== null) {
                File::put($envPath, $envBefore);
            } else {
                File::delete($envPath);
            }

            throw $e;
        } finally {
            File::deleteDirectory($workDir);
        }
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
     * @return array{0: array<int, string>, 1: array<int, string>} [restored table names, "table#id" rows dropped because their encrypted secret did not survive the move]
     */
    private function importData(string $extractTo): array
    {
        $restored = [];
        $secretsCleared = [];

        foreach (self::TABLES as $table) {
            $path = $extractTo."/data/{$table}.json";

            if (! File::exists($path) || ! $this->tableExists($table)) {
                continue;
            }

            $rows = json_decode((string) File::get($path), true);

            if (! is_array($rows)) {
                continue;
            }

            if ($column = self::ENCRYPTED_COLUMNS[$table] ?? null) {
                $rows = array_values(array_filter($rows, function (array $row) use ($table, $column, &$secretsCleared): bool {
                    if (! array_key_exists($column, $row) || $row[$column] === null) {
                        return true;
                    }

                    try {
                        Crypt::decryptString((string) $row[$column]);

                        return true;
                    } catch (DecryptException) {
                        $secretsCleared[] = $table.'#'.($row['id'] ?? '?');

                        return false;
                    }
                }));
            }

            try {
                // DELETE, not TRUNCATE: TRUNCATE is an implicit commit in
                // MySQL, so wrapping it in this transaction would not have
                // undone it anyway. A chunk failing partway through used to
                // leave the table wiped down to just its earlier chunks - a
                // table restore that fails now leaves that table exactly as
                // it was before this method touched it.
                DB::transaction(function () use ($table, $rows): void {
                    DB::table($table)->delete();

                    foreach (array_chunk($rows, 200) as $chunk) {
                        if ($chunk !== []) {
                            DB::table($table)->insert($chunk);
                        }
                    }
                });

                $restored[] = $table;
            } catch (Throwable) {
                // One malformed table must never abort the whole restore -
                // the owner still ends up with a working, if partial, panel
                // (and, per the transaction above, that table's own prior
                // data rather than a half-truncated version of it).
            }
        }

        return [$restored, $secretsCleared];
    }

    /**
     * The archive's uploads/ folder lands under public web root
     * (config('settings.upload_path') is public_path('uploads')) — and this
     * whole method runs before any owner exists (InstallLock), reachable by
     * whoever gets to a freshly deployed panel first. copyDirectory() used
     * to take every entry as-is; a "backup.zip" with uploads/shell.php would
     * plant it straight into the web root under its original name. The
     * panel's own logo/favicon upload endpoint (SettingsController::upload)
     * only ever accepts this same mime whitelist under a fixed logo.* /
     * favicon.* name - restoring holds it to exactly that, not "trust
     * whatever the zip contains", and skips (rather than silently drops)
     * anything else so the owner can see what did not come back.
     *
     * @return array<int, string> entry names that were present but rejected
     */
    private function restoreUploads(string $extractTo): array
    {
        $source = $extractTo.'/uploads';

        if (! File::isDirectory($source)) {
            return [];
        }

        $destination = (string) config('settings.upload_path');
        File::ensureDirectoryExists($destination);

        $skipped = [];

        foreach (File::files($source) as $file) {
            $name = $file->getFilename();

            if (preg_match('/^(logo|favicon)\.(png|jpe?g|webp|svg|ico)$/i', $name) !== 1) {
                $skipped[] = $name;

                continue;
            }

            File::copy($file->getPathname(), $destination.'/'.$name);
        }

        return $skipped;
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
