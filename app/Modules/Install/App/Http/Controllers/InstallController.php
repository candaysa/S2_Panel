<?php

namespace App\Modules\Install\App\Http\Controllers;

use App\Modules\Install\App\Services\ConnectionProbe;
use App\Modules\Install\App\Services\DependencyProbe;
use App\Modules\Install\App\Services\EnvWriter;
use App\Modules\Rcon\App\Models\RconSetting;
use App\Modules\Server\App\Models\AdminServer;
use App\Modules\Settings\App\Services\SettingService;
use App\Support\Api;
use App\Support\PanelBackup;
use App\Support\PanelBackupException;
use App\Support\SteamId;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Validator;
use InvalidArgumentException;
use Throwable;

/**
 * Installation wizard (C13). Public endpoints – the InstallLock middleware
 * already exempts "api/install/*" while INSTALLED=false.
 *
 * Flow: status → locale (session + owner default) → database (probe +
 * persist) → steam/owner (validate + persist) → complete (INSTALLED=true).
 *
 * Alternative flow: restoreBackup() replaces every step above at once from
 * a previously-downloaded backup.zip (see Settings > Backup / PanelBackup).
 */
class InstallController
{
    private const CONNECTIONS = ['panel', 'swiftly', 'ranks', 'weaponskins', 'vip'];

    /**
     * Scratch connection name used only to validate submitted credentials.
     * Deliberately not one of self::CONNECTIONS - see probeCredentials().
     */
    private const PROBE_CONNECTION = 'install_probe';

    /**
     * How far the wizard has got, recorded in .env as each step commits.
     *
     * The step used to live only in the browser, so a refresh - or the reload
     * the language step performs - dropped the operator back to the beginning
     * with their database and Steam credentials already written. Progress is
     * server state because the thing it describes, .env, is server state.
     */
    private const STEP_KEY = 'INSTALL_STEP';

    private const STEP_LOCALE = 1;

    private const STEP_DATABASE = 2;

    private const STEP_RCON = 3;

    private const STEP_STEAM = 4;

    /**
     * Wizard screens, in order. Only the count is used server-side, to clamp
     * a restored step; the labels live in the view.
     *
     * Module selection is not among them. Which features are on is an ongoing
     * operational decision, not an installation one, and it already has a
     * proper home in the owner's Modules tab - asking during setup only forced
     * a choice before there was anything to base it on.
     */
    private const STEPS = ['locale', 'database', 'rcon', 'steam', 'complete'];

    public function __construct(
        private readonly ConnectionProbe $probe,
        private readonly DependencyProbe $dependencies,
    ) {
    }

    private function envPath(): string
    {
        return (string) config('install.env_path', base_path('.env'));
    }

    /**
     * GET /api/install/status
     */
    public function status(): JsonResponse
    {
        // step is 1-based and one ahead of the last committed step, so a
        // refresh returns to the screen the operator was on rather than the
        // one they already finished.
        $completed = (int) env(self::STEP_KEY, 0);

        return Api::success([
            'installed' => (bool) config('app.installed'),
            'app_url' => config('app.url'),
            'step' => min($completed + 1, count(self::STEPS)),
        ]);
    }

    /**
     * POST /api/install/locale
     *
     * Body: { locale }. Sets the session locale immediately (so the rest of
     * the wizard renders in the chosen language after the page reload the
     * frontend does right after this call succeeds) and persists it as the
     * panel's own default_locale for every visitor once installed.
     */
    public function locale(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'locale' => 'required|string|in:en,tr,de,fr,it,ru',
            // Asked here rather than left to a post-install trip to Settings:
            // the name appears in the sidebar, the browser tab and the PWA
            // manifest, so a fresh install that still says "S2 Panel"
            // everywhere looks unconfigured on first login.
            'site_name' => 'nullable|string|max:120',
        ]);

        if ($validator->fails()) {
            return Api::error(Api::MSG_VALIDATION_FAILED, $validator->errors()->toArray(), 422);
        }

        $locale = (string) $request->input('locale');

        $request->session()->put('locale', $locale);
        app(SettingService::class)->set('default_locale', $locale);

        $siteName = trim((string) $request->input('site_name', ''));

        if ($siteName !== '') {
            app(SettingService::class)->set('site_name', $siteName);
        }

        // Recorded like every other step. Without this a refresh right after
        // choosing a language restarted the wizard, because nothing on the
        // server knew the step had been completed.
        (new EnvWriter($this->envPath()))->set([self::STEP_KEY => self::STEP_LOCALE]);

        return Api::success(['locale' => $locale]);
    }

    /**
     * POST /api/install/database
     *
     * Body: { connection: {host, port, database, username, password} }
     *
     * One database, not five. Swiftly and its companion plugins (CS2_Admin,
     * CS2_Ranks, weapon skins, VIPCore) already share a single database, and
     * the panel stores its own tables alongside them rather than standing up a
     * second one. The submitted block is fanned out to every connection name
     * the application uses, so config/database.php keeps its five entries and
     * a later install can repoint one of them without a schema change.
     *
     * The panel's migrations must therefore not collide with what the plugins
     * own. They do not: the plugin tables are prefixed by plugin (admin_*,
     * k4*, wp_*, vip_*, sa_*), while the panel creates the usual Laravel set
     * plus its own feature tables.
     */
    public function database(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'connection.host' => 'required|string',
            'connection.port' => 'required|integer|between:1,65535',
            'connection.database' => 'required|string',
            'connection.username' => 'required|string',
            'connection.password' => 'nullable|string',
            // Which admin plugin owns the permission/admin/group data on
            // this database - see config/settings.php's `admin_plugin` and
            // App\Support\AdminPlugin\AdminManagerInterface. Asked here,
            // once, rather than auto-detected from table presence.
            'admin_plugin' => 'nullable|string|in:cs2_admin,swiftly_admins',
        ]);

        if ($validator->fails()) {
            return Api::error(Api::MSG_VALIDATION_FAILED, $validator->errors()->toArray(), 422);
        }

        $data = $request->input('connection');
        $adminPlugin = (string) $request->input('admin_plugin', 'cs2_admin');

        // Probe once. Every connection points at the same server, so testing
        // each one separately would report a single wrong password five times.
        if (! $this->probeCredentials($data)) {
            return Api::error('database_connection_failed', ['connections' => ['connection']], 422);
        }

        // Reachable is not the same as usable: report which plugin tables are
        // absent so a wrong-but-valid database is caught here instead of
        // surfacing later as an empty page. Advisory only - not every server
        // runs every plugin.
        $integrations = $this->dependencies->inspect(self::PROBE_CONNECTION, $adminPlugin);

        app(SettingService::class)->set('admin_plugin', $adminPlugin);

        // Only .env is written. The live connections are deliberately left
        // alone: "panel" is what the session and cache drivers use, so
        // repointing it mid-request sent the rest of this request - including
        // the session write that closes it - at a database whose panel tables
        // do not exist yet, turning a successful save into a 500 immediately
        // after it. The new values are picked up on the next request, which is
        // when they are first needed.
        $values = [];

        foreach (self::CONNECTIONS as $connection) {
            $prefix = $connection === 'panel' ? 'DB_' : strtoupper($connection).'_DB_';

            $values[$prefix.'HOST'] = $data['host'];
            $values[$prefix.'PORT'] = $data['port'];
            $values[$prefix.'DATABASE'] = $data['database'];
            $values[$prefix.'USERNAME'] = $data['username'];
            $values[$prefix.'PASSWORD'] = $data['password'] ?? '';
        }

        $values['DB_CONNECTION'] = 'panel';
        $values[self::STEP_KEY] = self::STEP_DATABASE;
        (new EnvWriter($this->envPath()))->set($values);

        return Api::success(null, [
            'connections' => self::CONNECTIONS,
            'integrations' => $integrations,
        ]);
    }

    /**
     * GET /api/install/servers
     *
     * Servers the plugin has already registered in the database just
     * configured in the previous step - read fresh, not the "panel"
     * connection's stale copy, since this is a new request and the .env
     * written by database() has already taken effect by the time it runs.
     */
    public function servers(): JsonResponse
    {
        try {
            $servers = AdminServer::query()->orderBy('server_id')->get(['id', 'server_ip', 'server_port']);
        } catch (Throwable) {
            // The swiftly connection may still be unreachable (wrong
            // credentials survived the probe somehow, or the plugin has
            // simply never run) - an empty list just skips the RCON step.
            $servers = collect();
        }

        return Api::success($servers);
    }

    /**
     * POST /api/install/rcon
     *
     * Body: { password: nullable string }. Optional - a fresh install may
     * not know the RCON password yet, or may want a different one per
     * server, both fine to set later from the RCON page. When provided, it
     * is applied to every server detected in the plugin database so the
     * 5-minute health check (see HealthService::checkRcon) has something to
     * monitor immediately rather than only after a manual per-server visit.
     */
    public function rcon(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'password' => 'nullable|string|max:255',
        ]);

        if ($validator->fails()) {
            return Api::error(Api::MSG_VALIDATION_FAILED, $validator->errors()->toArray(), 422);
        }

        $password = trim((string) $request->input('password', ''));

        if ($password !== '') {
            foreach (AdminServer::query()->get(['id']) as $server) {
                RconSetting::query()->updateOrCreate(
                    ['server_id' => $server->id],
                    ['password' => $password],
                );
            }
        }

        (new EnvWriter($this->envPath()))->set([self::STEP_KEY => self::STEP_RCON]);

        return Api::success(null);
    }

    /**
     * POST /api/install/steam
     *
     * Body: { api_key, client_id, client_secret, callback_url, owner_steam_id }
     */
    public function steam(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'api_key' => 'required|string',
            'owner_steam_id' => 'required|string',
        ]);

        if ($validator->fails()) {
            return Api::error(Api::MSG_VALIDATION_FAILED, $validator->errors()->toArray(), 422);
        }

        $ownerId = trim($request->input('owner_steam_id'));

        if (! SteamId::isValid($ownerId)) {
            return Api::error(Api::MSG_INVALID_INPUT, ['owner_steam_id' => 'invalid_steam_id'], 422);
        }

        // Two values, because Steam OpenID 2.0 genuinely needs no more.
        // config/services.php derives the Socialite client secret and the
        // callback from these; see the note there.
        (new EnvWriter($this->envPath()))->set([
            'STEAM_API_KEY' => trim((string) $request->input('api_key')),
            'OWNER_STEAM_ID' => $ownerId,
            self::STEP_KEY => self::STEP_STEAM,
        ]);

        return Api::success(null);
    }


    /**
     * POST /api/install/complete
     *
     * Marks the panel as installed. From this point the InstallLock
     * middleware lets every route through.
     */
    public function complete(): JsonResponse
    {
        (new EnvWriter($this->envPath()))->set(['INSTALLED' => true]);

        $this->retireInstaller();

        return Api::success(['installed' => true]);
    }

    /**
     * Take the installer out of service once it has done its job.
     *
     * config/modules.php gates the Install module on INSTALLED, so from the
     * next request its routes are never registered - the wizard stops
     * existing rather than merely being refused. InstallLock's 404 stays as
     * the outer guard; an earlier release shipped with only that check
     * misordered and left /api/install/* writable on a live panel, so the two
     * layers are deliberate.
     *
     * The gate reads env(), which a cached config resolves once at cache time,
     * so the cache is dropped here or the module would stay registered until
     * the next deploy.
     *
     * Deleting the wizard's own files is attempted but expected to fail on a
     * correctly permissioned deploy, where the application user owns nothing
     * it serves. That is the better arrangement, so the failure is ignored
     * rather than reported: the routes are already gone, which is what
     * actually matters. The module's PHP is never removed in any case -
     * bootstrap/providers.php names InstallServiceProvider, and deleting the
     * class would stop the application booting at all.
     */
    private function retireInstaller(): void
    {
        try {
            Artisan::call('config:clear');
        } catch (Throwable $e) {
            // Worst case the wizard remains routable until the next deploy,
            // where InstallLock still refuses it. Not worth failing the
            // install the operator just completed.
            report($e);
        }

        try {
            File::delete(resource_path('views/install/index.blade.php'));
        } catch (Throwable) {
            // Permission denied is the normal, healthy outcome here.
        }
    }

    /**
     * POST /api/install/restore-backup
     *
     * Body: multipart { backup: <file> }. Replaces the entire locale ->
     * database -> steam -> modules -> complete flow above in one shot -
     * see PanelBackup::restore() for exactly what it does and does not
     * restore (plugin *code* is deliberately excluded; see its docblock).
     */
    public function restoreBackup(Request $request, PanelBackup $backup): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'backup' => 'required|file|max:51200',
        ]);

        if ($validator->fails()) {
            return Api::error(Api::MSG_VALIDATION_FAILED, $validator->errors()->toArray(), 422);
        }

        try {
            $summary = $backup->restore($request->file('backup'));

            return Api::success($summary);
        } catch (PanelBackupException $e) {
            return Api::error($e->getMessage(), $e->errors(), 422);
        } catch (InvalidArgumentException $e) {
            return Api::error($e->getMessage(), [], 422);
        } catch (Throwable) {
            return Api::error('backup_restore_failed', [], 422);
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    /**
     * Test submitted credentials on a throwaway connection.
     *
     * Probing one of the app's own connections does not work: Laravel caches
     * a connection once it has been resolved, and "panel" is the default one,
     * already open for the session and cache drivers. Overriding its config
     * therefore changes nothing, DB::connection() hands back the live handle
     * and the probe reports success no matter what was typed in.
     *
     * Purging that connection instead would test the right credentials but
     * leave the request without a working session store when they are wrong,
     * so the probe gets a connection of its own that nothing else uses.
     *
     * @param  array<string, mixed>  $data
     */
    private function probeCredentials(array $data): bool
    {
        $this->overrideConnection(self::PROBE_CONNECTION, $data);

        DB::purge(self::PROBE_CONNECTION);

        $healthy = $this->probe->isHealthy(self::PROBE_CONNECTION);

        DB::purge(self::PROBE_CONNECTION);

        return $healthy;
    }

    private function overrideConnection(string $connection, array $data): void
    {
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