<?php

namespace App\Http\Controllers;

use App\Modules\Admin\App\Models\AdminAdmin;
use App\Modules\Ban\App\Models\AdminBan;
use App\Modules\Ban\App\Models\AdminMute;
use App\Modules\Rank\App\Models\RankPlayer;
use App\Modules\Server\App\Models\AdminServer;
use App\Support\Api;
use App\Support\ModuleRegistry;
use Illuminate\Http\JsonResponse;
use Throwable;

/**
 * Everything the dashboard shows, in one response.
 *
 * Deliberately not a module: the dashboard spans several of them and has to
 * keep working when any one is switched off. Each section is gated on its
 * module and wrapped individually, so a disabled Rank module or a plugin
 * whose tables are absent costs that one card - never the whole page.
 */
class DashboardController extends Controller
{
    public function __construct(private readonly ModuleRegistry $modules)
    {
    }

    public function index(): JsonResponse
    {
        return Api::success([
            'counts' => [
                'servers' => $this->count('server', fn (): int => AdminServer::query()->count()),
                'bans' => $this->count('ban', fn (): int => AdminBan::query()->active()->count()),
                'mutes' => $this->count('ban', fn (): int => AdminMute::query()->active()->count()),
                'admins' => $this->count('admin', fn (): int => AdminAdmin::query()->count()),
            ],
            'servers' => $this->section('server', fn (): array => AdminServer::query()
                ->orderBy('id')
                ->limit(25)
                ->get(['id', 'hostname', 'address'])
                ->all()),
            'ranks' => $this->section('rank', fn (): array => RankPlayer::query()
                ->orderByDesc('value')
                ->limit(10)
                ->get(['steam', 'name', 'value', 'rank', 'kills', 'deaths'])
                ->all()),
            'recent_bans' => $this->section('ban', fn (): array => AdminBan::query()
                ->orderByDesc('id')
                ->limit(8)
                ->get(['id', 'name', 'steamid', 'reason', 'admin_name', 'created_at', 'expires_at'])
                ->all()),
            'recent_mutes' => $this->section('ban', fn (): array => AdminMute::query()
                ->orderByDesc('id')
                ->limit(8)
                ->get(['id', 'name', 'steamid', 'reason', 'admin_name', 'created_at', 'expires_at'])
                ->all()),
        ]);
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
