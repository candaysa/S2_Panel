<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\AssertsAlpineIntegrity;
use Tests\Support\CreatesPluginTables;
use Tests\TestCase;

/**
 * Every page's inline JS and x-data must be syntactically whole in the
 * rendered HTML - see Tests\Support\AssertsAlpineIntegrity for the bug this
 * guards against and how the two checks cover it. Cheap enough to run for
 * every page on every test run, which a headless-browser check that
 * actually boots Alpine would not be.
 */
class PageScriptsTest extends TestCase
{
    use AssertsAlpineIntegrity;
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

            $this->assertAlpineIntact($uri, $html);
        }
    }
}
