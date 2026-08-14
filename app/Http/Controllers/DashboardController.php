<?php

namespace App\Http\Controllers;

use App\Modules\Admin\App\Models\AdminAdmin;
use App\Modules\Ban\App\Models\AdminBan;
use App\Modules\Ban\App\Models\AdminMute;
use App\Modules\Rank\App\Models\RankPlayer;
use App\Modules\Server\App\Models\AdminServer;
use App\Support\Api;
use App\Support\Flags;
use App\Support\ModuleRegistry;
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
                'bans' => $this->count('ban', fn (): int => AdminBan::query()->active()->count()),
                'mutes' => $this->count('ban', fn (): int => AdminMute::query()->active()->count()),
                'admins' => $this->count('admin', fn (): int => AdminAdmin::query()->count()),
            ],
            // id/hostname/address were never real columns on admin_servers -
            // this silently returned an empty list on every load (masked by
            // section()'s catch-and-hide). hostname is a live A2S field
            // (ServerService::live()), deliberately not queried here: the
            // dashboard has to stay fast for an anonymous visitor, and firing
            // a live UDP probe per server on every public page view is an
            // easy amplification vector. The full /servers page already
            // shows live status for whoever actually wants it.
            'servers' => $this->section('server', fn (): array => AdminServer::query()
                ->orderBy('id')
                ->limit(25)
                ->get(['id', 'server_ip', 'server_port'])
                ->all()),
            'ranks' => $this->section('rank', fn (): array => RankPlayer::query()
                ->orderByDesc('value')
                ->limit(10)
                ->get(['steam', 'name', 'value', 'rank', 'kills', 'deaths'])
                ->all()),
            'recent_bans' => ! $canViewBanDetail ? [] : $this->section('ban', fn (): array => AdminBan::query()
                ->orderByDesc('id')
                ->limit(8)
                ->get(['id', 'name', 'steamid', 'reason', 'admin_name', 'created_at', 'expires_at'])
                ->all()),
            'recent_mutes' => ! $canViewBanDetail ? [] : $this->section('ban', fn (): array => AdminMute::query()
                ->orderByDesc('id')
                ->limit(8)
                ->get(['id', 'name', 'steamid', 'reason', 'admin_name', 'created_at', 'expires_at'])
                ->all()),
        ], ['can_view_ban_detail' => $canViewBanDetail]);
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
