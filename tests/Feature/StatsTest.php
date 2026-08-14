<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\Support\CreatesPluginTables;
use Tests\TestCase;

class StatsTest extends TestCase
{
    use CreatesPluginTables;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createSwiftlyCoreTables();
        $this->createSwiftlyPunishmentTables();
        $this->createRankTables();
        config(['server.a2s_timeout' => 0.05]);
        Cache::flush();
    }

    public function test_page_renders_for_an_authenticated_user(): void
    {
        $this->actingAs(User::factory()->create())
            ->get('/stats')
            ->assertOk();
    }

    private function seedRankPlayer(string $steam, array $overrides = []): void
    {
        DB::connection('ranks')->table('rank_base')->insert(array_merge([
            'steam' => $steam,
            'name' => 'Player',
            'value' => 1000,
            'rank' => 1,
            'kills' => 100,
            'deaths' => 40,
            'headshots' => 30,
            'assists' => 10,
            'playtime' => 3600,
            'damage' => 9999,
            'game_wins' => 10,
            'game_losses' => 5,
            'games_played' => 15,
            'lastconnect' => 1700000000,
        ], $overrides));
    }

    public function test_dashboard_requires_authentication(): void
    {
        $this->getJson('/api/stats/dashboard')->assertStatus(401);
    }

    public function test_dashboard_returns_totals(): void
    {
        $user = User::factory()->create(['steam_id' => '76561197960512640']);
        User::factory()->create(['steam_id' => '76561197960512641']);

        $this->seedRankPlayer('STEAM_0:0:123456');
        $this->seedRankPlayer('STEAM_0:1:234567');

        DB::connection('swiftly')->table('admin_servers')->insert([
            'server_id' => '127.0.0.1:27015',
            'server_ip' => '127.0.0.1',
            'server_port' => 27015,
            'last_seen_at' => now(),
        ]);

        DB::connection('swiftly')->table('admin_bans')->insert([
            'steamid' => 76561197960512640,
            'status' => 'active',
            'created_at' => now(),
        ]);
        DB::connection('swiftly')->table('admin_bans')->insert([
            'steamid' => 76561197960512641,
            'status' => 'unbanned',
            'created_at' => now(),
        ]);

        DB::table('reports')->insert([
            'ticket_type' => 'report',
            'status' => 'open',
            'reporter_steamid' => 76561197960512640,
            'report_reason' => 'x',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($user)
            ->getJson('/api/stats/dashboard')
            ->assertOk()
            ->assertJsonPath('data.total_players', 2)
            ->assertJsonPath('data.active_bans', 1)
            ->assertJsonPath('data.total_servers', 1)
            ->assertJsonPath('data.open_tickets', 1)
            ->assertJsonPath('data.total_users', 2);
    }

    public function test_player_requires_authentication(): void
    {
        $this->getJson('/api/stats/player/76561197960512640')->assertStatus(401);
    }

    public function test_player_returns_profile_with_computed_stats(): void
    {
        $user = User::factory()->create(['steam_id' => '76561197960512640']);
        $this->seedRankPlayer('STEAM_0:0:123456');

        $this->actingAs($user)
            ->getJson('/api/stats/player/76561197960512640')
            ->assertOk()
            ->assertJsonPath('data.steam', 'STEAM_0:0:123456')
            ->assertJsonPath('data.kills', 100)
            ->assertJsonPath('data.deaths', 40)
            ->assertJsonPath('data.kd_ratio', 2.5)
            ->assertJsonPath('data.headshot_rate', 0.3)
            ->assertJsonPath('data.playtime', 3600)
            ->assertJsonPath('data.rank', 1);
    }

    public function test_player_rejects_invalid_steamid(): void
    {
        $user = User::factory()->create(['steam_id' => '76561197960512640']);

        $this->actingAs($user)
            ->getJson('/api/stats/player/not-a-steamid')
            ->assertStatus(422)
            ->assertJsonPath('message', 'invalid_input');
    }

    public function test_player_not_found_returns_404(): void
    {
        $user = User::factory()->create(['steam_id' => '76561197960512640']);

        $this->actingAs($user)
            ->getJson('/api/stats/player/76561197960512640')
            ->assertStatus(404)
            ->assertJsonPath('message', 'not_found');
    }

    public function test_history_requires_authentication(): void
    {
        $this->getJson('/api/stats/servers/1/history')->assertStatus(401);
    }

    public function test_history_returns_samples_ascending(): void
    {
        $user = User::factory()->create(['steam_id' => '76561197960512640']);

        DB::table('server_stats')->insert([
            ['server_id' => 1, 'players' => 5, 'max_players' => 64, 'map' => 'de_mirage', 'recorded_at' => now()->subMinutes(10)],
            ['server_id' => 1, 'players' => 9, 'max_players' => 64, 'map' => 'de_mirage', 'recorded_at' => now()->subMinutes(5)],
            ['server_id' => 2, 'players' => 3, 'max_players' => 32, 'map' => 'de_dust2', 'recorded_at' => now()->subMinutes(5)],
        ]);

        $this->actingAs($user)
            ->getJson('/api/stats/servers/1/history')
            ->assertOk()
            ->assertJsonPath('data.server_id', 1)
            ->assertJsonCount(2, 'data.samples')
            ->assertJsonPath('data.samples.0.players', 5)
            ->assertJsonPath('data.samples.1.players', 9);
    }

    public function test_history_filtered_by_hours(): void
    {
        $user = User::factory()->create(['steam_id' => '76561197960512640']);

        DB::table('server_stats')->insert([
            ['server_id' => 1, 'players' => 4, 'max_players' => 64, 'map' => 'de_mirage', 'recorded_at' => now()->subDays(3)],
            ['server_id' => 1, 'players' => 7, 'max_players' => 64, 'map' => 'de_mirage', 'recorded_at' => now()->subMinutes(5)],
        ]);

        $this->actingAs($user)
            ->getJson('/api/stats/servers/1/history?hours=24')
            ->assertOk()
            ->assertJsonCount(1, 'data.samples')
            ->assertJsonPath('data.samples.0.players', 7);
    }

    public function test_history_unknown_server_returns_empty_samples(): void
    {
        $user = User::factory()->create(['steam_id' => '76561197960512640']);

        $this->actingAs($user)
            ->getJson('/api/stats/servers/999/history')
            ->assertOk()
            ->assertJsonCount(0, 'data.samples');
    }

    public function test_collect_command_records_nothing_for_offline_servers(): void
    {
        DB::connection('swiftly')->table('admin_servers')->insert([
            'server_id' => '127.0.0.1:27015',
            'server_ip' => '127.0.0.1',
            'server_port' => 27015,
            'last_seen_at' => now(),
        ]);

        $this->artisan('stats:collect')
            ->expectsOutputToContain('0 server sample(s)')
            ->assertExitCode(0);

        $this->assertSame(0, DB::table('server_stats')->count());
    }
}