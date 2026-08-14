<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Adds a hardened set of security headers to every response.
 *
 * - X-Content-Type-Options: nosniff
 * - X-Frame-Options: DENY (no embedding anywhere)
 * - Referrer-Policy: no-referrer
 * - Permissions-Policy: deny sensors/cameras/mic/geolocation
 * - Content-Security-Policy: locked-down defaults (self + data: images)
 * - Strict-Transport-Security: only when the request is already HTTPS
 */
class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('X-Frame-Options', 'DENY');
        $response->headers->set('Referrer-Policy', 'no-referrer');
        $response->headers->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=(), interest-cohort=()');
        // 'unsafe-eval' is required by Alpine: it compiles every directive
        // expression (x-show, x-text, @click, ...) with new Function(), which
        // CSP blocks outright without it. Leaving it out does not degrade
        // gracefully - Alpine still boots and still strips x-cloak, then throws
        // on the first expression it evaluates, so every element stays visible
        // and nothing reacts. That is exactly how the install wizard ended up
        // rendering all five steps stacked with "ContinueLoading..." buttons.
        //
        // The alternative is Alpine's CSP build, which bans inline expressions
        // and would mean rewriting every page as registered Alpine.data()
        // components. Worth doing eventually; until then script-src stays
        // 'self' so only same-origin files execute, and Blade's escaping is
        // what keeps user input from ever reaching the evaluator.
        $response->headers->set('Content-Security-Policy',
            "default-src 'self'; script-src 'self' 'unsafe-eval'; style-src 'self' 'unsafe-inline'; ".
            "img-src 'self' data: https:; font-src 'self' data:; connect-src 'self'; ".
            "frame-ancestors 'none'; base-uri 'self'; form-action 'self'; object-src 'none'"
        );

        if ($request->isSecure() || strtolower((string) $request->header('X-Forwarded-Proto')) === 'https') {
            $response->headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        }

        return $response;
    }
}