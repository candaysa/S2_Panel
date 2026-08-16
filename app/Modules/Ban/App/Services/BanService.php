<?php

namespace App\Modules\Ban\App\Services;

use App\Modules\Ban\App\Models\AdminBan;
use App\Modules\Ban\App\Models\AdminGag;
use App\Modules\Ban\App\Models\AdminMute;
use App\Modules\Ban\App\Models\AdminWarn;
use App\Modules\Ban\App\Models\Punishment;
use App\Support\SteamId;
use App\Support\SteamProfiles;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use InvalidArgumentException;
use Throwable;

/**
 * Read access to the Swiftly punishment tables (C4). The panel is a
 * read-only consumer here – bans/mutes/gags/warns are owned by the plugin
 * and are only ever queried.
 *
 * Search accepts a player name or any SteamID format; the lookup converts
 * to SteamID64 so all three plugin formats resolve to the same row.
 */
class BanService
{
    private const MODELS = [
        'ban' => AdminBan::class,
        'mute' => AdminMute::class,
        'gag' => AdminGag::class,
        'warn' => AdminWarn::class,
    ];

    /** Columns a caller may sort by. Anything else falls back to 'id'. */
    private const SORTABLE = ['id', 'target_name', 'admin_name', 'created_at', 'expires_at'];

    public static function types(): array
    {
        return array_keys(self::MODELS);
    }

    /**
     * @return LengthAwarePaginator<int, Punishment>
     */
    public function list(
        string $type,
        ?string $search = null,
        string $status = 'active',
        int $perPage = 25,
        string $sort = 'id',
        string $dir = 'desc',
    ): LengthAwarePaginator {
        $model = self::MODELS[$type] ?? throw new InvalidArgumentException('invalid_ban_type');

        $query = $model::query();

        if ($search !== null && $search !== '') {
            $query->where(function ($q) use ($search): void {
                $q->where('target_name', 'like', "%{$search}%");

                if (SteamId::isValid($search)) {
                    $q->orWhere('steamid', (int) SteamId::parse($search)->steamId64());
                }
            });
        }

        if ($status === 'expired') {
            $query->expired();
        } elseif ($status !== 'all') {
            $query->active();
        }

        $column = in_array($sort, self::SORTABLE, true) ? $sort : 'id';
        $dir = $dir === 'asc' ? 'asc' : 'desc';

        $paginator = $query->orderBy($column, $dir)->paginate($perPage);

        $this->decorate($paginator);

        return $paginator;
    }

    /**
     * Avatar lookup plus a normalized three-state status - see
     * Punishment::displayStatus(). Both are presentation concerns the raw
     * plugin row doesn't carry on its own.
     *
     * @param  LengthAwarePaginator<int, Punishment>  $paginator
     */
    private function decorate(LengthAwarePaginator $paginator): void
    {
        $rows = $paginator->getCollection();

        if ($rows->isEmpty()) {
            return;
        }

        try {
            $profiles = SteamProfiles::many($rows->pluck('steamid')->unique()->all());
        } catch (Throwable) {
            $profiles = [];
        }

        $rows->transform(function (Punishment $row) use ($profiles): array {
            $data = $row->toArray();
            $data['avatar'] = $profiles[$row->steamid]['avatar'] ?? null;
            $data['status'] = $row->displayStatus();

            return $data;
        });
    }
}