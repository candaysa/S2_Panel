<?php

namespace App\Modules\Audit\App\Http\Controllers;

use App\Modules\Audit\App\Services\AdminLogService;
use App\Support\Api;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * In-game admin action log (the "Admin logs" tab).
 *
 * Distinct from AuditController, which reads this panel's own trail. This
 * one reads the admin plugin's admin_log: who did what, to whom, when, and
 * on which server - none of which the panel records itself, because none of
 * it happens in the panel.
 *
 * GET /api/audit/admin-log         - paginated, filterable
 * GET /api/audit/admin-log/filters - admins and actions present in the log
 *
 * Both sit behind the same flag:admin.root gate as the rest of the module
 * (see Routes/api.php): this is every moderation decision on the server,
 * including ones taken against the person reading it.
 */
class AdminLogController
{
    private const MAX_PER_PAGE = 100;

    public function __construct(private readonly AdminLogService $log)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $perPage = min((int) $request->query('per_page', 50), self::MAX_PER_PAGE);

        $logs = $this->log->list(
            adminSteamId: $request->query('admin') !== null ? (string) $request->query('admin') : null,
            action: $request->query('action') !== null ? (string) $request->query('action') : null,
            search: $request->query('search') !== null ? (string) $request->query('search') : null,
            from: $request->query('from') !== null ? (string) $request->query('from') : null,
            to: $request->query('to') !== null ? (string) $request->query('to') : null,
            perPage: $perPage,
            sort: (string) $request->query('sort', 'id'),
            dir: (string) $request->query('dir', 'desc'),
        );

        // No table is a real, expected answer for a plugin that keeps no
        // such log - an empty list plus a flag the page can explain, not an
        // error. See AdminLogService::available().
        if ($logs === null) {
            return Api::success([], ['available' => false]);
        }

        return Api::success($logs->items(), [
            'available' => true,
            'pagination' => [
                'current_page' => $logs->currentPage(),
                'per_page' => $logs->perPage(),
                'total' => $logs->total(),
                'last_page' => $logs->lastPage(),
            ],
        ]);
    }

    public function filters(): JsonResponse
    {
        return Api::success([
            'admins' => $this->log->admins(),
            'actions' => $this->log->actions(),
        ], ['available' => $this->log->available()]);
    }
}
