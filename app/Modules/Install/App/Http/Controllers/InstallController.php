<?php

namespace App\Modules\Install\App\Http\Controllers;

use App\Modules\Install\App\Services\ConnectionProbe;
use App\Modules\Install\App\Services\EnvWriter;
use App\Modules\Settings\App\Services\SettingService;
use App\Support\Api;
use App\Support\SteamId;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/**
 * Installation wizard (C13). Public endpoints – the InstallLock middleware
 * already exempts "api/install/*" while INSTALLED=false.
 *
 * Flow: status → locale (session + owner default) → database (probe +
 * persist) → steam/owner (validate + persist) → modules (persist toggles)
 * → complete (INSTALLED=true).
 */
class InstallController
{
    private const CONNECTIONS = ['panel', 'swiftly', 'ranks', 'weaponskins', 'vip'];

    public function __construct(private readonly ConnectionProbe $probe)
    {
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
        return Api::success([
            'installed' => (bool) config('app.installed'),
            'app_url' => config('app.url'),
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
        ]);

        if ($validator->fails()) {
            return Api::error(Api::MSG_VALIDATION_FAILED, $validator->errors()->toArray(), 422);
        }

        $locale = (string) $request->input('locale');

        $request->session()->put('locale', $locale);
        app(SettingService::class)->set('default_locale', $locale);

        return Api::success(['locale' => $locale]);
    }

    /**
     * POST /api/install/database
     *
     * Body: { connection: {host, port, database, username, password}, ... }
     * Probes every connection with the submitted credentials (overrides the
     * runtime config only) and persists them once all four succeed.
     */
    public function database(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'panel.host' => 'required|string',
            'panel.port' => 'required|integer|between:1,65535',
            'panel.database' => 'required|string',
            'panel.username' => 'required|string',
            'panel.password' => 'nullable|string',
            'swiftly.host' => 'required|string',
            'swiftly.port' => 'required|integer|between:1,65535',
            'swiftly.database' => 'required|string',
            'swiftly.username' => 'required|string',
            'swiftly.password' => 'nullable|string',
            'ranks.host' => 'required|string',
            'ranks.port' => 'required|integer|between:1,65535',
            'ranks.database' => 'required|string',
            'ranks.username' => 'required|string',
            'ranks.password' => 'nullable|string',
            'weaponskins.host' => 'required|string',
            'weaponskins.port' => 'required|integer|between:1,65535',
            'weaponskins.database' => 'required|string',
            'weaponskins.username' => 'required|string',
            'weaponskins.password' => 'nullable|string',
            'vip.host' => 'required|string',
            'vip.port' => 'required|integer|between:1,65535',
            'vip.database' => 'required|string',
            'vip.username' => 'required|string',
            'vip.password' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return Api::error(Api::MSG_VALIDATION_FAILED, $validator->errors()->toArray(), 422);
        }

        $failures = [];

        foreach (self::CONNECTIONS as $connection) {
            $this->overrideConnection($connection, $request->input($connection));

            if (! $this->probe->isHealthy($connection)) {
                $failures[] = $connection;
            }
        }

        if ($failures !== []) {
            return Api::error('database_connection_failed', ['connections' => $failures], 422);
        }

        $values = [];

        foreach (self::CONNECTIONS as $connection) {
            $data = $request->input($connection);
            $prefix = $connection === 'panel' ? 'DB_' : strtoupper($connection).'_DB_';

            $values[$prefix.'HOST'] = $data['host'];
            $values[$prefix.'PORT'] = $data['port'];
            $values[$prefix.'DATABASE'] = $data['database'];
            $values[$prefix.'USERNAME'] = $data['username'];
            $values[$prefix.'PASSWORD'] = $data['password'] ?? '';
        }

        $values['DB_CONNECTION'] = 'panel';
        (new EnvWriter($this->envPath()))->set($values);

        return Api::success(null, ['connections' => self::CONNECTIONS]);
    }

    /**
     * POST /api/install/steam
     *
     * Body: { api_key, client_id, client_secret, callback_url, owner_steam_id }
     */
    public function steam(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'api_key' => 'nullable|string',
            'client_id' => 'nullable|string',
            'client_secret' => 'nullable|string',
            'callback_url' => 'nullable|url',
            'owner_steam_id' => 'required|string',
        ]);

        if ($validator->fails()) {
            return Api::error(Api::MSG_VALIDATION_FAILED, $validator->errors()->toArray(), 422);
        }

        $ownerId = trim($request->input('owner_steam_id'));

        if (! SteamId::isValid($ownerId)) {
            return Api::error(Api::MSG_INVALID_INPUT, ['owner_steam_id' => 'invalid_steam_id'], 422);
        }

        (new EnvWriter($this->envPath()))->set([
            'STEAM_API_KEY' => $request->input('api_key'),
            'STEAM_CLIENT_ID' => $request->input('client_id'),
            'STEAM_CLIENT_SECRET' => $request->input('client_secret'),
            'STEAM_CALLBACK_URL' => $request->input('callback_url') ?? config('app.url').'/api/auth/callback',
            'OWNER_STEAM_ID' => $ownerId,
        ]);

        return Api::success(null);
    }

    /**
     * POST /api/install/modules
     *
     * Body: { admin: true, ban: true, ... } – any module key present is
     * persisted as MODULE_<KEY>=true/false.
     */
    public function modules(Request $request): JsonResponse
    {
        $moduleKeys = array_keys(config('modules.modules', []));
        $values = [];

        foreach ($moduleKeys as $key) {
            // auth/install/modules/plugins are always-on core plumbing,
            // not installer-selectable features - see config/modules.php.
            if (in_array($key, ['auth', 'install', 'modules', 'plugins'], true)) {
                continue;
            }

            $envKey = 'MODULE_'.strtoupper($key);

            if ($request->has($key)) {
                $values[$envKey] = filter_var($request->input($key), FILTER_VALIDATE_BOOLEAN);
            }
        }

        (new EnvWriter($this->envPath()))->set($values);

        return Api::success(null, ['written' => array_keys($values)]);
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

        return Api::success(['installed' => true]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
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