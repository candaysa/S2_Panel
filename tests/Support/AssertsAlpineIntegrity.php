<?php

namespace Tests\Support;

/**
 * Checks that every Alpine attribute in a rendered page reached the browser
 * whole - shared by PageScriptsTest (every page of an installed panel) and
 * InstallTest (the wizard, which is only reachable before install, so the
 * page sweep can never see it).
 *
 * The bug: an x-data attribute is delimited by double quotes, so a single
 * stray " anywhere inside it - including inside a // comment - ends the
 * attribute early. The browser parses the rest of the Alpine object as HTML
 * attributes, every binding on the page throws, and the page silently does
 * nothing while PHP still renders it with a 200.
 *
 * Two checks, from opposite ends of the same break: the attribute that was
 * cut short is left with unbalanced {}/[]/(), and the part that was cut off
 * shows up as junk inside the tag. Either one alone has blind spots - a cut
 * landing on already-balanced braces passes the first; a remainder that
 * happens to be a bare word carries no JavaScript punctuation and passes the
 * second - so a leak that is BOTH balanced and punctuation-free would still
 * slip through both. Everything realistic (a multi-line Alpine object, which
 * by construction contains braces, commas and quotes) trips at least one.
 */
trait AssertsAlpineIntegrity
{
    protected function assertAlpineIntact(string $label, string $html): void
    {
        $this->assertNoLeakedScriptInTags($label, $html);

        foreach ($this->alpineAttributes($html) as [$name, $value]) {
            // Checking for a stray quote in the captured value cannot work:
            // once the attribute is cut short, the value the parser hands
            // back stops *before* that quote and looks perfectly clean. What
            // a truncated Alpine object does leave behind is an unbalanced
            // brace - the rest of the object is out in the DOM, not in here.
            foreach ([['{', '}'], ['[', ']'], ['(', ')']] as [$open, $close]) {
                $this->assertSame(
                    substr_count($value, $open),
                    substr_count($value, $close),
                    "{$label}: {$name} is cut short - unbalanced {$open}{$close}. A raw double quote inside the attribute (even in a // comment) ends it early. Value starts: ".mb_substr($value, 0, 120)
                );
            }
        }
    }

    /**
     * Strip every properly quoted value out of each opening tag and look at
     * what is left: a healthy tag has only attribute names, "=", "/" and
     * whitespace, and { } ( ) ' , cannot occur there at all. Finding one
     * means an attribute ended early and its remainder is sitting in the
     * markup.
     */
    private function assertNoLeakedScriptInTags(string $label, string $html): void
    {
        // Quoted spans are consumed whole rather than scanning to the first
        // ">": Alpine expressions legitimately contain one (window.innerWidth
        // > 1024), and stopping there would cut a healthy tag in half and
        // report the remains as leaked script.
        preg_match_all('/<[a-zA-Z][a-zA-Z0-9-]*(?:"[^"]*"|\'[^\']*\'|[^>"\'])*>/s', $html, $tags);

        foreach ($tags[0] as $tag) {
            $skeleton = preg_replace('/=\s*"[^"]*"/s', '=""', $tag) ?? $tag;
            $skeleton = preg_replace("/=\s*'[^']*'/s", "=''", $skeleton) ?? $skeleton;

            if (preg_match('/[{}()\',]/', $skeleton, $m) === 1) {
                $this->fail(
                    "{$label}: a tag carries leaked script - found '{$m[0]}' where only attribute names belong, "
                    ."which is what a double quote inside an Alpine attribute does to the rest of it. Tag starts: "
                    .mb_substr($skeleton, 0, 160)
                );
            }
        }
    }

    /**
     * Alpine attribute values as they actually reached the browser.
     *
     * @return array<int, array{0: string, 1: string}>
     */
    private function alpineAttributes(string $html): array
    {
        preg_match_all('/\s(x-data|x-init|x-show|x-text|x-model|@[\w.:-]+|:[\w.-]+)="([^"]*)"/', $html, $matches, PREG_SET_ORDER);

        return array_map(
            fn (array $m): array => [$m[1], html_entity_decode($m[2], ENT_QUOTES)],
            $matches,
        );
    }
}
