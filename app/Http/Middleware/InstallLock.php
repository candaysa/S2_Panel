<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Blocks every request until the installer has completed, AND blocks the
 * installer itself once it has.
 *
 * The installation wizard (Install module) writes INSTALLED=true to .env.
 * Requests to the installer ("install" / "api/install") are only allowed
 * while installation is NOT yet complete.
 *
 * SECURITY: the two conditions below must be checked in this order. An
 * earlier version returned $next($request) for every request as soon as
 * "installed" was true, BEFORE checking whether the request targeted the
 * installer routes - which left /api/install/* (database credentials,
 * OWNER_STEAM_ID, module toggles) reachable with no authentication for
 * the lifetime of an already-installed panel. Verified live: an
 * unauthenticated POST to /api/install/steam successfully overwrote
 * OWNER_STEAM_ID on an installed panel.
 */
class InstallLock
{
    public function handle(Request $request, Closure $next): Response
    {
        $installed = config('app.installed') === true;
        $isInstallerRoute = $request->is('install*') || $request->is('api/install*');

        if ($isInstallerRoute) {
            if ($installed) {
                return $this->deny($request, 404);
            }

            return $next($request);
        }

        if ($installed) {
            return $next($request);
        }

        if ($request->is('api/*') || $request->expectsJson()) {
            return response()->json(['message' => 'not_installed'], 403);
        }

        return redirect()->to('/install');
    }

    private function deny(Request $request, int $status): Response
    {
        if ($request->is('api/*') || $request->expectsJson()) {
            return response()->json(['message' => 'not_found'], $status);
        }

        abort($status);
    }
}