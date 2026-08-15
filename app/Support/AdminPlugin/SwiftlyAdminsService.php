<?php

namespace App\Support\AdminPlugin;

use App\Modules\Audit\App\Services\AuditService;
use App\Support\Flags;
use App\Support\SteamId;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Throwable;

/**
 * Admin/group management (C3, C9) for the official swiftlys2-plugins/admins
 * plugin - `admins`/`groups`/`servers` tables on the 'swiftly' connection,
 * PascalCase columns, Permissions/Groups/Servers stored as JSON array text
 * (Admins.Core/src/DB/Models/Admin.cs upstream).
 *
 * PARTIALLY VERIFIED: written from reading the plugin's public source (no
 * live instance was available), then exercised end-to-end against a
 * synthetic MySQL schema built from the plugin's own FluentMigrator
 * migrations (see verify-admin-plugin.php in the 2026-08-15 deploy) - every
 * method here, JSON encode/decode, and the JSON_CONTAINS group-membership
 * query all ran successfully against real MySQL. What that does NOT cover:
 * the actual running plugin's in-game behaviour, its exact RCON duration
 * format, and whether populating every known server GUID on create (see
 * below) matches what an admin actually needs. Confirm against a real
 * server before depending on this for moderation.
 *
 * Two structural differences from CS2_Admin worth knowing before reading
 * this file:
 *   - No expires_at column exists, so disable() deletes the row outright
 *     instead of the CS2_Admin project's soft-disable convention - there is
 *     nothing to soft-disable with. This matches the plugin's own
 *     `admins remove` console command, which deletes once an admin has no
 *     server left (see Admins.Core/src/Commands/Admins.cs upstream).
 *   - Every admin row carries a `Servers` array of GUIDs scoping which
 *     SwiftlyS2 server instance it applies to; a row created with no
 *     matching GUID does not work in-game at all. create()/update() write
 *     every GUID currently found in the plugin's own `servers` table (see
 *     allServerGuids()), so an admin created here applies everywhere the
 *     plugin has already registered a server - the closest match to
 *     CS2_Admin's admins-apply-everywhere behaviour, which has no
 *     per-server scoping concept to begin with.
 */
class SwiftlyAdminsService implements AdminManagerInterface
{
    private const TABLE_ADMINS = 'admins';

    private const TABLE_GROUPS = 'groups';

    private const TABLE_SERVERS = 'servers';

    /** Columns a caller may sort by, mapped to the actual column name. */
    private const SORTABLE = [
        'id' => 'Id',
        'name' => 'Username',
        'steamid' => 'SteamId64',
        'immunity' => 'Immunity',
    ];

    public function __construct(private readonly AuditService $audit)
    {
    }

    public function list(
        ?string $search,
        ?bool $active,
        int $perPage,
        string $sort,
        string $dir,
    ): LengthAwarePaginator {
        $query = DB::connection('swiftly')->table(self::TABLE_ADMINS);
        $column = self::SORTABLE[$sort] ?? self::SORTABLE['id'];
        $dir = $dir === 'asc' ? 'asc' : 'desc';

        if ($search !== null && $search !== '') {
            $query->where(function ($q) use ($search): void {
                $q->where('Username', 'like', "%{$search}%");

                if (SteamId::isValid($search)) {
                    $q->orWhere('SteamId64', (int) SteamId::parse($search)->steamId64());
                }
            });
        }

        // $active is accepted for interface parity but has no effect: this
        // schema has no expiry column, every row is unconditionally active.

        $paginator = $query->orderBy($column, $dir)->paginate($perPage);
        $paginator->getCollection()->transform(fn (object $row): array => $this->normalize($row));

        return $paginator;
    }

    /**
     * admin_playtime is a CS2_Admin-specific table with no equivalent here.
     */
    public function playtimeFor(iterable $admins): array
    {
        return [];
    }

    public function groups(): Collection
    {
        $groups = DB::connection('swiftly')->table(self::TABLE_GROUPS)->orderBy('Name')->get();

        return $groups->map(function (object $g): array {
            $memberCount = DB::connection('swiftly')->table(self::TABLE_ADMINS)
                ->whereRaw('JSON_CONTAINS(Groups, JSON_QUOTE(?))', [$g->Name])
                ->count();

            return [
                'name' => $g->Name,
                'flags' => $this->joinOrNull($this->decode($g->Permissions ?? null)),
                'immunity' => (int) $g->Immunity,
                'member_count' => $memberCount,
            ];
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

        if (DB::connection('swiftly')->table(self::TABLE_GROUPS)->where('Name', $name)->exists()) {
            throw new InvalidArgumentException('group_already_exists');
        }

        $flags = (array) ($data['flags'] ?? []);
        $immunity = (int) ($data['immunity'] ?? 0);

        DB::connection('swiftly')->table(self::TABLE_GROUPS)->insert([
            'Name' => $name,
            'Permissions' => $this->encode($flags),
            // Not nullable upstream - an empty allow-list, same convention
            // as an admin's own Servers column (see class docblock).
            'Servers' => $this->encode([]),
            'Immunity' => $immunity,
        ]);

        $this->audit->log('admin_group.created', 'admin_group', $name, ['flags' => $flags, 'immunity' => $immunity]);

        return $this->findGroup($name);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function updateGroup(string $name, array $data): array
    {
        $existing = DB::connection('swiftly')->table(self::TABLE_GROUPS)->where('Name', $name)->first();

        if ($existing === null) {
            throw new InvalidArgumentException('group_not_found');
        }

        $flags = array_key_exists('flags', $data) ? (array) $data['flags'] : $this->decode($existing->Permissions ?? null);
        $immunity = (int) ($data['immunity'] ?? $existing->Immunity);

        DB::connection('swiftly')->table(self::TABLE_GROUPS)->where('Name', $name)->update([
            'Permissions' => $this->encode($flags),
            'Immunity' => $immunity,
        ]);

        // Every admin carrying this group has its flags cached; see
        // AdminService::updateGroup() for why this must happen synchronously.
        DB::connection('swiftly')->table(self::TABLE_ADMINS)
            ->whereRaw('JSON_CONTAINS(Groups, JSON_QUOTE(?))', [$name])
            ->pluck('SteamId64')
            ->each(fn ($id) => Flags::forget((int) $id));

        $this->audit->log('admin_group.updated', 'admin_group', $name, ['flags' => $flags, 'immunity' => $immunity]);

        return $this->findGroup($name);
    }

    public function deleteGroup(string $name): void
    {
        $existing = DB::connection('swiftly')->table(self::TABLE_GROUPS)->where('Name', $name)->first();

        if ($existing === null) {
            throw new InvalidArgumentException('group_not_found');
        }

        $members = DB::connection('swiftly')->table(self::TABLE_ADMINS)
            ->whereRaw('JSON_CONTAINS(Groups, JSON_QUOTE(?))', [$name])
            ->pluck('SteamId64');

        DB::connection('swiftly')->table(self::TABLE_GROUPS)->where('Name', $name)->delete();

        $members->each(fn ($id) => Flags::forget((int) $id));

        $this->audit->log('admin_group.deleted', 'admin_group', $name);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): array
    {
        $steamId64 = (int) SteamId::parse((string) $data['steamid'])->steamId64();

        if (DB::connection('swiftly')->table(self::TABLE_ADMINS)->where('SteamId64', $steamId64)->exists()) {
            throw new InvalidArgumentException('admin_already_exists');
        }

        $flags = (array) ($data['flags'] ?? []);
        $groups = (array) ($data['groups'] ?? []);
        $immunity = (int) ($data['immunity'] ?? 0);
        $name = (string) $data['name'];

        DB::connection('swiftly')->table(self::TABLE_ADMINS)->insert([
            'SteamId64' => $steamId64,
            'Username' => $name,
            'Permissions' => $this->encode($flags),
            'Groups' => $this->encode($groups),
            'Immunity' => $immunity,
            'Servers' => $this->encode($this->allServerGuids()),
        ]);

        Flags::forget($steamId64);
        $this->audit->log('admin.created', 'admin', (string) $steamId64, [
            'steamid' => (string) $steamId64, 'name' => $name, 'flags' => $flags, 'groups' => $groups, 'immunity' => $immunity,
        ]);

        return $this->findBySteamId($steamId64);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(int $id, array $data): array
    {
        $existing = DB::connection('swiftly')->table(self::TABLE_ADMINS)->where('Id', $id)->first();

        if ($existing === null) {
            throw new InvalidArgumentException('admin_not_found');
        }

        $oldSteamId64 = (int) $existing->SteamId64;
        $steamId64 = isset($data['steamid'])
            ? (int) SteamId::parse((string) $data['steamid'])->steamId64()
            : $oldSteamId64;

        if ($steamId64 !== $oldSteamId64 && DB::connection('swiftly')->table(self::TABLE_ADMINS)->where('SteamId64', $steamId64)->exists()) {
            throw new InvalidArgumentException('admin_already_exists');
        }

        $flags = array_key_exists('flags', $data) ? (array) $data['flags'] : $this->decode($existing->Permissions ?? null);
        $groups = array_key_exists('groups', $data) ? (array) $data['groups'] : $this->decode($existing->Groups ?? null);
        $immunity = (int) ($data['immunity'] ?? $existing->Immunity);
        $name = (string) ($data['name'] ?? $existing->Username);

        DB::connection('swiftly')->table(self::TABLE_ADMINS)->where('Id', $id)->update([
            'SteamId64' => $steamId64,
            'Username' => $name,
            'Permissions' => $this->encode($flags),
            'Groups' => $this->encode($groups),
            'Immunity' => $immunity,
        ]);

        Flags::forget($oldSteamId64);
        Flags::forget($steamId64);
        $this->audit->log('admin.updated', 'admin', (string) $steamId64, [
            'steamid' => (string) $steamId64, 'name' => $name, 'flags' => $flags, 'groups' => $groups, 'immunity' => $immunity,
        ]);

        return $this->findBySteamId($steamId64);
    }

    public function disable(int $id): array
    {
        $existing = DB::connection('swiftly')->table(self::TABLE_ADMINS)->where('Id', $id)->first();

        if ($existing === null) {
            throw new InvalidArgumentException('admin_not_found');
        }

        $row = $this->normalize($existing);

        DB::connection('swiftly')->table(self::TABLE_ADMINS)->where('Id', $id)->delete();

        Flags::forget((int) $existing->SteamId64);
        $this->audit->log('admin.disabled', 'admin', (string) $existing->SteamId64, $row);

        return $row;
    }

    /**
     * @return array<string, mixed>
     */
    private function findGroup(string $name): array
    {
        $g = DB::connection('swiftly')->table(self::TABLE_GROUPS)->where('Name', $name)->first();
        $memberCount = DB::connection('swiftly')->table(self::TABLE_ADMINS)
            ->whereRaw('JSON_CONTAINS(Groups, JSON_QUOTE(?))', [$name])
            ->count();

        return [
            'name' => $g->Name,
            'flags' => $this->joinOrNull($this->decode($g->Permissions ?? null)),
            'immunity' => (int) $g->Immunity,
            'member_count' => $memberCount,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function findBySteamId(int $steamId64): array
    {
        $row = DB::connection('swiftly')->table(self::TABLE_ADMINS)->where('SteamId64', $steamId64)->first();

        return $this->normalize($row);
    }

    /**
     * Same shape AdminAdmin::toArray() produces, so neither AdminController
     * nor the admin/group Blade views need to know which plugin is active.
     *
     * @return array<string, mixed>
     */
    private function normalize(object $row): array
    {
        return [
            'id' => (int) $row->Id,
            'steamid' => (string) $row->SteamId64,
            'name' => (string) $row->Username,
            'flags' => $this->joinOrNull($this->decode($row->Permissions ?? null)),
            'groups' => $this->joinOrNull($this->decode($row->Groups ?? null)),
            'immunity' => (int) $row->Immunity,
            // No expiry concept on this schema - see class docblock.
            'expires_at' => null,
        ];
    }

    /**
     * Every GUID the plugin has registered for this install - see class
     * docblock for why every admin write applies all of them.
     *
     * @return array<int, string>
     */
    private function allServerGuids(): array
    {
        try {
            return DB::connection('swiftly')->table(self::TABLE_SERVERS)
                ->pluck('GUID')
                ->filter(fn ($guid) => is_string($guid) && $guid !== '')
                ->values()
                ->all();
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * @return array<int, string>
     */
    private function decode(?string $json): array
    {
        if ($json === null || trim($json) === '') {
            return [];
        }

        $decoded = json_decode($json, true);

        return is_array($decoded) ? array_values(array_map('strval', $decoded)) : [];
    }

    /**
     * @param  array<int, string>  $values
     */
    private function encode(array $values): string
    {
        $values = array_values(array_filter(array_map('trim', $values), fn (string $v): bool => $v !== ''));

        return json_encode($values, JSON_THROW_ON_ERROR);
    }

    /**
     * @param  array<int, string>  $values
     */
    private function joinOrNull(array $values): ?string
    {
        return $values === [] ? null : implode(',', $values);
    }
}
