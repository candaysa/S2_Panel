<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
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
            'steam' => 'STEAM_0:1:780281982',
            'name' => 'Top Player',
            'value' => 30000,
            'rank' => 1,
        ]);

        $response = $this->getJson('/api/dashboard')->assertOk();

        $this->assertSame(
            '76561199520829693',
            $response->json('data.ranks.0.steam64'),
        );
    }
}
