<?php

namespace App\Http\Middleware;

use App\Support\SourceComments;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Strips every comment from text/html responses, so no developer notes are
 * visible in view-source or the browser's dev tools: HTML comments
 * (<!-- ... -->), and // and /* *\/ comments inside inline <script> and
 * <style> blocks and Alpine attributes (see App\Support\SourceComments for
 * how strings, template literals and regexes are kept intact).
 *
 * External scripts (<script src>) are compiled by Vite, which already drops
 * comments; data blocks such as application/ld+json have none to drop and
 * are left alone. Conditional comments (<!--[if IE]> ... <![endif]-->) are
 * preserved because old IE layouts depend on them.
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

        $response->setContent($this->strip($content));

        return $response;
    }

    public function strip(string $html): string
    {
        // Script and style bodies first, and set aside while the HTML
        // comments go: a "<!--" inside a script string is not a comment.
        $blocks = [];

        $html = (string) preg_replace_callback(
            '#(<(script|style)\b([^>]*)>)(.*?)(</\2\s*>)#is',
            function (array $m) use (&$blocks): string {
                $body = $m[4];

                if (strtolower($m[2]) === 'style') {
                    $body = SourceComments::stripCss($body);
                } elseif ($this->isInlineJs($m[3])) {
                    $body = SourceComments::stripJs($body);
                }

                $blocks[] = $m[1].$body.$m[5];

                return "\0S2BLOCK".(count($blocks) - 1)."\0";
            },
            $html,
        );

        $html = (string) preg_replace('/<!--(?!\[if).*?-->/s', '', $html);

        // Alpine attributes are JavaScript too, and a large x-data="{ … }"
        // carries comments like any script would. Their values never hold a
        // double quote (it would end the attribute), so [^"]* is the value.
        $html = (string) preg_replace_callback(
            '/(\s(?:x-[\w:.-]+|@[\w:.-]+|:[\w:.-]+)=")([^"]*)(")/',
            fn (array $m): string => str_contains($m[2], '//') || str_contains($m[2], '/*')
                ? $m[1].SourceComments::stripJs($m[2]).$m[3]
                : $m[0],
            $html,
        );

        return (string) preg_replace_callback('/\0S2BLOCK(\d+)\0/', fn (array $m): string => $blocks[(int) $m[1]], $html);
    }

    private function isInlineJs(string $attributes): bool
    {
        if (preg_match('/\bsrc\s*=/i', $attributes) === 1) {
            return false;
        }

        if (preg_match('/\btype\s*=\s*["\']?([^"\'\s>]+)/i', $attributes, $type) !== 1) {
            return true;
        }

        return in_array(strtolower($type[1]), ['text/javascript', 'application/javascript', 'module'], true);
    }
}
