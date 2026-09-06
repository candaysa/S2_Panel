<?php

namespace App\Support\AdminPlugin;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

/**
 * Admin/group management, independent of which plugin owns the underlying
 * table (CS2_Admin's admin_admins/admin_groups vs the official
 * swiftlys2-plugins/admins admins/groups). AdminController resolves the
 * right implementation once, from the `admin_plugin` setting - see
 * AdminServiceProvider - and never queries a table name itself.
 *
 * Every method returns/accepts the same shape AdminController and the
 * existing admin/group Blade views already expect (steamid, name, flags as
 * a CSV string, groups as a CSV string, immunity, expires_at) regardless of
 * how the backing plugin actually stores that data, so neither the frontend
 * nor the controller needs to know which plugin is active.
 */
interface AdminManagerInterface
{
    /**
     * @return LengthAwarePaginator<int, array<string, mixed>>
     */
    public function list(
        ?string $search,
        ?bool $active,
        int $perPage,
        string $sort,
        string $dir,
    ): LengthAwarePaginator;

    /**
     * @param  iterable<array<string, mixed>>  $admins
     * @return array<string, int>
     */
    public function playtimeFor(iterable $admins): array;

    /**
     * @return Collection<int, array<string, mixed>>
     */
    public function groups(): Collection;

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function createGroup(array $data): array;

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function updateGroup(string $name, array $data): array;

    public function deleteGroup(string $name): void;

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function create(array $data): array;

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function update(int $id, array $data): array;

    /**
     * @return array<string, mixed>
     */
    public function disable(int $id): array;
}
