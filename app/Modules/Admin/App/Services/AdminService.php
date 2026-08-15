<?php

namespace App\Modules\Admin\App\Services;

use App\Modules\Admin\App\Models\AdminAdmin;
use App\Modules\Admin\App\Models\AdminGroup;
use App\Modules\Admin\App\Models\AdminPlaytime;
use App\Modules\Admin\Events\AdminCreated;
use App\Modules\Admin\Events\AdminDisabled;
use App\Modules\Admin\Events\AdminUpdated;
use App\Modules\Audit\App\Services\AuditService;
use App\Support\AdminPlugin\AdminManagerInterface;
use App\Support\Flags;
use App\Support\SteamId;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use Throwable;

/**
 * Admin management (C3) for the CS2_Admin plugin (admin_admins/
 * admin_groups). All mutations run through here so business rules, the flag
 * cache invalidation, audit rows and module events stay in one place.
 *
 * Project rule: NOTHING is ever deleted from the plugin database. "Removing"
 * an admin sets expires_at into the past – Swiftly and the panel ignore
 * expired rows, the row itself stays intact.
 *
 * See App\Support\AdminPlugin\SwiftlyAdminsService for the equivalent
 * against the official swiftlys2-plugins/admins schema - AdminServiceProvider
 * picks one to bind to AdminManagerInterface based on the `admin_plugin`
 * setting.
 */
class AdminService implements AdminManagerInterface
{
    public function __construct(private readonly AuditService $audit)
    {
    }

    /** Columns a caller may sort by, mapped to what actually gets ordered on. */
    private const SORTABLE = [
        'id' => 'admin_admins.id',
        'name' => 'admin_admins.name',
        'steamid' => 'admin_admins.steamid',
        'flags' => 'admin_admins.flags',
        'groups' => 'admin_admins.groups',
        'immunity' => 'admin_admins.immunity',
        'expires_at' => 'admin_admins.expires_at',
        'created_at' => 'admin_admins.created_at',
        'playtime' => 'admin_playtime.playtime_minutes',
    ];

    /**
     * List admins with optional name/steamid search, status filter and sort.
     *
     * @return LengthAwarePaginator<int, AdminAdmin>
     */
    public function list(
        ?string $search = null,
        ?bool $active = null,
        int $perPage = 25,
        string $sort = 'id',
        string $dir = 'desc',
    ): LengthAwarePaginator {
        $query = AdminAdmin::query();
        $column = self::SORTABLE[$sort] ?? self::SORTABLE['id'];
        $dir = $dir === 'asc' ? 'asc' : 'desc';

        // Sorting by playtime needs the same join list() -> playtimeFor()
        // would otherwise do as a second query; joining here instead of
        // filtering in PHP is what lets ORDER BY + LIMIT stay in the
        // database rather than sorting the whole table in memory.
        if ($sort === 'playtime') {
            try {
                if (\Illuminate\Support\Facades\Schema::connection('swiftly')->hasTable('admin_playtime')) {
                    $query->leftJoin('admin_playtime', 'admin_playtime.steamid', '=', 'admin_admins.steamid')
                        ->select('admin_admins.*');
                } else {
                    $column = self::SORTABLE['id'];
                }
            } catch (Throwable) {
                $column = self::SORTABLE['id'];
            }
        }

        if ($search !== null && $search !== '') {
            $query->where(function ($q) use ($search): void {
                $q->where('admin_admins.name', 'like', "%{$search}%");

                if (SteamId::isValid($search)) {
                    $q->orWhere('admin_admins.steamid', (int) SteamId::parse($search)->steamId64());
                }
            });
        }

        if ($active === true) {
            $query->where(function ($q): void {
                $q->whereNull('admin_admins.expires_at')->orWhere('admin_admins.expires_at', '>', now());
            });
        } elseif ($active === false) {
            $query->where(function ($q): void {
                $q->whereNotNull('admin_admins.expires_at')->where('admin_admins.expires_at', '<=', now());
            });
        }

        return $query->orderBy($column, $dir)->paginate($perPage);
    }

    /**
     * Attach admin_playtime.playtime_minutes to a page of admins, keyed by
     * steamid.
     *
     * admin_playtime is a separate Swiftly plugin table - some installs run
     * it, some don't - so a missing table degrades to "no playtime data"
     * rather than a 500. One query for the whole page, not one per row.
     *
     * @param  iterable<AdminAdmin>  $admins
     * @return array<string, int>
     */
    public function playtimeFor(iterable $admins): array
    {
        try {
            if (! Schema::connection('swiftly')->hasTable('admin_playtime')) {
                return [];
            }

            $ids = collect($admins)->pluck('steamid')->map(fn ($v) => (string) $v)->all();

            return AdminPlaytime::query()
                ->whereIn('steamid', $ids)
                ->pluck('playtime_minutes', 'steamid')
                ->all();
        } catch (Throwable) {
            return [];
        }
    }

    public function groups(): Collection
    {
        $groups = AdminGroup::query()->orderBy('name')->get();

        // One count query for every group's membership rather than N+1 - a
        // FIND_IN_SET per group against the (typically small) admin table.
        $counts = $groups->mapWithKeys(function (AdminGroup $g): array {
            return [$g->name => AdminAdmin::query()->whereRaw('FIND_IN_SET(?, groups)', [$g->name])->count()];
        });

        return $groups->map(function (AdminGroup $g) use ($counts): array {
            $g->setAttribute('member_count', $counts[$g->name] ?? 0);

            return $g->toArray();
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function createGroup(array $data): array
    {
        $name = trim((string) ($data['name'] ?? ''));

        if ($name === '') {
            throw new InvalidArgumentException('group_name_required');
        }

        if (AdminGroup::query()->where('name', $name)->exists()) {
            throw new InvalidArgumentException('group_already_exists');
        }

        $group = AdminGroup::query()->create([
            'name' => $name,
            'flags' => $this->csv($data['flags'] ?? null),
            'immunity' => (int) ($data['immunity'] ?? 0),
        ]);

        $this->audit->log('admin_group.created', 'admin_group', $name, [
            'flags' => $group->flags,
            'immunity' => $group->immunity,
        ]);

        return $group->toArray();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function updateGroup(string $name, array $data): array
    {
        $group = AdminGroup::query()->where('name', $name)->first();

        if ($group === null) {
            throw new InvalidArgumentException('group_not_found');
        }

        $group->update([
            'flags' => $this->csv($data['flags'] ?? $group->flags),
            'immunity' => (int) ($data['immunity'] ?? $group->immunity),
        ]);

        // Every admin carrying this group has its flags cached; the group's
        // own flags just changed underneath them, so those caches are now
        // wrong until they naturally expire. Forgetting them here is the
        // difference between "takes effect now" and "takes effect in five
        // minutes, silently".
        AdminAdmin::query()
            ->whereRaw('FIND_IN_SET(?, groups)', [$name])
            ->pluck('steamid')
            ->each(fn ($id) => Flags::forget((int) $id));

        $this->audit->log('admin_group.updated', 'admin_group', $name, [
            'flags' => $group->flags,
            'immunity' => $group->immunity,
        ]);

        return $group->refresh()->toArray();
    }

    /**
     * Groups are deleted outright, unlike admins - a group has no history of
     * its own worth preserving, and Swiftly treats an admin's reference to a
     * deleted group name as simply absent.
     */
    public function deleteGroup(string $name): void
    {
        $group = AdminGroup::query()->where('name', $name)->first();

        if ($group === null) {
            throw new InvalidArgumentException('group_not_found');
        }

        $members = AdminAdmin::query()->whereRaw('FIND_IN_SET(?, groups)', [$name])->pluck('steamid');

        $group->delete();

        $members->each(fn ($id) => Flags::forget((int) $id));

        $this->audit->log('admin_group.deleted', 'admin_group', $name);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): array
    {
        $steamId64 = (int) SteamId::parse((string) $data['steamid'])->steamId64();

        if (AdminAdmin::query()->where('steamid', $steamId64)->exists()) {
            throw new InvalidArgumentException('admin_already_exists');
        }

        $admin = AdminAdmin::query()->create([
            'steamid' => $steamId64,
            'name' => $data['name'],
            'flags' => $this->csv($data['flags'] ?? null),
            'groups' => $this->csv($data['groups'] ?? null),
            'immunity' => (int) ($data['immunity'] ?? 0),
            'expires_at' => $data['expires_at'] ?? null,
        ]);

        Flags::forget($steamId64);
        $this->audit->log('admin.created', 'admin', (string) $steamId64, $this->auditDetails($admin));
        event(new AdminCreated($admin));

        return $admin->toArray();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(int $id, array $data): array
    {
        $admin = AdminAdmin::query()->find($id);

        if ($admin === null) {
            throw new InvalidArgumentException('admin_not_found');
        }

        $oldSteamId64 = (int) $admin->steamid;
        $steamId64 = isset($data['steamid'])
            ? (int) SteamId::parse((string) $data['steamid'])->steamId64()
            : (int) $admin->steamid;

        if ($steamId64 !== (int) $admin->steamid && AdminAdmin::query()->where('steamid', $steamId64)->exists()) {
            throw new InvalidArgumentException('admin_already_exists');
        }

        $admin->update([
            'steamid' => $steamId64,
            'name' => $data['name'] ?? $admin->name,
            'flags' => $this->csv($data['flags'] ?? $admin->flags),
            'groups' => $this->csv($data['groups'] ?? $admin->groups),
            'immunity' => (int) ($data['immunity'] ?? $admin->immunity),
            'expires_at' => array_key_exists('expires_at', $data) ? $data['expires_at'] : $admin->expires_at,
        ]);

        // Invalidate both old and new cache keys – the steamid may have moved.
        Flags::forget($oldSteamId64);
        Flags::forget($steamId64);
        $this->audit->log('admin.updated', 'admin', (string) $steamId64, $this->auditDetails($admin));
        event(new AdminUpdated($admin));

        return $admin->refresh()->toArray();
    }

    /**
     * Disable an admin without deleting the row (project rule).
     */
    public function disable(int $id): array
    {
        $admin = AdminAdmin::query()->find($id);

        if ($admin === null) {
            throw new InvalidArgumentException('admin_not_found');
        }

        $admin->update(['expires_at' => now()->subSecond()]);

        Flags::forget((int) $admin->steamid);
        $this->audit->log('admin.disabled', 'admin', (string) $admin->steamid, $this->auditDetails($admin));
        event(new AdminDisabled($admin));

        return $admin->refresh()->toArray();
    }

    /**
     * Normalize a CSV input (string "a,b" or array) into a compact string.
     */
    private function csv(string|array|null $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        $items = is_array($value) ? $value : explode(',', (string) $value);

        $items = array_values(array_filter(array_map('trim', $items), fn (string $v): bool => $v !== ''));

        return $items === [] ? null : implode(',', $items);
    }

    /**
     * @return array<string, mixed>
     */
    private function auditDetails(AdminAdmin $admin): array
    {
        return [
            'steamid' => (string) $admin->steamid,
            'name' => $admin->name,
            'flags' => $admin->flags,
            'groups' => $admin->groups,
            'immunity' => $admin->immunity,
            'expires_at' => $admin->expires_at?->toDateTimeString(),
        ];
    }
}