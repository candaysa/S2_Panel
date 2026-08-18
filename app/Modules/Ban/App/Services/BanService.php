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
 * Search accepts a name or any SteamID format and matches either side of
 * the punishment - the player who received it or the admin who issued it -
 * so an admin's SteamID lists what they have handed out. SteamIDs are
 * converted to SteamID64 so all three plugin formats resolve to the same
 * row.
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
            // Both sides of the punishment, not just the player who received
            // it. "What has this admin been handing out?" is a routine
            // question - reviewing a new moderator, or checking a complaint
            // about one - and answering it previously meant reading every
            // page by eye because only the target was searchable.
            //
            // target_name only really exists on admin_bans - confirmed
            // against the live schema, where admin_mutes/gags/warns carry a
            // steamid for the target but never captured a display name for
            // one. Querying a column a table does not have is a hard SQL
            // error, not an empty result, so this is conditional per type
            // rather than assumed universal the way it first shipped.
            $hasTargetName = $type === 'ban';

            $query->where(function ($q) use ($search, $hasTargetName): void {
                if ($hasTargetName) {
                    $q->where('target_name', 'like', "%{$search}%");
                }

                $q->orWhere('admin_name', 'like', "%{$search}%");

                if (SteamId::isValid($search)) {
                    $steam64 = (int) SteamId::parse($search)->steamId64();
                    $q->orWhere('steamid', $steam64)
                        ->orWhere('admin_steamid', $steam64);
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
            $profile = $profiles[$row->steamid] ?? null;
            $data['avatar'] = $profile['avatar'] ?? null;

            // target_name is whatever the plugin captured at the moment of
            // the punishment - for one issued from RCON/console against a
            // player with no cached persona name, it stores the SteamID
            // itself as a placeholder rather than leaving the column empty
            // (confirmed live: 130/587 bans on this install, every one
            // admin_name="Konsol"). The player's own profile page already
            // shows their real name via a live lookup; this is the same
            // fallback, applied here too rather than only there.
            if ($profile !== null && $this->looksLikeSteamId((string) ($data['target_name'] ?? ''), $row->steamid)) {
                $data['target_name'] = $profile['name'] ?? $data['target_name'];
            }

            $data['status'] = $row->displayStatus();

            return $data;
        });
    }

    /**
     * True when a stored name is empty, or is literally the row's own
     * SteamID rendered as text (in whatever of the three common formats)
     * rather than an actual persona name.
     */
    private function looksLikeSteamId(string $name, string $steamId64): bool
    {
        if ($name === '' || $name === $steamId64) {
            return true;
        }

        try {
            return SteamId::isValid($name) && SteamId::parse($name)->steamId64() === $steamId64;
        } catch (Throwable) {
            return false;
        }
    }
}