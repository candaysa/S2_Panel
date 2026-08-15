<?php

namespace App\Modules\Vip\App\Services;

use App\Modules\Admin\App\Models\AdminPlaytime;
use App\Modules\Audit\App\Services\AuditService;
use App\Modules\Vip\App\Models\VipServer;
use App\Modules\Vip\App\Models\VipUser;
use App\Models\User;
use App\Support\SteamId;
use App\Support\SteamProfiles;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use InvalidArgumentException;
use Throwable;

/**
 * VIP module (C7) - reads and writes VIPCore's vip_users table
 * (https://github.com/SwiftlyS2-Plugins/VIPCore).
 *
 * VIP group DEFINITIONS (weight, per-feature values) live in VIPCore's own
 * server-side config file, not in the database - the panel has no way to
 * enumerate them, so "group" is accepted as free text here, same as
 * VIPCore's own admin commands. account_id is VIPCore's primary player key
 * (SteamID32/AccountID, not SteamID64); every lookup normalizes the
 * caller-provided SteamID (any of the three formats) through SteamId.
 *
 * grant() upserts by (account_id, sid, group) - VIPCore's own
 * UserRepository.AddUserAsync does the same thing (insert, fall back to
 * update on a duplicate-key error); using the query builder's upsert()
 * gets the identical result as a single atomic statement instead.
 */
class VipService
{
    public function __construct(private readonly AuditService $audit)
    {
    }

    /**
     * @return LengthAwarePaginator<int, array<string, mixed>>
     */
    public function listUsers(?int $serverId = null, ?string $search = null, int $perPage = 25): LengthAwarePaginator
    {
        $query = VipUser::query();

        if ($serverId !== null) {
            $query->where('sid', $serverId);
        }

        if ($search !== null && $search !== '') {
            $query->where(function ($q) use ($search): void {
                $q->where('name', 'like', "%{$search}%");

                if (SteamId::isValid($search)) {
                    $q->orWhere('account_id', SteamId::parse($search)->accountId());
                }
            });
        }

        $paginator = $query->orderByDesc('expires')->paginate($perPage);

        $this->enrich($paginator->getCollection());

        return $paginator;
    }

    /**
     * Overlays avatar (Steam Web API, bulk) and playtime (Swiftly
     * admin_playtime, bulk) onto each row, and derives active/expired from
     * `expires` - VIPCore's own "0 = never expires" convention.
     *
     * @param  Collection<int, VipUser>  $users
     */
    private function enrich(Collection $users): void
    {
        if ($users->isEmpty()) {
            return;
        }

        $ids64 = $users->map(fn (VipUser $u): string => SteamId::fromAccountId((int) $u->account_id)->steamId64())->all();

        try {
            $profiles = SteamProfiles::many($ids64);
        } catch (Throwable) {
            $profiles = [];
        }

        try {
            $playtime = AdminPlaytime::query()->whereIn('steamid', $ids64)->pluck('playtime_minutes', 'steamid');
        } catch (Throwable) {
            // admin_playtime is a separate, optional plugin table.
            $playtime = collect();
        }

        $now = now()->timestamp;

        $users->transform(function (VipUser $u) use ($profiles, $playtime, $now): array {
            $id64 = SteamId::fromAccountId((int) $u->account_id)->steamId64();
            $row = $u->toArray();
            $row['avatar'] = $profiles[$id64]['avatar'] ?? null;
            $row['playtime_minutes'] = (int) ($playtime[$id64] ?? 0);
            $row['active'] = $u->expires === 0 || $u->expires > $now;

            return $row;
        });
    }

    /**
     * @return Collection<int, VipUser>
     */
    public function groupsFor(string $steamId, ?int $serverId = null): Collection
    {
        if (! SteamId::isValid($steamId)) {
            throw new InvalidArgumentException('invalid_steamid');
        }

        $query = VipUser::query()->where('account_id', SteamId::parse($steamId)->accountId());

        if ($serverId !== null) {
            $query->where('sid', $serverId);
        }

        return $query->get();
    }

    /**
     * Read-only: server IDs/GUIDs are owned by VIPCore's own runtime.
     *
     * @return Collection<int, VipServer>
     */
    public function listServers(): Collection
    {
        return VipServer::query()->orderBy('serverId')->get();
    }

    /**
     * Grant (or extend/replace) one VIP group for a player on one server.
     *
     * @param  int|null  $expiresAt  Unix timestamp, or null for "never expires"
     *                               (VIPCore's own convention: expires = 0).
     */
    public function grant(string $steamId, string $playerName, string $group, int $serverId, ?int $expiresAt, User $actor): VipUser
    {
        if (! SteamId::isValid($steamId)) {
            throw new InvalidArgumentException('invalid_steamid');
        }

        if (! VipServer::query()->whereKey($serverId)->exists()) {
            throw new InvalidArgumentException('server_not_found');
        }

        $accountId = SteamId::parse($steamId)->accountId();
        $expires = $expiresAt ?? 0;

        VipUser::query()->upsert(
            [[
                'account_id' => $accountId,
                'name' => $playerName,
                'lastvisit' => now()->timestamp,
                'sid' => $serverId,
                'group' => $group,
                'expires' => $expires,
            ]],
            ['account_id', 'sid', 'group'],
            ['name', 'expires', 'lastvisit'],
        );

        $this->audit->log('vip.granted', 'vip_user', (string) $accountId, [
            'account_id' => $accountId,
            'server_id' => $serverId,
            'group' => $group,
            'expires' => $expires,
        ]);

        /** @var VipUser $row */
        $row = VipUser::query()
            ->where('account_id', $accountId)
            ->where('sid', $serverId)
            ->where('group', $group)
            ->firstOrFail();

        return $row;
    }

    /**
     * Revoke one VIP group for a player on one server.
     */
    public function revoke(string $steamId, int $serverId, string $group, User $actor): bool
    {
        if (! SteamId::isValid($steamId)) {
            throw new InvalidArgumentException('invalid_steamid');
        }

        $accountId = SteamId::parse($steamId)->accountId();

        $deleted = VipUser::query()
            ->where('account_id', $accountId)
            ->where('sid', $serverId)
            ->where('group', $group)
            ->delete();

        if ($deleted === 0) {
            return false;
        }

        $this->audit->log('vip.revoked', 'vip_user', (string) $accountId, [
            'account_id' => $accountId,
            'server_id' => $serverId,
            'group' => $group,
        ]);

        return true;
    }
}
