<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Restricts a route to the panel owner (users.is_owner = true).
 */
class OwnerOnly
{
    public function handle(Request $request, Closure $next): Response
    {
        if (Auth::check() && Auth::user()->isOwner()) {
            return $next($request);
        }

        if ($request->is('api/*') || $request->expectsJson()) {
            return response()->json(['message' => 'forbidden'], 403);
        }

        abort(403);
    }
}