<?php

namespace App\Support;

/**
 * Removes comments from inline JavaScript and CSS before a page is sent.
 *
 * Only comments go: strings, template literals (including `${}` nested to
 * any depth) and regular expression literals are copied through untouched,
 * so a URL like 'https://…' or a regex like /\/\// is never mistaken for
 * one. A line that held nothing but a comment disappears with it; a block
 * comment spanning lines leaves a line break behind so automatic semicolon
 * insertion sees the same code it did before.
 */
final class SourceComments
{
    /** A `/` after one of these starts a regex literal, not a division. */
    private const REGEX_AFTER_CHAR = '(,=:[!&|?{};+-*%<>~^';

    private const REGEX_AFTER_WORD = [
        'return', 'typeof', 'instanceof', 'in', 'of', 'new', 'delete', 'void',
        'throw', 'case', 'do', 'else', 'yield', 'await',
    ];

    public static function stripJs(string $js): string
    {
        $out = '';
        $len = strlen($js);
        $i = 0;
        $depth = 0;
        $templates = [];
        $inTemplate = false;
        $last = '';
        $word = '';

        while ($i < $len) {
            $c = $js[$i];
            $next = $js[$i + 1] ?? '';

            if ($inTemplate) {
                if ($c === '\\') {
                    $out .= substr($js, $i, 2);
                    $i += 2;
                } elseif ($c === '`') {
                    $out .= $c;
                    $i++;
                    $inTemplate = false;
                    $last = '`';
                    $word = '';
                } elseif ($c === '$' && $next === '{') {
                    $out .= '${';
                    $i += 2;
                    $templates[] = $depth;
                    $depth++;
                    $inTemplate = false;
                    $last = '{';
                    $word = '';
                } else {
                    $out .= $c;
                    $i++;
                }

                continue;
            }

            if ($c === '/' && $next === '/') {
                $end = strpos($js, "\n", $i);
                $end = $end === false ? $len : $end;
                $i = self::dropComment($out, $js, $end, $len);

                continue;
            }

            if ($c === '/' && $next === '*') {
                $end = strpos($js, '*/', $i + 2);
                $end = $end === false ? $len : $end + 2;
                $multiline = str_contains(substr($js, $i, $end - $i), "\n");
                $i = self::dropComment($out, $js, $end, $len, $multiline);

                continue;
            }

            if ($c === '"' || $c === "'") {
                $j = $i + 1;

                while ($j < $len && $js[$j] !== $c && $js[$j] !== "\n") {
                    $j += $js[$j] === '\\' ? 2 : 1;
                }

                $out .= substr($js, $i, $j - $i + 1);
                $i = $j + 1;
                $last = $c;
                $word = '';

                continue;
            }

            if ($c === '`') {
                $out .= $c;
                $i++;
                $inTemplate = true;

                continue;
            }

            if ($c === '/' && self::regexAllowed($last, $word)) {
                $j = $i + 1;
                $inClass = false;

                while ($j < $len && $js[$j] !== "\n") {
                    if ($js[$j] === '\\') {
                        $j += 2;

                        continue;
                    }

                    if ($js[$j] === '[') {
                        $inClass = true;
                    } elseif ($js[$j] === ']') {
                        $inClass = false;
                    } elseif ($js[$j] === '/' && ! $inClass) {
                        break;
                    }

                    $j++;
                }

                $out .= substr($js, $i, $j - $i + 1);
                $i = $j + 1;
                $last = '/';
                $word = '';

                continue;
            }

            if ($c === '{') {
                $depth++;
            } elseif ($c === '}') {
                $depth--;

                if ($templates !== [] && end($templates) === $depth) {
                    array_pop($templates);
                    $out .= '}';
                    $i++;
                    $inTemplate = true;

                    continue;
                }
            }

            $prev = $i > 0 ? $js[$i - 1] : '';
            $out .= $c;
            $i++;

            if (self::isIdent($c)) {
                // Whitespace keeps the last word (`return /x/`), but a new
                // identifier starts fresh after it.
                $word = self::isIdent($prev) ? $word.$c : $c;
                $last = $c;
            } elseif (! ctype_space($c)) {
                $last = $c;
                $word = '';
            }
        }

        return $out;
    }

    public static function stripCss(string $css): string
    {
        $out = '';
        $len = strlen($css);
        $i = 0;

        while ($i < $len) {
            $c = $css[$i];

            if ($c === '"' || $c === "'") {
                $j = $i + 1;

                while ($j < $len && $css[$j] !== $c && $css[$j] !== "\n") {
                    $j += $css[$j] === '\\' ? 2 : 1;
                }

                $out .= substr($css, $i, $j - $i + 1);
                $i = $j + 1;

                continue;
            }

            if ($c === '/' && ($css[$i + 1] ?? '') === '*') {
                $end = strpos($css, '*/', $i + 2);
                $i = self::dropComment($out, $css, $end === false ? $len : $end + 2, $len, false);

                continue;
            }

            $out .= $c;
            $i++;
        }

        return $out;
    }

    /**
     * Skip a comment ending at $end, taking with it the whitespace before it
     * on its line - and the line itself, when the comment was all it held.
     *
     * @return int where scanning resumes
     */
    private static function dropComment(string &$out, string $src, int $end, int $len, bool $keepBreak = false): int
    {
        $lineStart = strrpos($out, "\n");
        $lineSoFar = $lineStart === false ? $out : substr($out, $lineStart + 1);
        $out = rtrim($out, " \t");

        if (trim($lineSoFar) === '' && ! $keepBreak && $end < $len && $src[$end] === "\n") {
            // Nothing but this comment on the line: the line goes too.
            if ($lineStart !== false) {
                $out = substr($out, 0, $lineStart + 1);
            }

            return $end + 1;
        }

        if ($keepBreak) {
            $out .= "\n";
        } elseif ($out !== '' && ! ctype_space(substr($out, -1)) && $end < $len && ! ctype_space($src[$end])) {
            // `a/* x */b` must not become `ab`.
            $out .= ' ';
        }

        return $end;
    }

    private static function isIdent(string $c): bool
    {
        return $c !== '' && (ctype_alnum($c) || $c === '_' || $c === '$');
    }

    private static function regexAllowed(string $last, string $word): bool
    {
        if ($last === '') {
            return true;
        }

        if (str_contains(self::REGEX_AFTER_CHAR, $last)) {
            return true;
        }

        return in_array($word, self::REGEX_AFTER_WORD, true) && $word !== '' && ctype_alpha($last);
    }
}
