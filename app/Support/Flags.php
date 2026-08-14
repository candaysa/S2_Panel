<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Reads admin flags from the Swiftly plugin's admin_admins table.
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
        });
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