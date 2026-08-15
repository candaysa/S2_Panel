<?php

namespace App\Support;

use App\Modules\Settings\App\Services\SettingService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Reads admin flags from whichever admin plugin the panel is configured
 * for - CS2_Admin's admin_admins table (default) or the official
 * swiftlys2-plugins/admins admins table, per the `admin_plugin` setting.
 * See App\Support\AdminPlugin\AdminManagerInterface for the equivalent
 * split on the CRUD side.
 *
 * The panel treats the plugin database as read-mostly: flags are cached for
 * 5 minutes and invalidated explicitly when the Admin module mutates an
 * admin (create/update/delete).
 */
final class Flags
{
    private const TTL_SECONDS = 300;

    /**
     * Resolve the flag profile for a SteamID64.
     *
     * @return array{flags: array<int, string>, groups: array<int, string>, immunity: int}|null
     */
    public static function for(int $steamId64): ?array
    {
        return Cache::remember("flags:{$steamId64}", self::TTL_SECONDS, function () use ($steamId64): ?array {
            return self::usesSwiftlyAdmins()
                ? self::forSwiftlyAdmins($steamId64)
                : self::forCs2Admin($steamId64);
        });
    }

    private static function usesSwiftlyAdmins(): bool
    {
        return app(SettingService::class)->get('admin_plugin', 'cs2_admin') === 'swiftly_admins';
    }

    /**
     * @return array{flags: array<int, string>, groups: array<int, string>, immunity: int}|null
     */
    private static function forCs2Admin(int $steamId64): ?array
    {
        $admin = DB::connection('swiftly')
            ->table('admin_admins')
            ->where('steamid', $steamId64)
            ->where(function ($query): void {
                $query->whereNull('expires_at')->orWhere('expires_at', '>', now());
            })
            ->first(['flags', 'groups', 'immunity']);

        if ($admin === null) {
            return null;
        }

        return [
            'flags' => self::explode($admin->flags),
            'groups' => self::explode($admin->groups),
            'immunity' => (int) $admin->immunity,
        ];
    }

    /**
     * UNVERIFIED against a live instance - see
     * App\Support\AdminPlugin\SwiftlyAdminsService's class docblock.
     * Permissions/Groups are JSON array text here, not CSV, and there is no
     * expires_at column to filter on - every row is unconditionally active.
     *
     * @return array{flags: array<int, string>, groups: array<int, string>, immunity: int}|null
     */
    private static function forSwiftlyAdmins(int $steamId64): ?array
    {
        $admin = DB::connection('swiftly')
            ->table('admins')
            ->where('SteamId64', $steamId64)
            ->first(['Permissions', 'Groups', 'Immunity']);

        if ($admin === null) {
            return null;
        }

        return [
            'flags' => self::decodeJson($admin->Permissions),
            'groups' => self::decodeJson($admin->Groups),
            'immunity' => (int) $admin->Immunity,
        ];
    }

    /**
     * @return array<int, string>
     */
    private static function decodeJson(?string $value): array
    {
        if ($value === null || trim($value) === '') {
            return [];
        }

        $decoded = json_decode($value, true);

        return is_array($decoded) ? array_values(array_map('strval', $decoded)) : [];
    }

    public static function hasFlag(int $steamId64, string $flag): bool
    {
        $profile = self::for($steamId64);

        return $profile !== null && in_array($flag, $profile['flags'], true);
    }

    /**
     * @param  array<int, string>  $flags
     */
    public static function hasAnyFlag(int $steamId64, array $flags): bool
    {
        $profile = self::for($steamId64);

        if ($profile === null) {
            return false;
        }

        return count(array_intersect($flags, $profile['flags'])) > 0;
    }

    /**
     * Drop the cached profile after Admin module mutations.
     */
    public static function forget(int $steamId64): void
    {
        Cache::forget("flags:{$steamId64}");
    }

    /**
     * @return array<int, string>
     */
    private static function explode(?string $value): array
    {
        if ($value === null || trim($value) === '') {
            return [];
        }

        return array_values(array_filter(array_map('trim', explode(',', $value)), fn (string $v): bool => $v !== ''));
    }
}