<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\SteamId;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\CreatesPluginTables;
use Tests\TestCase;

/**
 * VIP endpoints (C7), backed by VIPCore's vip_users/vip_servers tables
 * (https://github.com/SwiftlyS2-Plugins/VIPCore).
 */
class VipTest extends TestCase
{
    use CreatesPluginTables;
    use RefreshDatabase;

    private const STEAM64 = '76561197960512640'; // account_id = 1

    protected function setUp(): void
    {
        parent::setUp();
        $this->createSwiftlyCoreTables();
        $this->createVipTables();
    }

    public function test_page_renders_for_an_authenticated_user(): void
    {
        $this->actingAs(User::factory()->create())
            ->get('/vip')
            ->assertOk();
    }

    private function accountId(): int
    {
        return SteamId::parse(self::STEAM64)->accountId();
    }

    private function addServer(array $overrides = []): int
    {
        DB::connection('vip')->table('vip_servers')->insert(array_merge([
            'serverIp' => '127.0.0.1',
            'port' => 27015,
            'created_at' => now(),
        ], $overrides));

        return (int) DB::connection('vip')->table('vip_servers')->max('serverId');
    }

    private function createStaff(int $steamid64, string $flags = 'admin.vip'): User
    {
        DB::connection('swiftly')->table('admin_admins')->insert([
            'steamid' => $steamid64,
            'name' => 'Staff',
            'flags' => $flags,
            'groups' => null,
            'immunity' => 1,
            'created_at' => now(),
            'expires_at' => null,
        ]);

        return User::factory()->create(['steam_id' => (string) $steamid64, 'name' => 'Staff']);
    }

    public function test_index_requires_authentication(): void
    {
        $this->getJson('/api/vip')->assertStatus(401);
    }

    public function test_index_lists_vip_users_for_any_authenticated_user(): void
    {
        $user = User::factory()->create();
        $serverId = $this->addServer();

        DB::connection('vip')->table('vip_users')->insert([
            'account_id' => $this->accountId(),
            'name' => 'Player One',
            'lastvisit' => now()->timestamp,
            'sid' => $serverId,
            'group' => 'vip',
            'expires' => 0,
        ]);

        $this->actingAs($user)
            ->getJson('/api/vip')
            ->assertOk()
            ->assertJsonPath('data.0.name', 'Player One')
            ->assertJsonPath('data.0.group', 'vip');
    }

    public function test_index_filters_by_server_id(): void
    {
        $user = User::factory()->create();
        $serverA = $this->addServer(['serverIp' => '1.1.1.1', 'port' => 1]);
        $serverB = $this->addServer(['serverIp' => '2.2.2.2', 'port' => 2]);

        DB::connection('vip')->table('vip_users')->insert([
            ['account_id' => 1, 'name' => 'A', 'lastvisit' => 0, 'sid' => $serverA, 'group' => 'vip', 'expires' => 0],
            ['account_id' => 2, 'name' => 'B', 'lastvisit' => 0, 'sid' => $serverB, 'group' => 'vip', 'expires' => 0],
        ]);

        $response = $this->actingAs($user)->getJson("/api/vip?server_id={$serverA}");

        $response->assertOk();
        $this->assertCount(1, $response->json('data'));
        $this->assertSame('A', $response->json('data.0.name'));
    }

    public function test_index_search_matches_name_or_steamid(): void
    {
        $user = User::factory()->create();
        $serverId = $this->addServer();

        DB::connection('vip')->table('vip_users')->insert([
            'account_id' => $this->accountId(),
            'name' => 'FindMe',
            'lastvisit' => 0,
            'sid' => $serverId,
            'group' => 'vip',
            'expires' => 0,
        ]);

        $this->actingAs($user)
            ->getJson('/api/vip?search=FindMe')
            ->assertOk()
            ->assertJsonCount(1, 'data');

        $this->actingAs($user)
            ->getJson('/api/vip?search='.self::STEAM64)
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    public function test_servers_lists_known_vip_servers(): void
    {
        $user = User::factory()->create();
        $this->addServer(['serverIp' => '10.0.0.5', 'port' => 27020]);

        $this->actingAs($user)
            ->getJson('/api/vip/servers')
            ->assertOk()
            ->assertJsonPath('data.0.serverIp', '10.0.0.5')
            ->assertJsonPath('data.0.port', 27020);
    }

    public function test_show_returns_groups_for_player(): void
    {
        $user = User::factory()->create();
        $serverId = $this->addServer();

        DB::connection('vip')->table('vip_users')->insert([
            ['account_id' => $this->accountId(), 'name' => 'P', 'lastvisit' => 0, 'sid' => $serverId, 'group' => 'vip', 'expires' => 0],
            ['account_id' => $this->accountId(), 'name' => 'P', 'lastvisit' => 0, 'sid' => $serverId, 'group' => 'gold', 'expires' => 0],
        ]);

        $this->actingAs($user)
            ->getJson('/api/vip/'.self::STEAM64)
            ->assertOk()
            ->assertJsonCount(2, 'data');
    }

    public function test_show_rejects_invalid_steamid(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->getJson('/api/vip/not-a-steamid')
            ->assertStatus(422);
    }

    public function test_grant_requires_authentication(): void
    {
        $this->postJson('/api/vip', [])->assertStatus(401);
    }

    public function test_grant_requires_admin_vip_flag(): void
    {
        $user = User::factory()->create();
        $serverId = $this->addServer();

        $this->actingAs($user)
            ->postJson('/api/vip', [
                'steamid' => self::STEAM64, 'name' => 'P', 'group' => 'vip', 'server_id' => $serverId,
            ])
            ->assertStatus(403);
    }

    public function test_grant_creates_new_row_and_audits(): void
    {
        $staff = $this->createStaff(76561197960512641);
        $serverId = $this->addServer();

        $this->actingAs($staff)
            ->postJson('/api/vip', [
                'steamid' => self::STEAM64,
                'name' => 'New VIP',
                'group' => 'vip',
                'server_id' => $serverId,
            ])
            ->assertOk()
            ->assertJsonPath('data.account_id', $this->accountId())
            ->assertJsonPath('data.group', 'vip')
            ->assertJsonPath('data.expires', 0);

        $this->assertDatabaseHas('vip_users', [
            'account_id' => $this->accountId(),
            'sid' => $serverId,
            'group' => 'vip',
        ], 'vip');

        $this->assertDatabaseHas('panel_logs', [
            'action' => 'vip.granted',
            'target_type' => 'vip_user',
            'target_id' => (string) $this->accountId(),
        ]);
    }

    public function test_grant_upserts_instead_of_duplicating(): void
    {
        $staff = $this->createStaff(76561197960512641);
        $serverId = $this->addServer();

        $grant = fn (int $expiresAt) => $this->actingAs($staff)->postJson('/api/vip', [
            'steamid' => self::STEAM64,
            'name' => 'V',
            'group' => 'vip',
            'server_id' => $serverId,
            'expires_at' => $expiresAt,
        ]);

        $grant(1000)->assertOk();
        $grant(2000)->assertOk()->assertJsonPath('data.expires', 2000);

        $this->assertSame(1, DB::connection('vip')->table('vip_users')
            ->where('account_id', $this->accountId())
            ->where('sid', $serverId)
            ->where('group', 'vip')
            ->count());
    }

    public function test_grant_rejects_unknown_server(): void
    {
        $staff = $this->createStaff(76561197960512641);

        $this->actingAs($staff)
            ->postJson('/api/vip', [
                'steamid' => self::STEAM64, 'name' => 'P', 'group' => 'vip', 'server_id' => 999,
            ])
            ->assertStatus(404);
    }

    public function test_grant_validates_input(): void
    {
        $staff = $this->createStaff(76561197960512641);

        $this->actingAs($staff)
            ->postJson('/api/vip', [])
            ->assertStatus(422);
    }

    public function test_revoke_requires_admin_vip_flag(): void
    {
        $user = User::factory()->create();
        $serverId = $this->addServer();

        $this->actingAs($user)
            ->deleteJson('/api/vip/'.self::STEAM64.'/vip', ['server_id' => $serverId])
            ->assertStatus(403);
    }

    public function test_revoke_deletes_row_and_audits(): void
    {
        $staff = $this->createStaff(76561197960512641);
        $serverId = $this->addServer();

        DB::connection('vip')->table('vip_users')->insert([
            'account_id' => $this->accountId(),
            'name' => 'P',
            'lastvisit' => 0,
            'sid' => $serverId,
            'group' => 'vip',
            'expires' => 0,
        ]);

        $this->actingAs($staff)
            ->deleteJson('/api/vip/'.self::STEAM64.'/vip', ['server_id' => $serverId])
            ->assertOk();

        $this->assertDatabaseMissing('vip_users', [
            'account_id' => $this->accountId(),
            'sid' => $serverId,
            'group' => 'vip',
        ], 'vip');

        $this->assertDatabaseHas('panel_logs', [
            'action' => 'vip.revoked',
            'target_type' => 'vip_user',
            'target_id' => (string) $this->accountId(),
        ]);
    }

    public function test_revoke_unknown_returns_404(): void
    {
        $staff = $this->createStaff(76561197960512641);
        $serverId = $this->addServer();

        $this->actingAs($staff)
            ->deleteJson('/api/vip/'.self::STEAM64.'/vip', ['server_id' => $serverId])
            ->assertStatus(404);
    }
}
