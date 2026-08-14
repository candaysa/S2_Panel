<?php

namespace App\Modules\Ban\App\Services;

use App\Modules\Ban\App\Models\AdminBan;
use App\Modules\Ban\App\Models\AdminGag;
use App\Modules\Ban\App\Models\AdminMute;
use App\Modules\Ban\App\Models\AdminWarn;
use App\Modules\Ban\App\Models\Punishment;
use App\Support\SteamId;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use InvalidArgumentException;

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

        return $query->orderByDesc('id')->paginate($perPage);
    }
}