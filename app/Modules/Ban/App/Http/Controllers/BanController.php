<?php

namespace App\Modules\Ban\App\Http\Controllers;

use App\Modules\Ban\App\Services\BanService;
use App\Support\Api;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

/**
 * Punishment reading (C4). One endpoint, type selected via query param:
 *
 * GET /api/bans?type=ban|mute|gag|warn&search=&status=active|expired|all
 */
class BanController
{
    public function __construct(private readonly BanService $bans)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $type = (string) $request->query('type', 'ban');
        $search = $request->query('search');
        $status = (string) $request->query('status', 'active');
        $perPage = min((int) $request->query('per_page', 25), 100);
        $sort = (string) $request->query('sort', 'id');
        $dir = (string) $request->query('dir', 'desc');

        if (! in_array($status, ['active', 'expired', 'all'], true)) {
            return Api::error(Api::MSG_INVALID_INPUT, ['status' => ['invalid_status']], 422);
        }

        try {
            $punishments = $this->bans->list(
                $type,
                $search !== null ? (string) $search : null,
                $status,
                $perPage,
                $sort,
                $dir,
            );
        } catch (InvalidArgumentException) {
            return Api::error(Api::MSG_INVALID_INPUT, ['type' => ['invalid_ban_type']], 422);
        }

        return Api::success($punishments->items(), [
            'type' => $type,
            'pagination' => [
                'current_page' => $punishments->currentPage(),
                'per_page' => $punishments->perPage(),
                'total' => $punishments->total(),
                'last_page' => $punishments->lastPage(),
            ],
        ]);
    }
}