<?php

namespace App\Modules\Stats\App\Services;

use App\Modules\Server\App\Models\AdminServer;
use App\Modules\Server\App\Services\ServerService;
use App\Modules\Stats\App\Models\ServerStat;
use App\Support\SteamId;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Stats module (C12) – player profile (rank_base), server history
 * (panel-owned server_stats samples) and dashboard numbers.
 *
 * Reads the plugin tables read-only; writes only into its own
 * server_stats table via the collect() sampler.
 */
class StatsService
{
    public function __construct(private readonly ServerService $servers)
    {
    }

    /**
     * Dashboard totals across the three sources.
     *
     * @return array<string, int>
     */
    public function dashboard(): array
    {
        return [
            'total_players' => (int) DB::connection('ranks')->table('rank_base')->count(),
            'active_bans' => (int) DB::connection('swiftly')
                ->table('admin_bans')
                ->where(fn ($q) => $q->whereNull('status')->orWhere('status', 'active'))
                ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))
                ->count(),
            'total_servers' => (int) DB::connection('swiftly')->table('admin_servers')->count(),
            'open_tickets' => (int) DB::table('reports')->where('status', 'open')->count(),
            'total_users' => (int) DB::table('users')->count(),
        ];
    }

    /**
     * Player profile from the plugin's rank_base (read-only), plus a few
     * computed stats (K/D, headshot rate).
     *
     * @return array<string, mixed>|null
     */
    public function playerProfile(string $steamid): ?array
    {
        if (! SteamId::isValid($steamid)) {
            throw new InvalidArgumentException('invalid_steamid');
        }

        $row = DB::connection('ranks')
            ->table('rank_base')
            ->where('steam', SteamId::parse($steamid)->steamId2())
            ->first();

        if ($row === null) {
            return null;
        }

        $kills = (int) $row->kills;
        $deaths = (int) $row->deaths;
        $headshots = (int) $row->headshots;

        return [
            'steam' => (string) $row->steam,
            'name' => (string) $row->name,
            'value' => (int) $row->value,
            'rank' => (int) $row->rank,
            'kills' => $kills,
            'deaths' => $deaths,
            'assists' => (int) $row->assists,
            'headshots' => $headshots,
            'headshot_rate' => $kills > 0 ? round($headshots / $kills, 4) : 0.0,
            'kd_ratio' => $deaths > 0 ? round($kills / $deaths, 2) : (float) $kills,
            'playtime' => (int) $row->playtime,
            'damage' => (int) $row->damage,
            'game_wins' => (int) $row->game_wins,
            'game_losses' => (int) $row->game_losses,
            'games_played' => (int) $row->games_played,
            'lastconnect' => (int) $row->lastconnect,
        ];
    }

    /**
     * Recent A2S samples for one server (ascending by time, newest last).
     *
     * @return array<int, array{recorded_at: string, players: int, max_players: int, map: string|null}>
     */
    public function serverHistory(int $serverId, int $hours = 24, int $limit = 500): array
    {
        return ServerStat::query()
            ->where('server_id', $serverId)
            ->where('recorded_at', '>=', now()->subHours(max(1, $hours)))
            ->orderBy('recorded_at')
            ->limit(max(1, min($limit, 2000)))
            ->get()
            ->map(fn (ServerStat $sample): array => [
                'recorded_at' => $sample->recorded_at?->toIso8601String(),
                'players' => $sample->players,
                'max_players' => $sample->max_players,
                'map' => $sample->map,
            ])
            ->all();
    }

    /**
     * Sample every registered server once via A2S and append one row per
     * reachable server to server_stats. Returns how many samples were
     * recorded (offline servers are skipped, never recorded as zero rows).
     */
    public function collect(): int
    {
        $recorded = 0;

        foreach (AdminServer::query()->get() as $server) {
            $live = $this->servers->live($server);

            if ($live === null) {
                continue;
            }

            ServerStat::query()->create([
                'server_id' => $server->id,
                'players' => $live['players'],
                'max_players' => $live['max_players'],
                'map' => $live['map'] !== '' ? $live['map'] : null,
                'recorded_at' => now(),
            ]);

            $recorded++;
        }

        return $recorded;
    }
}