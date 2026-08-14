<?php

namespace App\Modules\Server\App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Server\App\Models\AdminServer;
use App\Modules\Server\App\Services\ServerService;
use App\Support\Access;
use App\Support\Api;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Validator;
use InvalidArgumentException;

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

        // Only an owner may ask to see hidden servers, and only by asking -
        // the default stays the public view even for them, so the management
        // screen is the one place hidden entries surface.
        $includeHidden = $request->boolean('include_hidden') && Access::isOwner();

        $servers = $this->servers->listWithLive($request->query('search'), $perPage, $includeHidden);

        return $this->response($servers, $includeHidden ? $this->servers->hiddenMap() : []);
    }

    /**
     * POST /api/servers – register a server the plugin has not reported.
     */
    public function store(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'server_ip' => 'required|string|max:64',
            'server_port' => 'required|integer|between:1,65535',
        ]);

        if ($validator->fails()) {
            return Api::error(Api::MSG_VALIDATION_FAILED, $validator->errors()->toArray(), 422);
        }

        $data = $validator->validated();

        try {
            $server = $this->servers->create((string) $data['server_ip'], (int) $data['server_port']);
        } catch (InvalidArgumentException $e) {
            return Api::error(Api::MSG_INVALID_INPUT, ['server_ip' => [$e->getMessage()]], 422);
        }

        return Api::success($this->payload($server, null), ['created' => true]);
    }

    /**
     * PUT /api/servers/{id}/visibility – show or hide in the public list.
     */
    public function visibility(Request $request, string $id): JsonResponse
    {
        if (! ctype_digit($id)) {
            return Api::notFound();
        }

        $validator = Validator::make($request->all(), ['hidden' => 'required|boolean']);

        if ($validator->fails()) {
            return Api::error(Api::MSG_VALIDATION_FAILED, $validator->errors()->toArray(), 422);
        }

        if (AdminServer::query()->whereKey((int) $id)->doesntExist()) {
            return Api::notFound();
        }

        $setting = $this->servers->setHidden((int) $id, (bool) $validator->validated()['hidden']);

        return Api::success(['server_id' => $setting->server_id, 'hidden' => $setting->hidden]);
    }

    /**
     * DELETE /api/servers/{id}
     */
    public function destroy(string $id): JsonResponse
    {
        if (! ctype_digit($id)) {
            return Api::notFound();
        }

        if (! $this->servers->destroy((int) $id)) {
            return Api::notFound();
        }

        return Api::success(['deleted' => true]);
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

    /**
     * @param  array<int, bool>  $hiddenMap
     */
    private function response(LengthAwarePaginator $servers, array $hiddenMap = []): JsonResponse
    {
        $items = collect($servers->items())
            ->map(function (array $row) use ($hiddenMap): array {
                $payload = $this->payload($row['server'], $row['live']);
                $payload['hidden'] = $hiddenMap[(int) $row['server']->getKey()] ?? false;

                return $payload;
            })
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