<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\CreatesPluginTables;
use Tests\TestCase;

/**
 * Every page's inline JS and x-data must be syntactically whole in the
 * rendered HTML.
 *
 * The bug this exists for: an x-data attribute is delimited by double
 * quotes, so a single stray " anywhere inside it - including inside a //
 * comment - ends the attribute early. The browser then parses the rest of
 * the Alpine object as HTML attributes, every binding on the page throws
 * "x is not defined", and the page silently does nothing. Nothing in PHP
 * or the test suite notices, because the Blade still renders and still
 * returns 200; only a browser console shows it.
 *
 * Two checks, from opposite ends of the same break: the attribute that was
 * cut short is left with unbalanced {}/[]/(), and the part that was cut off
 * shows up as junk inside the tag. Either one alone has blind spots - a cut
 * landing on already-balanced braces passes the first; a remainder that
 * happens to be a bare word ("x-data=... // "foo") carries no JavaScript
 * punctuation and passes the second - so a leak that is BOTH balanced and
 * punctuation-free would still slip through both. Everything realistic (a
 * multi-line Alpine object, which by construction contains braces, commas
 * and quotes) trips at least one, and this is cheap enough to run for every
 * page on every test run, which a headless-browser check that actually
 * boots Alpine would not be.
 */
class PageScriptsTest extends TestCase
{
    use CreatesPluginTables;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createSwiftlyCoreTables();
    }

    /** @var array<int, string> */
    private const PAGES = [
        '/dashboard',
        '/ranks',
        '/players/76561198000000001',
        '/bans',
        '/bans/mutes',
        '/bans/gags',
        '/bans/warns',
        '/vip',
        '/tickets',
        '/admins',
        '/groups',
        '/rcon',
        '/audit',
        '/cheat-check',
        '/webhooks',
        '/modules',
        '/settings',
        '/settings/design',
        '/settings/servers',
    ];

    public function test_page_alpine_attributes_are_not_cut_short(): void
    {
        $owner = User::factory()->owner()->create();

        foreach (self::PAGES as $uri) {
            $html = $this->actingAs($owner)
                ->get($uri)
                ->assertOk()
                ->getContent();

            $this->assertNoLeakedScriptInTags($uri, $html);

            foreach ($this->alpineAttributes($html) as [$name, $value]) {
                // Checking for a stray quote in the captured value cannot
                // work: once the attribute is cut short, the value the
                // parser hands back stops *before* that quote and looks
                // perfectly clean. What a truncated Alpine object does
                // leave behind is an unbalanced brace - the rest of the
                // object is out in the DOM, not in here.
                foreach ([['{', '}'], ['[', ']'], ['(', ')']] as [$open, $close]) {
                    $this->assertSame(
                        substr_count($value, $open),
                        substr_count($value, $close),
                        "{$uri}: {$name} is cut short - unbalanced {$open}{$close}. A raw double quote inside the attribute (even in a // comment) ends it early. Value starts: ".mb_substr($value, 0, 120)
                    );
                }
            }
        }
    }

    /**
     * The other half of the same bug, caught from the opposite side.
     *
     * The balance check below only fires when truncation happens to leave a
     * delimiter unpaired - cut an attribute at a point where the braces
     * already balance and it sails through. But the JavaScript that was cut
     * off does not vanish: the browser reads it as more attributes on the
     * same tag. So strip every properly quoted value out of each opening
     * tag and look at what is left: a healthy tag has only attribute names,
     * "=", "/" and whitespace, and characters like { } ( ) ' , cannot occur
     * there at all. Finding one means an attribute ended early and its
     * remainder is now sitting in the markup.
     */
    private function assertNoLeakedScriptInTags(string $uri, string $html): void
    {
        // Quoted spans are consumed whole rather than scanning to the first
        // ">": Alpine expressions legitimately contain one (window.innerWidth
        // > 1024), and stopping there would cut a healthy tag in half and
        // report the remains as leaked script.
        preg_match_all('/<[a-zA-Z][a-zA-Z0-9-]*(?:"[^"]*"|\'[^\']*\'|[^>"\'])*>/s', $html, $tags);

        foreach ($tags[0] as $tag) {
            // Drop quoted attribute values - their contents are allowed to
            // hold anything, and they are not what this is looking for.
            $skeleton = preg_replace('/=\s*"[^"]*"/s', '=""', $tag) ?? $tag;
            $skeleton = preg_replace("/=\s*'[^']*'/s", "=''", $skeleton) ?? $skeleton;

            if (preg_match('/[{}()\',]/', $skeleton, $m) === 1) {
                $this->fail(
                    "{$uri}: a tag carries leaked script - found '{$m[0]}' where only attribute names belong, "
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
