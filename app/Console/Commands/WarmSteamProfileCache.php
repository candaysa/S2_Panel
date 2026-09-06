<?php

namespace App\Console\Commands;

use App\Modules\Admin\App\Models\AdminAdmin;
use App\Modules\Ban\App\Models\AdminBan;
use App\Modules\Ban\App\Models\AdminMute;
use App\Modules\Rank\App\Models\RankPlayer;
use App\Support\ModuleRegistry;
use App\Support\SteamProfiles;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Throwable;

/**
 * Pre-fetches Steam avatars/names for whoever Bans, Admins and the Ranks
 * leaderboard are about to show, so a real page load almost never pays the
 * cost of a live Steam API round trip itself.
 *
 * That cost is real and was the confirmed reason those three pages felt
 * slow to open: BanService::decorate(), AdminController::index() and
 * RankService::leaderboard() each call SteamProfiles::many() synchronously
 * on the request path, and measured against the live install, an id
 * SteamProfiles has not cached in the last 12h (see
 * SteamProfiles::CACHE_HOURS) costs 300-2500ms of Steam's own response
 * time - nothing on this panel's side is slow, Steam's API simply is not
 * fast from here. Scheduled hourly (bootstrap/app.php), comfortably inside
 * that 12h window, so a page almost never lands on a genuinely cold id.
 *
 * Deliberately its own command rather than a page-load side effect: warming
 * belongs off the request path entirely, on a schedule, not bolted onto
 * whichever request happens to run first after the cache expires.
 */
class WarmSteamProfileCache extends Command
{
    protected $signature = 'steam:warm-profiles';

    protected $description = 'Pre-fetch Steam profiles for recent bans/mutes, admins and the ranks leaderboard';

    /** How far each list reaches - matches or exceeds what its page actually displays. */
    private const BAN_LIMIT = 200;

    private const RANK_LIMIT = 200;

    public function handle(ModuleRegistry $modules): int
    {
        $ids = collect()
            ->merge($this->from($modules, 'ban', fn (): Collection => AdminBan::query()
                ->orderByDesc('id')->limit(self::BAN_LIMIT)->pluck('steamid')))
            ->merge($this->from($modules, 'ban', fn (): Collection => AdminMute::query()
                ->orderByDesc('id')->limit(self::BAN_LIMIT)->pluck('steamid')))
            ->merge($this->from($modules, 'admin', fn (): Collection => AdminAdmin::query()->pluck('steamid')))
            ->merge($this->from($modules, 'rank', fn (): Collection => RankPlayer::query()
                ->orderByDesc('value')->limit(self::RANK_LIMIT)->pluck('steam')))
            ->map(fn ($id): string => (string) $id)
            ->filter()
            ->unique()
            ->values()
            ->all();

        if ($ids === []) {
            $this->line('Nothing to warm - no enabled module produced any ids.');

            return self::SUCCESS;
        }

        SteamProfiles::many($ids);

        $this->info(count($ids).' Steam profile(s) warmed.');

        return self::SUCCESS;
    }

    /**
     * Module-gated and failure-tolerant, the same way DashboardController's
     * section() is - a plugin whose tables are absent, or a module the
     * owner has switched off, costs this command nothing and never fails
     * the whole run over one source.
     *
     * @param  callable(): Collection<int, mixed>  $query
     * @return Collection<int, mixed>
     */
    private function from(ModuleRegistry $modules, string $module, callable $query): Collection
    {
        if (! $modules->isEnabled($module)) {
            return collect();
        }

        try {
            return $query();
        } catch (Throwable) {
            return collect();
        }
    }
}
