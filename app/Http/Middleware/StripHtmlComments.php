<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Strips HTML comments (<!-- ... -->) from text/html responses so no
 * developer notes, framework markers or template leftovers are ever visible
 * in the browser console / view source.
 *
 * Conditional comments (<!--[if IE]> ... <![endif]-->) are preserved because
 * old IE layouts depend on them.
 */
class StripHtmlComments
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if (! $response instanceof Response) {
            return $response;
        }

        $contentType = (string) $response->headers->get('Content-Type', '');

        if (! str_contains($contentType, 'text/html')) {
            return $response;
        }

        $content = $response->getContent();

        if ($content === false || $content === '') {
            return $response;
        }

        $response->setContent(preg_replace('/<!--(?!\[if).*?-->/s', '', $content));

        return $response;
    }
}