<?php

namespace App\Modules\Server\App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Server\App\Models\AdminServer;
use App\Modules\Server\App\Services\ServerService;
use App\Support\Api;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * Server list endpoints (C10).
 *
 * Reads the Swiftly admin_servers table (read-only) and enriches every
 * row with live A2S state. Any authenticated session may list servers;
 * live=null marks a server that did not answer in time.
 */
class ServerController extends Controller
{
    public function __construct(private readonly ServerService $servers)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $perPage = min((int) $request->query('per_page', 25), 100);

        $servers = $this->servers->listWithLive($request->query('search'), $perPage);

        return $this->response($servers);
    }

    public function show(string $id): JsonResponse
    {
        if (! ctype_digit($id)) {
            return Api::notFound();
        }

        $server = $this->servers->findWithLive((int) $id);

        if ($server === null) {
            return Api::notFound();
        }

        return Api::success($this->payload($server['server'], $server['live']));
    }

    private function response(LengthAwarePaginator $servers): JsonResponse
    {
        $items = collect($servers->items())
            ->map(fn (array $row): array => $this->payload($row['server'], $row['live']))
            ->all();

        return Api::success($items, [
            'pagination' => [
                'total' => $servers->total(),
                'per_page' => $servers->perPage(),
                'current_page' => $servers->currentPage(),
                'last_page' => $servers->lastPage(),
            ],
        ]);
    }

    /**
     * @param  array|null  $live  A2S payload or null when offline.
     * @return array<string, mixed>
     */
    private function payload(AdminServer $server, ?array $live): array
    {
        return [
            'id' => $server->id,
            'server_id' => $server->server_id,
            'server_ip' => $server->server_ip,
            'server_port' => $server->server_port,
            'last_seen_at' => $server->last_seen_at?->toIso8601String(),
            'online' => $live !== null,
            'live' => $live,
        ];
    }
}