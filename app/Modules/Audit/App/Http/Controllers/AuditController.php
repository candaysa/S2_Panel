<?php

namespace App\Modules\Audit\App\Http\Controllers;

use App\Support\Api;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Audit log browsing (C7). Owner and admin.root flagged users may read the
 * trail; filtering is applied server-side so big logs stay paginated.
 *
 * GET /api/audit         – paginated list with optional filters
 * GET /api/audit/{id}    – single log entry
 */
class AuditController
{
    private const MAX_PER_PAGE = 100;

    public function index(Request $request): JsonResponse
    {
        $query = \App\Modules\Audit\App\Models\PanelLog::query();

        if ($action = $request->query('action')) {
            $query->where('action', (string) $action);
        }

        if ($request->has('actor_steamid')) {
            $query->where('actor_steamid', (int) $request->query('actor_steamid'));
        }

        if ($targetType = $request->query('target_type')) {
            $query->where('target_type', (string) $targetType);
        }

        if ($from = $request->query('from')) {
            $query->where('created_at', '>=', (string) $from);
        }

        if ($to = $request->query('to')) {
            $query->where('created_at', '<=', (string) $to);
        }

        $perPage = min((int) $request->query('per_page', 25), self::MAX_PER_PAGE);

        $logs = $query
            ->orderByDesc('created_at')
            ->paginate($perPage);

        return Api::success($logs->items(), [
            'pagination' => [
                'current_page' => $logs->currentPage(),
                'per_page' => $logs->perPage(),
                'total' => $logs->total(),
                'last_page' => $logs->lastPage(),
            ],
        ]);
    }

    public function show(int $id): JsonResponse
    {
        $log = \App\Modules\Audit\App\Models\PanelLog::query()->find($id);

        if ($log === null) {
            return Api::notFound();
        }

        return Api::success($log);
    }
}