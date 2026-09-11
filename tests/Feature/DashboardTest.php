<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\Support\CreatesPluginTables;
use Tests\TestCase;

/**
 * GET /api/dashboard's "Top players" widget (C5). Public, and built from
 * its own query rather than reusing RankService - which is exactly how it
 * ended up without the steam64 field the profile link needs (see
 * RankService::steam64()'s docblock): a STEAM_0:x:y key is not safe in a
 * URL path segment as-is (its colons still need percent-encoding, and
 * anything that re-encodes or trims a link - a chat client, a redirect -
 * breaks it), so every player-profile link on the site was moved to it
 * except this one, which builds its own row shape from scratch.
 */
class DashboardTest extends TestCase
{
    use CreatesPluginTables;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createSwiftlyCoreTables();
        $this->createRankTables();
    }

    public function test_top_players_includes_a_steam64_for_the_profile_link(): void
    {
        DB::connection('ranks')->table('lvl_base')->insert([
            'steam' => 'STEAM_0:1:123456',
            'name' => 'Top Player',
            'value' => 30000,
            'rank' => 1,
        ]);

        $response = $this->getJson('/api/dashboard')->assertOk();

        $this->assertSame(
            '76561197960512641',
            $response->json('data.ranks.0.steam64'),
        );
    }

    /**
     * The summary must never wait on a game server: one that does not answer
     * holds an A2S query for its whole timeout, and this response carries
     * every other card on the page. Servers with nothing cached come back
     * pending, and nothing is probed (a probe caches its answer, so an empty
     * cache afterwards proves none ran).
     */
    public function test_summary_answers_from_cache_without_probing_servers(): void
    {
        $this->addServer('127.0.0.1', 1);

        $this->getJson('/api/dashboard')
            ->assertOk()
            ->assertJsonPath('data.servers.0.pending', true)
            ->assertJsonPath('data.servers.0.live', null)
            ->assertJsonPath('data.servers.0.online', false);

        $this->assertFalse(Cache::has('server.live.127.0.0.1:1'));
    }

    public function test_summary_uses_live_state_that_is_already_cached(): void
    {
        $this->addServer('127.0.0.1', 27015);
        Cache::put('server.live.127.0.0.1:27015', ['v' => [
            'name' => 'Example #1', 'map' => 'de_dust2', 'players' => 7,
            'max_players' => 20, 'bots' => 0, 'app_id' => 730,
        ]], 15);

        $this->getJson('/api/dashboard')
            ->assertOk()
            ->assertJsonPath('data.servers.0.pending', false)
            ->assertJsonPath('data.servers.0.online', true)
            ->assertJsonPath('data.servers.0.live.name', 'Example #1');
    }

    public function test_servers_endpoint_probes_and_resolves_every_pending_row(): void
    {
        config(['server.a2s_timeout' => 0.2]);
        $this->addServer('127.0.0.1', 1);

        $this->getJson('/api/dashboard/servers')
            ->assertOk()
            ->assertJsonPath('data.0.pending', false)
            ->assertJsonPath('data.0.online', false);

        // Cached as "offline", so the next summary shows it at once.
        $this->assertTrue(Cache::has('server.live.127.0.0.1:1'));
        $this->getJson('/api/dashboard')->assertJsonPath('data.servers.0.pending', false);
    }

    private function addServer(string $ip, int $port): void
    {
        DB::connection('swiftly')->table('admin_servers')->insert([
            'server_id' => "{$ip}:{$port}",
            'server_ip' => $ip,
            'server_port' => $port,
        ]);
    }
}
