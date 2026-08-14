<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\CreatesPluginTables;
use Tests\TestCase;

class RankTest extends TestCase
{
    use CreatesPluginTables;
    use RefreshDatabase;

    /** STEAM_0:0:123456 */
    private const STEAM_ID2 = 'STEAM_0:0:123456';

    private const STEAM_ID64 = 76561197960512640;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createSwiftlyCoreTables();
        $this->createRankTables();
    }

    public function test_page_renders_for_an_authenticated_user(): void
    {
        $this->actingAs(User::factory()->create())
            ->get('/ranks')
            ->assertOk();
    }

    private function insertPlayer(array $overrides = []): void
    {
        DB::connection('ranks')->table('rank_base')->insert(array_merge([
            'steam' => self::STEAM_ID2,
            'name' => 'RankedPlayer',
            'value' => 1000,
            'rank' => 1,
            'kills' => 150,
            'deaths' => 80,
            'shoots' => 5000,
            'hits' => 2500,
            'headshots' => 60,
            'assists' => 30,
            'round_win' => 20,
            'round_lose' => 10,
            'playtime' => 3600,
            'lastconnect' => 1700000000,
            'game_wins' => 5,
            'game_losses' => 2,
            'games_played' => 7,
            'rounds_played' => 40,
            'damage' => 12000,
        ], $overrides));
    }

    private function insertHits(array $overrides = []): void
    {
        DB::connection('ranks')->table('rank_hits')->insert(array_merge([
            'SteamID' => self::STEAM_ID2,
            'DmgHealth' => 9000,
            'DmgArmor' => 3000,
            'Head' => 60,
            'Chest' => 100,
            'Belly' => 40,
            'LeftArm' => 20,
            'RightArm' => 25,
            'LeftLeg' => 15,
            'RightLeg' => 15,
            'Neak' => 5,
        ], $overrides));
    }

    public function test_index_requires_authentication(): void
    {
        $this->getJson('/api/ranks')->assertStatus(401);
    }

    public function test_index_visible_to_any_authenticated_user(): void
    {
        $this->insertPlayer();

        $this->actingAs(User::factory()->create())
            ->getJson('/api/ranks')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.steam', self::STEAM_ID2);
    }

    public function test_index_orders_by_value_descending(): void
    {
        $this->insertPlayer(['steam' => 'STEAM_0:0:1', 'name' => 'Low', 'value' => 100]);
        $this->insertPlayer(['steam' => 'STEAM_0:0:2', 'name' => 'High', 'value' => 900]);

        $this->actingAs(User::factory()->create())
            ->getJson('/api/ranks')
            ->assertOk()
            ->assertJsonPath('data.0.name', 'High')
            ->assertJsonPath('data.1.name', 'Low');
    }

    public function test_index_searches_by_name(): void
    {
        $this->insertPlayer(['steam' => 'STEAM_0:0:1', 'name' => 'Johnny Bravo']);
        $this->insertPlayer(['steam' => 'STEAM_0:0:2', 'name' => 'Jane Doe']);

        $this->actingAs(User::factory()->create())
            ->getJson('/api/ranks?search=Johnny')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Johnny Bravo');
    }

    public function test_index_searches_by_steamid64_and_steamid3(): void
    {
        $this->insertPlayer();

        // SteamID64 search.
        $this->actingAs(User::factory()->create())
            ->getJson('/api/ranks?search=76561197960512640')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.steam', self::STEAM_ID2);

        // SteamID3 search.
        $this->actingAs(User::factory()->create())
            ->getJson('/api/ranks?search='.urlencode('[U:1:246912]'))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.steam', self::STEAM_ID2);
    }

    public function test_index_paginates(): void
    {
        for ($i = 1; $i <= 12; $i++) {
            $this->insertPlayer(['steam' => "STEAM_0:0:{$i}", 'name' => "Player {$i}"]);
        }

        $this->actingAs(User::factory()->create())
            ->getJson('/api/ranks?per_page=5')
            ->assertOk()
            ->assertJsonCount(5, 'data')
            ->assertJsonPath('meta.pagination.total', 12)
            ->assertJsonPath('meta.pagination.last_page', 3);
    }

    public function test_show_returns_profile_with_hits(): void
    {
        $this->insertPlayer();
        $this->insertHits();

        $this->actingAs(User::factory()->create())
            ->getJson('/api/ranks/76561197960512640')
            ->assertOk()
            ->assertJsonPath('data.player.steam', self::STEAM_ID2)
            ->assertJsonPath('data.player.value', 1000)
            ->assertJsonPath('data.hits.SteamID', self::STEAM_ID2)
            ->assertJsonPath('data.hits.Head', 60);
    }

    public function test_show_returns_null_hits_when_missing(): void
    {
        $this->insertPlayer();

        $this->actingAs(User::factory()->create())
            ->getJson('/api/ranks/'.self::STEAM_ID2)
            ->assertOk()
            ->assertJsonPath('data.hits', null);
    }

    public function test_show_accepts_steamid2_path_format(): void
    {
        $this->insertPlayer();

        $this->actingAs(User::factory()->create())
            ->getJson('/api/ranks/STEAM_0:0:123456')
            ->assertOk()
            ->assertJsonPath('data.player.steam', self::STEAM_ID2);
    }

    public function test_show_returns_404_for_unknown_player(): void
    {
        $this->actingAs(User::factory()->create())
            ->getJson('/api/ranks/76561197960512641')
            ->assertStatus(404)
            ->assertJsonPath('message', 'not_found');
    }

    public function test_show_rejects_invalid_steamid(): void
    {
        $this->actingAs(User::factory()->create())
            ->getJson('/api/ranks/not-a-steamid')
            ->assertStatus(422)
            ->assertJsonPath('message', 'invalid_input');
    }

    public function test_update_points_requires_admin_root_flag(): void
    {
        $this->insertPlayer();

        $this->actingAs(User::factory()->create())
            ->patchJson('/api/ranks/76561197960512640/points', ['value' => 500])
            ->assertStatus(403);
    }

    public function test_update_points_owner_can_edit(): void
    {
        $this->insertPlayer();

        $this->actingAs(User::factory()->owner()->create())
            ->patchJson('/api/ranks/76561197960512640/points', ['value' => 2500])
            ->assertOk()
            ->assertJsonPath('data.value', 2500);

        $this->assertDatabaseHas('rank_base', ['steam' => self::STEAM_ID2, 'value' => 2500], 'ranks');
    }

    public function test_update_points_admin_with_root_flag_can_edit(): void
    {
        $this->insertPlayer();

        $admin = User::factory()->create(['steam_id' => (string) 76561197962734863]);
        DB::connection('swiftly')->table('admin_admins')->insert([
            'steamid' => 76561197962734863,
            'name' => 'Root',
            'flags' => 'admin.root',
            'groups' => null,
            'immunity' => 1,
            'created_at' => now(),
            'expires_at' => null,
        ]);

        $this->actingAs($admin)
            ->patchJson('/api/ranks/76561197960512640/points', ['value' => 777])
            ->assertOk()
            ->assertJsonPath('data.value', 777);
    }

    public function test_update_points_writes_audit_log(): void
    {
        $this->insertPlayer();

        $this->actingAs(User::factory()->owner()->create())
            ->patchJson('/api/ranks/76561197960512640/points', ['value' => 2500])
            ->assertOk();

        $this->assertDatabaseHas('panel_logs', [
            'action' => 'rank.points_updated',
            'target_type' => 'rank_player',
            'target_id' => self::STEAM_ID2,
        ]);
    }

    public function test_update_points_returns_404_for_unknown_player(): void
    {
        $this->actingAs(User::factory()->owner()->create())
            ->patchJson('/api/ranks/76561197960512641/points', ['value' => 500])
            ->assertStatus(404);
    }

    public function test_update_points_validates_value(): void
    {
        $this->insertPlayer();

        $this->actingAs(User::factory()->owner()->create())
            ->patchJson('/api/ranks/76561197960512640/points', ['value' => -5])
            ->assertStatus(422)
            ->assertJsonPath('message', 'validation_failed');
    }
}