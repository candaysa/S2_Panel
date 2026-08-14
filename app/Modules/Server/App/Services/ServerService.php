<?php

namespace App\Modules\Server\App\Services;

use App\Modules\Server\App\Models\AdminServer;
use App\Support\A2s;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * Server list + live state (C10).
 *
 * The list itself comes from the Swiftly admin_servers table (read-only);
 * live hostname/map/player counts are queried with A2S on demand. A server
 * that does not answer within the configured timeout is reported with
 * live=null – the panel never blocks on a dead server.
 */
class ServerService
{
    public function list(?string $search = null, int $perPage = 25): LengthAwarePaginator
    {
        return AdminServer::query()
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
     * @return array{
     *     name: string, map: string, players: int, max_players: int,
     *     bots: int, app_id: int
     * }|null null when the server is unreachable or misconfigured.
     */
    public function live(AdminServer $server): ?array
    {
        $host = trim((string) $server->server_ip);
        $port = (int) $server->server_port;

        if ($host === '' || $port < 1 || $port > 65535) {
            return null;
        }

        $info = A2s::info($host, $port, (float) config('server.a2s_timeout', 2.0));

        if ($info === null) {
            return null;
        }

        return [
            'name' => (string) $info['name'],
            'map' => (string) $info['map'],
            'players' => (int) $info['players'],
            'max_players' => (int) $info['max_players'],
            'bots' => (int) $info['bots'],
            'app_id' => (int) $info['app_id'],
        ];
    }

    /**
     * Every server plus its live state (best-effort).
     *
     * @return list<array{server: AdminServer, live: array|null}>
     */
    public function listWithLive(?string $search = null, int $perPage = 25): LengthAwarePaginator
    {
        $servers = $this->list($search, $perPage);

        $servers->getCollection()->transform(function (AdminServer $server): array {
            return [
                'server' => $server,
                'live' => $this->live($server),
            ];
        });

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
}