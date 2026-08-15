<?php

namespace App\Modules\Rank\App\Services;

use App\Modules\Audit\App\Services\AuditService;
use App\Modules\Rank\App\Models\RankHit;
use App\Modules\Rank\App\Models\RankPlayer;
use App\Support\CsRank;
use App\Support\SteamId;
use App\Support\SteamProfiles;
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

    /** Columns a caller may sort the VIEW by. Standing (`position`) never changes. */
    private const SORTABLE = ['value', 'name', 'kills', 'deaths', 'playtime'];

    /**
     * Leaderboard, viewable in any column order but always numbered by true
     * standing.
     *
     * `position` is computed from points alone regardless of `$sort` - it
     * answers "where does this player actually rank", which stays true no
     * matter which column the table is currently displayed sorted by.
     * Sorting the view by kills and having row 1 read "#847" is correct: it
     * says the top killer is the server's 847th-best player by points, which
     * is exactly the kind of thing sorting by kills is for surfacing.
     * Paginating or searching must not renumber anyone from 1 either way.
     * The tier badge is attached here too, so the list and the profile can
     * never disagree about what rank a points total means.
     *
     * @return LengthAwarePaginator<int, RankPlayer>
     */
    public function leaderboard(
        ?string $search = null,
        int $perPage = 25,
        string $sort = 'value',
        string $dir = 'desc',
    ): LengthAwarePaginator {
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

        $column = in_array($sort, self::SORTABLE, true) ? $sort : 'value';
        $dir = $dir === 'asc' ? 'asc' : 'desc';

        $players = $query->orderBy($column, $dir)->orderBy('steam')->paginate($perPage);

        // One Steam API call for the whole page rather than one per row -
        // see SteamProfiles. Missing profiles simply render without an
        // avatar, which is why this never blocks the response.
        $profiles = SteamProfiles::many($players->getCollection()->pluck('steam')->all());

        $players->getCollection()->each(function (RankPlayer $p) use ($profiles): void {
            $p->setAttribute('rank_tier', $this->tier($p));
            $p->setAttribute('avatar', $profiles[$p->steam]['avatar'] ?? null);
            $p->setAttribute('profile_url', $profiles[$p->steam]['profile_url'] ?? null);
        });

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

        $profile = SteamProfiles::many([$steam])[$steam] ?? null;
        $player->setAttribute('avatar', $profile['avatar'] ?? null);
        $player->setAttribute('profile_url', $profile['profile_url'] ?? null);

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