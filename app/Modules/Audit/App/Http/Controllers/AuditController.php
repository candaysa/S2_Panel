<?php

namespace App\Modules\Audit\App\Http\Controllers;

use App\Modules\Audit\App\Models\PanelLog;
use App\Support\Api;
use App\Support\SteamProfiles;
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

    /** Columns a caller may sort by. Anything else falls back to created_at. */
    private const SORTABLE = ['created_at', 'action', 'actor_name'];

    public function index(Request $request): JsonResponse
    {
        $query = PanelLog::query();

        if ($action = $request->query('action')) {
            $query->where('action', (string) $action);
        }

        if ($request->has('actor_steamid')) {
            $query->where('actor_steamid', (string) $request->query('actor_steamid'));
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
        $sort = in_array((string) $request->query('sort'), self::SORTABLE, true)
            ? (string) $request->query('sort')
            : 'created_at';
        $dir = strtolower((string) $request->query('dir', 'desc')) === 'asc' ? 'asc' : 'desc';

        $logs = $query->orderBy($sort, $dir)->paginate($perPage);

        // The row's own actor_name is a snapshot from the moment of the
        // action - correct at the time, but Steam nicknames change and one
        // real bug here (Socialite briefly stored the profile's "real name"
        // bio instead of the handle) left some rows with a wrong name that
        // no amount of re-fetching a single row will fix. Overlaying the
        // *current* live profile is strictly better going forward and
        // repairs the historical rows for free, without touching the table.
        $profiles = SteamProfiles::many(collect($logs->items())->pluck('actor_steamid')->filter()->all());

        $items = collect($logs->items())->map(function (PanelLog $log) use ($profiles): array {
            $row = $log->toArray();
            $profile = $profiles[(string) $log->actor_steamid] ?? null;
            $row['actor_avatar'] = $profile['avatar'] ?? null;
            $row['actor_current_name'] = $profile['name'] ?? null;

            return $row;
        })->all();

        return Api::success($items, [
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