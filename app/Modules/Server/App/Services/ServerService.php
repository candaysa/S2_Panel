<?php

namespace App\Modules\Server\App\Services;

use App\Modules\Audit\App\Services\AuditService;
use App\Modules\Server\App\Models\AdminServer;
use App\Modules\Server\App\Models\ServerSetting;
use App\Support\A2s;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Cache;
use InvalidArgumentException;

/**
 * Server list + live state (C10).
 *
 * The list itself comes from the Swiftly admin_servers table (read-only);
 * live hostname/map/player counts are queried with A2S. A server that does
 * not answer within the configured timeout is reported with live=null – the
 * panel never blocks on a dead server.
 *
 * Two things keep that from costing the page a visible stall: every server
 * in a listing is probed in one parallel batch (A2s::infoMany) rather than
 * one after another, and the result is cached briefly. Sequentially probing
 * this panel's 12 servers took up to 24s, since five of them carry a stored
 * IP of 0.0.0.0 and always burn the full timeout.
 */
class ServerService
{
    /**
     * Live state is volatile but not per-request - a few seconds of staleness
     * is invisible next to the round trip it saves. Short enough that a
     * server going down still surfaces quickly.
     */
    private const LIVE_CACHE_SECONDS = 15;

    public function __construct(private readonly AuditService $audit)
    {
    }

    public function list(?string $search = null, int $perPage = 25, bool $includeHidden = false): LengthAwarePaginator
    {
        return AdminServer::query()
            ->unless($includeHidden, fn ($q) => $q->visible())
            ->when($search !== null && $search !== '', function ($q) use ($search): void {
                $q->where(function ($q2) use ($search): void {
                    $q2->where('server_id', 'like', "%{$search}%")
                        ->orWhere('server_ip', 'like', "%{$search}%");
                });
            })
            ->orderBy('server_id')
            ->paginate($perPage);
    }

    /**
     * Register a server the plugin has not reported yet.
     *
     * Writes a row into the plugin's admin_servers, the same table CS2_Admin
     * upserts into itself - so a server added here is indistinguishable from
     * one the plugin registered, and the plugin will simply keep it current.
     */
    public function create(string $ip, int $port): AdminServer
    {
        $ip = trim($ip);

        if (filter_var($ip, FILTER_VALIDATE_IP) === false || $port < 1 || $port > 65535) {
            throw new InvalidArgumentException('invalid_address');
        }

        $serverId = $ip.':'.$port;

        if (AdminServer::query()->where('server_id', $serverId)->exists()) {
            throw new InvalidArgumentException('already_exists');
        }

        $server = AdminServer::query()->create([
            'server_id' => $serverId,
            'server_ip' => $ip,
            'server_port' => $port,
        ]);

        $this->audit->log('server.created', 'server', (string) $server->getKey(), ['address' => $serverId]);

        return $server;
    }

    /**
     * Remove a server from the panel and drop its settings.
     *
     * This deletes the plugin's own row. CS2_Admin re-registers a server it
     * still hosts on next startup, so for a live server "hide" is usually
     * what the owner actually wants - this is for entries that are gone for
     * good (or were never valid, like the 0.0.0.0 rows a misconfigured
     * install leaves behind).
     */
    public function destroy(int $id): bool
    {
        $server = AdminServer::query()->find($id);

        if ($server === null) {
            return false;
        }

        $address = $server->server_ip.':'.$server->server_port;
        $server->delete();
        ServerSetting::query()->where('server_id', $id)->delete();
        AdminServer::forgetHiddenIds();
        Cache::forget($this->liveCacheKey($server));

        $this->audit->log('server.deleted', 'server', (string) $id, ['address' => $address]);

        return true;
    }

    /**
     * Show or hide a server in the public list.
     */
    public function setHidden(int $id, bool $hidden): ServerSetting
    {
        $setting = ServerSetting::query()->updateOrCreate(
            ['server_id' => $id],
            ['hidden' => $hidden],
        );

        AdminServer::forgetHiddenIds();

        $this->audit->log($hidden ? 'server.hidden' : 'server.shown', 'server', (string) $id);

        return $setting;
    }

    /**
     * @return array<int, bool> hidden flag keyed by server id
     */
    public function hiddenMap(): array
    {
        return ServerSetting::query()->pluck('hidden', 'server_id')
            ->map(fn ($v): bool => (bool) $v)
            ->all();
    }

    /**
     * @return array{
     *     name: string, map: string, players: int, max_players: int,
     *     bots: int, app_id: int
     * }|null null when the server is unreachable or misconfigured.
     */
    public function live(AdminServer $server): ?array
    {
        return $this->liveFor([$server])[$server->getKey()] ?? null;
    }

    /**
     * Live state for a set of servers, in one parallel batch, cached.
     *
     * @param  iterable<AdminServer>  $servers
     * @return array<int, array<string, mixed>|null> keyed by server id
     */
    public function liveFor(iterable $servers): array
    {
        $results = [];
        $toProbe = [];

        foreach ($servers as $server) {
            $id = (int) $server->getKey();
            $cached = Cache::get($this->liveCacheKey($server));

            // A miss and a cached "offline" are different: the cache stores
            // ['v' => null] for a server that genuinely did not answer, so a
            // dead server is not re-probed on every single page load.
            if (is_array($cached) && array_key_exists('v', $cached)) {
                $results[$id] = $cached['v'];

                continue;
            }

            $results[$id] = null;
            $toProbe[$id] = [
                'host' => trim((string) $server->server_ip),
                'port' => (int) $server->server_port,
                'server' => $server,
            ];
        }

        if ($toProbe === []) {
            return $results;
        }

        $raw = A2s::infoMany(
            array_map(fn (array $t): array => ['host' => $t['host'], 'port' => $t['port']], $toProbe),
            (float) config('server.a2s_timeout', 2.0),
        );

        foreach ($toProbe as $id => $target) {
            $info = $raw[$id] ?? null;

            $live = $info === null ? null : [
                'name' => (string) $info['name'],
                'map' => (string) $info['map'],
                'players' => (int) $info['players'],
                'max_players' => (int) $info['max_players'],
                'bots' => (int) $info['bots'],
                'app_id' => (int) $info['app_id'],
            ];

            $results[$id] = $live;
            Cache::put($this->liveCacheKey($target['server']), ['v' => $live], self::LIVE_CACHE_SECONDS);
        }

        return $results;
    }

    /**
     * Every server plus its live state (best-effort).
     */
    public function listWithLive(?string $search = null, int $perPage = 25, bool $includeHidden = false): LengthAwarePaginator
    {
        // $includeHidden was silently dropped here before - the controller
        // has always passed it, but this signature never had a third
        // parameter to receive it, so "show hidden servers" never actually
        // reached list() and always saw the public-only view.
        $servers = $this->list($search, $perPage, $includeHidden);

        $live = $this->liveFor($servers->getCollection());

        $servers->getCollection()->transform(fn (AdminServer $server): array => [
            'server' => $server,
            'live' => $live[(int) $server->getKey()] ?? null,
        ]);

        return $servers;
    }

    /**
     * @return array{server: AdminServer, live: array|null}|null
     */
    public function findWithLive(int $id): ?array
    {
        $server = AdminServer::query()->find($id);

        if ($server === null) {
            return null;
        }

        return [
            'server' => $server,
            'live' => $this->live($server),
        ];
    }

    /**
     * Keyed on the address actually probed, not the row id, so editing a
     * server's ip/port invalidates its cached state immediately.
     */
    private function liveCacheKey(AdminServer $server): string
    {
        return 'server.live.'.$server->server_ip.':'.$server->server_port;
    }
}