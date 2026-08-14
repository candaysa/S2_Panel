<?php

namespace App\Modules\Admin\App\Services;

use App\Modules\Admin\App\Models\AdminAdmin;
use App\Modules\Admin\App\Models\AdminGroup;
use App\Modules\Admin\Events\AdminCreated;
use App\Modules\Admin\Events\AdminDisabled;
use App\Modules\Admin\Events\AdminUpdated;
use App\Modules\Audit\App\Services\AuditService;
use App\Support\Flags;
use App\Support\SteamId;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use InvalidArgumentException;

/**
 * Admin management (C3). All mutations run through here so business rules,
 * the flag cache invalidation, audit rows and module events stay in one
 * place.
 *
 * Project rule: NOTHING is ever deleted from the plugin database. "Removing"
 * an admin sets expires_at into the past – Swiftly and the panel ignore
 * expired rows, the row itself stays intact.
 */
class AdminService
{
    public function __construct(private readonly AuditService $audit)
    {
    }

    /**
     * List admins with optional name/steamid search and status filter.
     *
     * @return LengthAwarePaginator<int, AdminAdmin>
     */
    public function list(?string $search = null, ?bool $active = null, int $perPage = 25): LengthAwarePaginator
    {
        $query = AdminAdmin::query();

        if ($search !== null && $search !== '') {
            $query->where(function ($q) use ($search): void {
                $q->where('name', 'like', "%{$search}%");

                if (SteamId::isValid($search)) {
                    $q->orWhere('steamid', (int) SteamId::parse($search)->steamId64());
                }
            });
        }

        if ($active === true) {
            $query->where(function ($q): void {
                $q->whereNull('expires_at')->orWhere('expires_at', '>', now());
            });
        } elseif ($active === false) {
            $query->where(function ($q): void {
                $q->whereNotNull('expires_at')->where('expires_at', '<=', now());
            });
        }

        return $query->orderByDesc('id')->paginate($perPage);
    }

    /**
     * @return \Illuminate\Support\Collection<int, AdminGroup>
     */
    public function groups()
    {
        return AdminGroup::query()->orderBy('name')->get();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): AdminAdmin
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

        return $admin;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(int $id, array $data): AdminAdmin
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

        return $admin->refresh();
    }

    /**
     * Disable an admin without deleting the row (project rule).
     */
    public function disable(int $id): AdminAdmin
    {
        $admin = AdminAdmin::query()->find($id);

        if ($admin === null) {
            throw new InvalidArgumentException('admin_not_found');
        }

        $admin->update(['expires_at' => now()->subSecond()]);

        Flags::forget((int) $admin->steamid);
        $this->audit->log('admin.disabled', 'admin', (string) $admin->steamid, $this->auditDetails($admin));
        event(new AdminDisabled($admin));

        return $admin->refresh();
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