<?php

namespace App\Modules\Vip\App\Http\Controllers;

use App\Modules\Vip\App\Services\VipService;
use App\Support\Api;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use InvalidArgumentException;

/**
 * VIP endpoints (C7).
 *
 * GET    /api/vip                    - list VIP users (search + server filter + pagination)
 * GET    /api/vip/servers            - VIPCore's known servers (read-only)
 * GET    /api/vip/{steamid}          - one player's VIP groups across servers
 * POST   /api/vip                    - grant/extend a VIP group (requires admin.vip)
 * DELETE /api/vip/{steamid}/{group}  - revoke a VIP group (requires admin.vip)
 */
class VipController
{
    public function __construct(private readonly VipService $vip)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $search = $request->query('search');
        $serverId = $request->query('server_id');
        $perPage = min((int) $request->query('per_page', 25), 100);

        $users = $this->vip->listUsers(
            $serverId !== null ? (int) $serverId : null,
            $search !== null ? (string) $search : null,
            $perPage,
        );

        return Api::success($users->items(), [
            'pagination' => [
                'current_page' => $users->currentPage(),
                'per_page' => $users->perPage(),
                'total' => $users->total(),
                'last_page' => $users->lastPage(),
            ],
        ]);
    }

    public function servers(): JsonResponse
    {
        return Api::success($this->vip->listServers());
    }

    public function show(Request $request, string $steamid): JsonResponse
    {
        $serverId = $request->query('server_id');

        try {
            $groups = $this->vip->groupsFor($steamid, $serverId !== null ? (int) $serverId : null);
        } catch (InvalidArgumentException) {
            return Api::error(Api::MSG_INVALID_INPUT, ['steamid' => ['invalid_steamid_format']], 422);
        }

        return Api::success($groups);
    }

    public function grant(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'steamid' => 'required|string',
            'name' => 'required|string|max:64',
            'group' => 'required|string|max:64',
            'server_id' => 'required|integer',
            'expires_at' => 'nullable|integer|min:0',
        ]);

        if ($validator->fails()) {
            return Api::error(Api::MSG_VALIDATION_FAILED, $validator->errors()->toArray(), 422);
        }

        $data = $validator->validated();

        try {
            $row = $this->vip->grant(
                $data['steamid'],
                $data['name'],
                $data['group'],
                (int) $data['server_id'],
                $data['expires_at'] ?? null,
                $request->user(),
            );
        } catch (InvalidArgumentException $e) {
            if ($e->getMessage() === 'server_not_found') {
                return Api::notFound();
            }

            return Api::error(Api::MSG_INVALID_INPUT, ['steamid' => [$e->getMessage()]], 422);
        }

        return Api::success($row);
    }

    public function revoke(Request $request, string $steamid, string $group): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'server_id' => 'required|integer',
        ]);

        if ($validator->fails()) {
            return Api::error(Api::MSG_VALIDATION_FAILED, $validator->errors()->toArray(), 422);
        }

        try {
            $revoked = $this->vip->revoke(
                $steamid,
                (int) $validator->validated()['server_id'],
                $group,
                $request->user(),
            );
        } catch (InvalidArgumentException) {
            return Api::error(Api::MSG_INVALID_INPUT, ['steamid' => ['invalid_steamid_format']], 422);
        }

        if (! $revoked) {
            return Api::notFound();
        }

        return Api::success(null);
    }
}
