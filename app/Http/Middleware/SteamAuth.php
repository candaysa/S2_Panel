<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Requires an authenticated panel user (Steam OpenID session).
 *
 * Failures render 401 JSON for API requests, otherwise redirect to login.
 * redirect()->guest() (rather than a plain redirect()->to()) stashes the
 * originally-requested URL in the session, so AuthController::callback()'s
 * redirect()->intended() sends the player back to the page they wanted
 * instead of always dropping them on the dashboard.
 */
class SteamAuth
{
    public function handle(Request $request, Closure $next): Response
    {
        if (Auth::check()) {
            return $next($request);
        }

        if ($request->is('api/*') || $request->expectsJson()) {
            return response()->json(['message' => 'unauthenticated'], 401);
        }

        return redirect()->guest(route('login'));
    }
}