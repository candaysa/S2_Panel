<?php

namespace App\Http\Middleware;

use App\Support\Flags;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Requires the authenticated user to hold a Swiftly admin flag.
 *
 * The panel owner (users.is_owner) always passes – the owner is installed
 * by the setup wizard and must never be locked out of an admin capability.
 * Everyone else is checked against the live admin_admins flag profile
 * (5-minute cache, invalidated by the Admin module on mutations).
 *
 * The check FAILS CLOSED: if the plugin database is unreachable the user
 * is denied rather than granted – a broken flag source must never widen
 * access.
 *
 * Usage: Route::middleware('flag:admin.root')
 *        Route::middleware('flag:admin.ban,admin.mute')  (any-of)
 */
class RequireFlag
{
    public function handle(Request $request, Closure $next, string ...$flags): Response
    {
        $user = Auth::user();

        if ($user === null) {
            return $this->deny($request);
        }

        if ($user->isOwner()) {
            return $next($request);
        }

        $required = array_values(array_filter(array_map('trim', $flags), fn (string $f): bool => $f !== ''));

        try {
            $authorized = count($required) > 1
                ? Flags::hasAnyFlag((int) $user->steam_id, $required)
                : Flags::hasFlag((int) $user->steam_id, $required[0] ?? '');
        } catch (Throwable) {
            $authorized = false;
        }

        return $authorized ? $next($request) : $this->deny($request);
    }

    private function deny(Request $request): Response
    {
        if ($request->is('api/*') || $request->expectsJson()) {
            return response()->json(['message' => 'forbidden'], 403);
        }

        abort(403);
    }
}