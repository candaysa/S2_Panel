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
 * A balanced-quote check on the raw attribute catches exactly that, and is
 * cheap enough to run for every page.
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
