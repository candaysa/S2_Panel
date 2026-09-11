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
        '/settings/updates',
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

    /**
     * Alpine calls a component's init() by itself. An x-init="init()" next
     * to it runs the whole thing a second time - every page fetched its data
     * twice and the notification bell polled twice as often, which went
     * unnoticed until the web server's access log showed each API call in
     * pairs.
     */
    public function test_no_component_calls_its_own_init_a_second_time(): void
    {
        $offenders = [];

        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(resource_path('views'), \FilesystemIterator::SKIP_DOTS)) as $file) {
            if (str_ends_with($file->getFilename(), '.blade.php')
                && preg_match('/x-init="\s*init\(\)\s*"/', (string) file_get_contents($file->getPathname())) === 1) {
                $offenders[] = $file->getPathname();
            }
        }

        $this->assertSame([], $offenders);
    }

    public function test_the_update_notice_is_rendered_for_the_owner_only(): void
    {
        $this->actingAs(User::factory()->owner()->create())
            ->get('/dashboard')
            ->assertOk()
            ->assertSee('window.updatePrompt', false);

        $this->actingAs(User::factory()->create())
            ->get('/dashboard')
            ->assertOk()
            ->assertDontSee('updatePrompt', false);

        auth()->logout();

        $this->get('/dashboard')
            ->assertOk()
            ->assertDontSee('updatePrompt', false);
    }
}
