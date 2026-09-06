<?php

namespace App\Support;

use Illuminate\Support\Facades\Auth;
use Throwable;

/**
 * View-layer permission checks.
 *
 * The API is always the real gate - every route carries its own steam.auth
 * and flag middleware. This exists so a page can avoid *offering* an action
 * the API would then refuse: an edit button that always 403s is worse than
 * no button. Never use it as the only check on a mutation.
 *
 * Fails closed on any error, matching RequireFlag: a broken flag source must
 * narrow what is shown, never widen it.
 */
final class Access
{
    public static function user(): ?object
    {
        return Auth::user();
    }

    public static function isOwner(): bool
    {
        return Auth::user()?->isOwner() ?? false;
    }

    /**
     * True when the current user holds the flag, or is the owner.
     */
    public static function hasFlag(string $flag): bool
    {
        return self::hasAnyFlag([$flag]);
    }

    /**
     * @param  array<int, string>  $flags
     */
    public static function hasAnyFlag(array $flags): bool
    {
        $user = Auth::user();

        if ($user === null) {
            return false;
        }

        if ($user->isOwner()) {
            return true;
        }

        try {
            return Flags::hasAnyFlag((int) $user->steam_id, $flags);
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * The current user's flag list, for callers that need to test several
     * gates without re-querying (the sidebar checks one per nav item).
     *
     * @return array<int, string>
     */
    public static function flags(): array
    {
        $user = Auth::user();

        if ($user === null) {
            return [];
        }

        try {
            return Flags::for((int) $user->steam_id)['flags'] ?? [];
        } catch (Throwable) {
            return [];
        }
    }
}
