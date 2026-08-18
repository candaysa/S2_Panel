<?php

namespace App\Modules\Audit\App\Services;

use App\Modules\Audit\App\Models\AdminLog;
use App\Support\SteamId;
use App\Support\SteamProfiles;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Read access to the admin plugin's in-game action log (admin_log).
 *
 * Not every admin plugin writes this table - the official swiftlys2-plugins
 * /admins schema has no equivalent - so every method here is written to
 * return an empty result rather than throw when it is missing. A panel
 * pointed at a plugin that keeps no such log should show an empty page with
 * an explanation, not a 500.
 */
class AdminLogService
{
    /** Columns a caller may sort by. Anything else falls back to id. */
    private const SORTABLE = ['id', 'created_at', 'admin_name', 'action'];

    /**
     * Whether the active admin plugin actually keeps this log. Checked
     * rather than assumed: this is the difference between "no admin has
     * done anything yet" and "this plugin does not record it", and the page
     * says which.
     */
    public function available(): bool
    {
        try {
            return Schema::connection('swiftly')->hasTable('admin_log');
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * @return LengthAwarePaginator<int, array<string, mixed>>|null null when
     *         the table does not exist (see available())
     */
    public function list(
        ?string $adminSteamId = null,
        ?string $action = null,
        ?string $search = null,
        ?string $from = null,
        ?string $to = null,
        int $perPage = 50,
        string $sort = 'id',
        string $dir = 'desc',
    ): ?LengthAwarePaginator {
        if (! $this->available()) {
            return null;
        }

        $query = AdminLog::query();

        // The dropdown's whole purpose: one admin's history, in order.
        if ($adminSteamId !== null && $adminSteamId !== '' && SteamId::isValid($adminSteamId)) {
            $query->where('admin_steamid', (int) SteamId::parse($adminSteamId)->steamId64());
        }

        if ($action !== null && $action !== '') {
            $query->where('action', $action);
        }

        // Free text matches either side, same rule as the bans list: a
        // SteamID is looked up as both the admin who acted and the player
        // acted upon, because which one you have in hand varies.
        if ($search !== null && $search !== '') {
            $query->where(function ($q) use ($search): void {
                $q->where('admin_name', 'like', "%{$search}%")
                    ->orWhere('action', 'like', "%{$search}%")
                    ->orWhere('details', 'like', "%{$search}%");

                if (SteamId::isValid($search)) {
                    $steam64 = (int) SteamId::parse($search)->steamId64();
                    $q->orWhere('admin_steamid', $steam64)
                        ->orWhere('target_steamid', $steam64);
                }
            });
        }

        if ($from !== null && $from !== '') {
            $query->where('created_at', '>=', $from);
        }

        if ($to !== null && $to !== '') {
            $query->where('created_at', '<=', $to);
        }

        $column = in_array($sort, self::SORTABLE, true) ? $sort : 'id';
        $dir = $dir === 'asc' ? 'asc' : 'desc';

        $paginator = $query->orderBy($column, $dir)->paginate($perPage);

        $this->decorate($paginator);

        return $paginator;
    }

    /**
     * Admins that actually appear in the log, for the filter dropdown.
     *
     * Built from the log itself rather than from the admin list, so it never
     * offers a name with nothing behind it and still includes admins who
     * have since been removed - whose history is exactly what someone
     * reviewing this page is most likely looking for.
     *
     * @return array<int, array{steamid: string, name: string, actions: int}>
     */
    public function admins(): array
    {
        if (! $this->available()) {
            return [];
        }

        return DB::connection('swiftly')
            ->table('admin_log')
            ->select('admin_steamid')
            // One admin can appear under several past nicknames; the most
            // recent row wins, so the dropdown shows what they are called
            // now rather than whatever they were called first.
            ->selectRaw('MAX(admin_name) as admin_name, COUNT(*) as actions')
            ->whereNotNull('admin_steamid')
            ->groupBy('admin_steamid')
            ->orderByDesc('actions')
            ->get()
            ->map(fn ($row): array => [
                'steamid' => (string) $row->admin_steamid,
                'name' => (string) ($row->admin_name ?? $row->admin_steamid),
                'actions' => (int) $row->actions,
            ])
            ->all();
    }

    /**
     * Distinct action verbs present in the log, for the second dropdown.
     * Plugin-defined strings, not a fixed list the panel could hardcode.
     *
     * @return array<int, string>
     */
    public function actions(): array
    {
        if (! $this->available()) {
            return [];
        }

        return DB::connection('swiftly')
            ->table('admin_log')
            ->distinct()
            ->orderBy('action')
            ->pluck('action')
            ->filter()
            ->values()
            ->all();
    }

    /**
     * Attach live Steam profiles for both sides of each row.
     *
     * The stored admin_name is a snapshot from the moment of the action, and
     * the target has no name column at all - only a SteamID - so without
     * this the "to whom" column reads as a bare 17-digit number, which is
     * the one thing nobody can identify at a glance.
     *
     * @param  LengthAwarePaginator<int, AdminLog>  $paginator
     */
    private function decorate(LengthAwarePaginator $paginator): void
    {
        $rows = $paginator->getCollection();

        if ($rows->isEmpty()) {
            return;
        }

        $ids = $rows->pluck('admin_steamid')
            ->merge($rows->pluck('target_steamid'))
            ->filter()
            ->unique()
            ->values()
            ->all();

        try {
            $profiles = SteamProfiles::many($ids);
        } catch (Throwable) {
            $profiles = [];
        }

        $rows->transform(function (AdminLog $row) use ($profiles): array {
            $data = $row->toArray();

            $admin = $profiles[(string) $row->admin_steamid] ?? null;
            $target = $profiles[(string) $row->target_steamid] ?? null;

            $data['admin_avatar'] = $admin['avatar'] ?? null;
            $data['admin_current_name'] = $admin['name'] ?? null;
            $data['target_avatar'] = $target['avatar'] ?? null;
            $data['target_name'] = $target['name'] ?? null;

            return $data;
        });
    }
}
