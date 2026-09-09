<?php

namespace App\Http\Middleware;

use App\Modules\Health\App\Services\RconVerificationService;
use App\Support\Access;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Blocks Bans/RCON/Admins/Groups entirely while any currently online
 * server's RCON credentials are unverified - see
 * RconVerificationService's own docblock for why this is fail-closed and
 * why it is not a live probe on every request.
 *
 * Deliberately NOT applied to the RCON settings endpoints themselves
 * (api/rcon/settings*) or to Settings > Servers, which is where an owner
 * actually fixes a bad password - gating the fix path would be a deadlock.
 *
 * Which server, and why, is owner-only detail: Bans in particular is open
 * to any signed-in player (see its Routes/api.php), and a plain player has
 * no way to act on "server 95.13.23.102:27021 has no rcon password" - it
 * would just be internal infrastructure handed to whoever happens to open
 * the page while it is broken. Everyone else gets told the section is
 * unavailable, nothing more.
 */
class RequireRconVerified
{
    public function __construct(private readonly RconVerificationService $verification)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $status = $this->verification->status();

        if ($status['ok']) {
            return $next($request);
        }

        $problems = Access::isOwner() ? $status['problems'] : [];

        if ($request->is('api/*') || $request->expectsJson()) {
            return response()->json([
                'message' => 'rcon_not_verified',
                'errors' => ['servers' => $problems],
            ], 503);
        }

        return response()->view('errors.rcon-not-verified', ['problems' => $problems], 503);
    }
}
