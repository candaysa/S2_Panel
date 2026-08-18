<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\View;
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
        // Per-request nonce, generated before the view renders so Blade can
        // stamp it onto the one inline script the panel has (the pre-paint
        // theme restore in partials/head-theme.blade.php). Without it that
        // script was silently dropped by CSP: the toggle worked, because
        // Alpine runs from a same-origin bundle, but the saved preference was
        // never re-applied on load - which read as "light mode resets to dark
        // on refresh". The alternative, 'unsafe-inline', would re-open every
        // injected <script> in the app, so a nonce it is.
        $nonce = base64_encode(random_bytes(16));
        View::share('cspNonce', $nonce);

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
            "default-src 'self'; script-src 'self' 'unsafe-eval' 'nonce-{$nonce}'; ".
            "style-src 'self' 'unsafe-inline'; ".
            // Skin/sticker/knife/glove/agent art all loads as <img src>,
            // which img-src covers - nothing on this panel makes a fetch/XHR
            // to a third-party host, so connect-src stays 'self' alone.
            "img-src 'self' data: https:; font-src 'self' data:; connect-src 'self'; ".
            "frame-ancestors 'none'; base-uri 'self'; form-action 'self'; object-src 'none'; ".
            "manifest-src 'self'; worker-src 'self'"
        );

        if ($request->isSecure() || strtolower((string) $request->header('X-Forwarded-Proto')) === 'https') {
            $response->headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        }

        return $response;
    }
}