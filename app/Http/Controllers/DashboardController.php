<?php

namespace App\Http\Controllers;

use App\Modules\Admin\App\Models\AdminAdmin;
use App\Modules\Ban\App\Models\AdminBan;
use App\Modules\Ban\App\Models\AdminMute;
use App\Modules\Rank\App\Models\RankPlayer;
use App\Modules\Server\App\Models\AdminServer;
use App\Modules\Server\App\Services\ServerService;
use App\Support\Api;
use App\Support\CsRank;
use App\Support\Flags;
use App\Support\ModuleRegistry;
use App\Support\SteamProfiles;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Throwable;

/**
 * Everything the dashboard shows, in one response.
 *
 * Deliberately not a module: the dashboard spans several of them and has to
 * keep working when any one is switched off. Each section is gated on its
 * module and wrapped individually, so a disabled Rank module or a plugin
 * whose tables are absent costs that one card - never the whole page.
 *
 * The page itself is public (see routes/web.php), so this endpoint has no
 * steam.auth in front of it either - a visitor should see server status and
 * activity before deciding to log in. Ban/mute detail is the exception: the
 * Ban module's own API deliberately requires a moderation flag to read
 * (app/Modules/Ban/Routes/api.php), so this mirrors that same gate rather
 * than quietly exposing the same records through a second door.
 */
class DashboardController extends Controller
{
    private const BAN_FLAGS = ['admin.ban', 'admin.mute', 'admin.kick', 'admin.generic'];

    public function __construct(private readonly ModuleRegistry $modules)
    {
    }

    public function index(): JsonResponse
    {
        $canViewBanDetail = $this->canViewBanDetail();

        return Api::success([
            'counts' => [
                'servers' => $this->count('server', fn (): int => AdminServer::query()->count()),
                // total + active, not active alone - a moderator reading
                // "522" with no context can't tell if that's every ban this
                // server has ever issued or just the ones still in force.
                'bans' => $this->countPair('ban', fn () => AdminBan::query()),
                'mutes' => $this->countPair('ban', fn () => AdminMute::query()),
                'admins' => $this->count('admin', fn (): int => AdminAdmin::query()->count()),
            ],
            // id/hostname/address were never real columns on admin_servers -
            // this silently returned an empty list on every load (masked by
            // section()'s catch-and-hide). hostname is a live A2S field, so
            // it comes from ServerService, which probes the whole set in one
            // parallel batch behind a short cache - cheap enough to run for
            // an anonymous visitor, unlike the old one-probe-per-server path.
            'servers' => $this->section('server', fn (): array => $this->serversWithLive()),
            'ranks' => $this->section('rank', fn (): array => $this->topPlayers()),
            // target_name, not name - AdminBan/AdminMute's real column
            // (see tests/Support/CreatesPluginTables.php for the schema).
            // Selecting a column that does not exist throws, which
            // section()'s catch-and-hide turned into a silent empty list -
            // these two cards showed "Nothing here yet" on every install
            // regardless of how many bans/mutes actually existed. Aliased
            // back to `name` since that's what the frontend already reads.
            'recent_bans' => ! $canViewBanDetail ? [] : $this->section('ban', fn (): array => AdminBan::query()
                ->orderByDesc('id')
                ->limit(6)
                ->get(['id', 'target_name as name', 'steamid', 'reason', 'admin_name', 'created_at', 'expires_at'])
                ->all()),
            'recent_mutes' => ! $canViewBanDetail ? [] : $this->section('ban', fn (): array => AdminMute::query()
                ->orderByDesc('id')
                ->limit(6)
                ->get(['id', 'target_name as name', 'steamid', 'reason', 'admin_name', 'created_at', 'expires_at'])
                ->all()),
        ], ['can_view_ban_detail' => $canViewBanDetail]);
    }

    /**
     * Server rows plus their live A2S state, newest-seen first.
     *
     * @return array<int, array<string, mixed>>
     */
    private function serversWithLive(): array
    {
        $servers = AdminServer::query()->visible()->orderBy('id')->limit(25)->get();
        $live = app(ServerService::class)->liveFor($servers);

        return $servers->map(fn (AdminServer $s): array => [
            'id' => $s->getKey(),
            'server_ip' => $s->server_ip,
            'server_port' => $s->server_port,
            'live' => $live[(int) $s->getKey()] ?? null,
            'online' => ($live[(int) $s->getKey()] ?? null) !== null,
        ])->all();
    }

    /**
     * Top ten by points, each with the tier its points earn - the same
     * mapping the leaderboard page uses, so the two never disagree.
     *
     * @return array<int, array<string, mixed>>
     */
    private function topPlayers(): array
    {
        $players = RankPlayer::query()
            ->orderByDesc('value')
            ->limit(10)
            ->get(['steam', 'name', 'value', 'rank', 'kills', 'deaths', 'playtime', 'lastconnect']);

        $profiles = SteamProfiles::many($players->pluck('steam')->all());

        return $players
            ->map(fn (RankPlayer $p): array => array_merge($p->toArray(), [
                'rank_tier' => CsRank::for((int) $p->value, (int) $p->rank),
                'avatar' => $profiles[$p->steam]['avatar'] ?? null,
            ]))
            ->all();
    }

    /**
     * Same rule the Ban module's own API enforces: a moderation flag, or
     * ownership. Fails closed - a guest or a plain logged-in player gets no
     * ban/mute records, matching what they'd get calling /api/bans directly.
     */
    private function canViewBanDetail(): bool
    {
        $user = Auth::user();

        if ($user === null) {
            return false;
        }

        if ($user->isOwner()) {
            return true;
        }

        try {
            return Flags::hasAnyFlag((int) $user->steam_id, self::BAN_FLAGS);
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * A count, or null when it cannot be produced.
     *
     * null and 0 are different answers and the UI renders them differently:
     * one is "no bans", the other is "this number is unavailable". Returning
     * 0 on failure would quietly report an empty panel as a working one.
     *
     * @param  callable(): int  $query
     */
    private function count(string $module, callable $query): ?int
    {
        if (! $this->modules->isEnabled($module)) {
            return null;
        }

        try {
            return $query();
        } catch (Throwable) {
            // Typically the plugin's tables are absent - the installer warns
            // about exactly this. Not worth a log line on every page load.
            return null;
        }
    }

    /**
     * Total and active counts for a punishment table (bans/mutes) in one
     * call - null (the whole pair, not per-field) on the same terms as
     * count() above: module off, or the query itself failing.
     *
     * @param  callable(): \Illuminate\Database\Eloquent\Builder  $baseQuery
     * @return array{total: int, active: int}|null
     */
    private function countPair(string $module, callable $baseQuery): ?array
    {
        if (! $this->modules->isEnabled($module)) {
            return null;
        }

        try {
            return [
                'total' => $baseQuery()->count(),
                'active' => $baseQuery()->active()->count(),
            ];
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @param  callable(): array<int, mixed>  $query
     * @return array<int, mixed>
     */
    private function section(string $module, callable $query): array
    {
        if (! $this->modules->isEnabled($module)) {
            return [];
        }

        try {
            return $query();
        } catch (Throwable) {
            return [];
        }
    }
}
