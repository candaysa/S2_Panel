<?php

namespace App\Modules\Rank\App\Services;

use App\Modules\Audit\App\Services\AuditService;
use App\Modules\Rank\App\Models\RankHit;
use App\Modules\Rank\App\Models\RankPlayer;
use App\Support\CsRank;
use App\Support\SteamId;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use InvalidArgumentException;

/**
 * Rank module (C5) – reads the Swiftly CS2_Ranks tables and edits points.
 *
 * The plugin stores steam as a STEAM_0:x:y string; every lookup normalizes
 * the caller-provided SteamID (any of the three formats) through SteamId.
 * Points updates write straight to rank_base.value – the panel keeps an
 * audit trail in its own panel_logs (service layer log), never in the
 * plugin database (Decision 5).
 */
class RankService
{
    public function __construct(private readonly AuditService $audit)
    {
    }

    /**
     * Leaderboard – descending value (the plugin's own index order).
     *
     * Each row carries its true leaderboard position, not its offset in the
     * current page: paginating or searching must not renumber everyone from 1
     * again. The tier badge is attached here too, so the list and the profile
     * can never disagree about what rank a points total means.
     *
     * @return LengthAwarePaginator<int, RankPlayer>
     */
    public function leaderboard(?string $search = null, int $perPage = 25): LengthAwarePaginator
    {
        $query = RankPlayer::query()
            ->select('*')
            ->selectRaw('(SELECT COUNT(*) + 1 FROM rank_base AS peer WHERE peer.value > rank_base.value) AS position');

        if ($search !== null && $search !== '') {
            $query->where(function ($q) use ($search): void {
                $q->where('name', 'like', "%{$search}%");

                if (SteamId::isValid($search)) {
                    $q->orWhere('steam', SteamId::parse($search)->steamId2());
                }
            });
        }

        $players = $query->orderByDesc('value')->orderBy('steam')->paginate($perPage);

        $players->getCollection()->each(fn (RankPlayer $p) => $p->setAttribute('rank_tier', $this->tier($p)));

        return $players;
    }

    /**
     * @return array{key: string, index: int, group: string, tiers: int}
     */
    public function tier(RankPlayer $player): array
    {
        return CsRank::for((int) $player->value, (int) $player->rank);
    }

    /**
     * Full profile: rank_base row plus the optional rank_hits breakdown.
     *
     * @return array{player: RankPlayer, hits: RankHit|null}|null
     */
    public function profile(string $steamid): ?array
    {
        if (! SteamId::isValid($steamid)) {
            throw new InvalidArgumentException('invalid_steamid');
        }

        $steam = SteamId::parse($steamid)->steamId2();
        $player = RankPlayer::query()->where('steam', $steam)->first();

        if ($player === null) {
            return null;
        }

        $position = RankPlayer::query()->where('value', '>', (int) $player->value)->count() + 1;
        $player->setAttribute('position', $position);
        $player->setAttribute('rank_tier', $this->tier($player));

        return [
            'player' => $player,
            'hits' => RankHit::query()->where('SteamID', $steam)->first(),
        ];
    }

    /**
     * Set a player's points. The audit trail lives in panel_logs (log
     * service), never inside the plugin database.
     */
    public function updatePoints(string $steamid, int $value): RankPlayer
    {
        if (! SteamId::isValid($steamid)) {
            throw new InvalidArgumentException('invalid_steamid');
        }

        $steam = SteamId::parse($steamid)->steamId2();
        $player = RankPlayer::query()->where('steam', $steam)->first();

        if ($player === null) {
            throw new InvalidArgumentException('player_not_found');
        }

        $oldValue = (int) $player->value;
        $player->update(['value' => $value]);

        $this->audit->log('rank.points_updated', 'rank_player', $steam, [
            'old_value' => $oldValue,
            'new_value' => $value,
        ]);

        return $player->refresh();
    }
}