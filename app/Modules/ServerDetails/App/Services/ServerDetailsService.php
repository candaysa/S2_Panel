<?php

namespace App\Modules\ServerDetails\App\Services;

use App\Modules\Rank\App\Models\RankPlayer;
use App\Modules\Server\App\Models\AdminServer;
use App\Modules\Server\App\Services\ServerService;
use App\Modules\ServerDetails\App\Models\ServerStat;
use App\Support\A2s;
use Carbon\Carbon;
use Illuminate\Support\Carbon as SupportCarbon;
use Throwable;

/**
 * Per-server history + live player list (C21).
 *
 * History is 5-minute samples (see SampleServerStats, scheduled) recorded
 * for every server regardless of visibility - a server hidden from the
 * public list today may be shown again later, and its chart should not
 * have a hole where it was hidden. Offline ticks are recorded as
 * players=0 rather than skipped, so the chart reads as a continuous
 * timeline instead of gaps that look like missing data.
 */
class ServerDetailsService
{
    public function __construct(private readonly ServerService $servers)
    {
    }

    /**
     * One row per known server, taken in a single parallel A2S batch via
     * ServerService (same probe the dashboard/server list already pay for -
     * this just also persists it).
     */
    public function sampleAll(): void
    {
        $servers = AdminServer::query()->get();

        if ($servers->isEmpty()) {
            return;
        }

        $live = $this->servers->liveFor($servers);
        $now = now();

        $rows = $servers->map(fn (AdminServer $server): array => [
            'server_id' => (int) $server->getKey(),
            'players' => (int) ($live[(int) $server->getKey()]['players'] ?? 0),
            'max_players' => (int) ($live[(int) $server->getKey()]['max_players'] ?? 0),
            'map' => $live[(int) $server->getKey()]['map'] ?? null,
            'sampled_at' => $now,
        ])->all();

        ServerStat::query()->insert($rows);
    }

    /**
     * Drops samples old enough that nothing still reads them - the oldest
     * chart range is 12 months, so nothing before that is ever queried.
     * A little slack (13 months) avoids trimming the tail end of that
     * range on the day it is requested.
     */
    public function prune(): void
    {
        ServerStat::query()->where('sampled_at', '<', now()->subMonths(13))->delete();
    }

    /**
     * @return array<int, array{players: int, max_players: int, map: ?string, at: string}>
     */
    public function statsFor(int $serverId, string $range): array
    {
        return match ($range) {
            '12h' => $this->rawSamples($serverId, now()->subHours(12)),
            '1w' => $this->bucketed($serverId, now()->subWeek(), "DATE_FORMAT(sampled_at, '%Y-%m-%d %H:00:00')"),
            '12m' => $this->bucketed($serverId, now()->subMonths(12), 'DATE(sampled_at)'),
            default => $this->rawSamples($serverId, now()->subHour()),
        };
    }

    /**
     * @return array<int, array{name: string, score: int, duration_seconds: int, steam: ?string}>|null null when the server did not answer
     */
    public function livePlayers(AdminServer $server): ?array
    {
        $players = A2s::players(
            trim((string) $server->server_ip),
            (int) $server->server_port,
            (float) config('server.a2s_timeout', 2.0),
        );

        if ($players === null) {
            return null;
        }

        $rows = collect($players)
            ->sortByDesc('duration')
            ->values()
            ->map(fn (array $p): array => [
                'name' => (string) $p['name'],
                'score' => (int) $p['score'],
                'duration_seconds' => (int) round((float) $p['duration']),
            ]);

        $steamByName = $this->steamByName($rows->pluck('name')->all());

        return $rows
            ->map(fn (array $p): array => [...$p, 'steam' => $steamByName[$p['name']] ?? null])
            ->all();
    }

    /**
     * Best-effort name -> steamid lookup against the Rank module's own
     * player table, so a live player's name in this list can link to their
     * /players/{steam} profile - the A2S query protocol this list is built
     * from (see livePlayers() above) has no concept of a SteamID at all,
     * only a display name, so there is no exact source to join on.
     *
     * Deliberately conservative: only names that match exactly one
     * lvl_base row are linked. A shared or default in-game name (multiple
     * players, or none at all) leaves those rows unlinked rather than
     * guessing which player it actually is. The Rank module being
     * disabled, or genuinely absent, degrades to "nothing links" the same
     * way every other optional Rank integration in this panel does.
     *
     * @param  list<string>  $names
     * @return array<string, string> name -> steam
     */
    private function steamByName(array $names): array
    {
        $names = array_values(array_unique(array_filter($names, fn (string $n): bool => $n !== '')));

        if ($names === []) {
            return [];
        }

        try {
            return $this->uniqueSteamByName(RankPlayer::query()->whereIn('name', $names)->get(['name', 'steam']));
        } catch (Throwable) {
            // One name in the batch can abort the whole IN (...) query -
            // confirmed live: a 4-byte-UTF8 display name (mathematical
            // bold letters) against lvl_base's own column collation threw
            // "Illegal mix of collations for operation 'in'" and silently
            // blocked every other name in the same player list, not just
            // the one actually responsible. Falling back to one query per
            // name means only that one name fails to link.
            $result = [];

            foreach ($names as $name) {
                try {
                    $result += $this->uniqueSteamByName(RankPlayer::query()->where('name', $name)->get(['name', 'steam']));
                } catch (Throwable) {
                    // This name's own collation clash (or anything else) - just skip it.
                }
            }

            return $result;
        }
    }

    /**
     * @param  \Illuminate\Support\Collection<int, RankPlayer>  $rows
     * @return array<string, string>
     */
    private function uniqueSteamByName($rows): array
    {
        return $rows->groupBy('name')
            ->filter(fn ($group) => $group->count() === 1)
            ->map(fn ($group) => $group->first()->steam)
            ->all();
    }

    /**
     * @return array<int, array{players: int, max_players: int, map: ?string, at: string}>
     */
    private function rawSamples(int $serverId, Carbon|SupportCarbon $since): array
    {
        return ServerStat::query()
            ->where('server_id', $serverId)
            ->where('sampled_at', '>=', $since)
            ->orderBy('sampled_at')
            ->get(['players', 'max_players', 'map', 'sampled_at'])
            ->map(fn (ServerStat $s): array => [
                'players' => $s->players,
                'max_players' => $s->max_players,
                'map' => $s->map,
                'at' => $s->sampled_at->toIso8601String(),
            ])
            ->all();
    }

    /**
     * Averages players into fixed buckets at the database level - the
     * 12-month range covers roughly 105k raw 5-minute samples per server,
     * far too many to pull into PHP just to group them there.
     *
     * @return array<int, array{players: int, max_players: int, map: null, at: string}>
     */
    private function bucketed(int $serverId, Carbon|SupportCarbon $since, string $bucketExpr): array
    {
        return ServerStat::query()
            ->where('server_id', $serverId)
            ->where('sampled_at', '>=', $since)
            ->selectRaw("{$bucketExpr} as bucket, ROUND(AVG(players)) as players, MAX(max_players) as max_players, MAX(sampled_at) as last_sampled_at")
            ->groupBy('bucket')
            ->orderBy('bucket')
            ->get()
            ->map(fn ($row): array => [
                'players' => (int) $row->players,
                'max_players' => (int) $row->max_players,
                'map' => null,
                'at' => Carbon::parse($row->last_sampled_at)->toIso8601String(),
            ])
            ->all();
    }
}
