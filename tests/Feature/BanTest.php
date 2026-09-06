<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\CreatesPluginTables;
use Tests\TestCase;

class BanTest extends TestCase
{
    use CreatesPluginTables;
    use RefreshDatabase;

    private const STEAM64 = 76561197962734863;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createSwiftlyCoreTables();
        $this->createSwiftlyPunishmentTables();
    }

    /**
     * Insert a ban row directly into the plugin table.
     *
     * @param  array<string, mixed>  $overrides
     */
    private function insertBan(array $overrides = []): void
    {
        DB::connection('swiftly')->table('admin_bans')->insert(array_merge([
            'steamid' => self::STEAM64,
            'target_name' => 'Cheater',
            'target_type' => 'steamid',
            'admin_name' => 'Owner',
            'admin_steamid' => 76561197960265728,
            'reason' => 'cheating',
            'is_global' => false,
            'server_id' => 1,
            'created_at' => now()->subDay(),
            'expires_at' => null,
            'status' => 'active',
        ], $overrides));
    }

    public function test_index_requires_authentication(): void
    {
        $this->getJson('/api/bans')->assertStatus(401);
    }

    public function test_page_renders_for_an_authenticated_user(): void
    {
        $this->actingAs(User::factory()->owner()->create())
            ->get('/bans')
            ->assertOk();
    }

    public function test_index_is_visible_to_any_authenticated_player(): void
    {
        // Read-only community tier, same as VIP/Skins/Tickets - no
        // moderation flag required, just a logged-in session.
        $this->actingAs(User::factory()->create())
            ->getJson('/api/bans')
            ->assertOk();
    }

    public function test_owner_can_access_without_flag(): void
    {
        $this->actingAs(User::factory()->owner()->create())
            ->getJson('/api/bans')
            ->assertOk()
            ->assertJsonPath('data', [])
            ->assertJsonPath('meta.pagination.total', 0);
    }

    public function test_admin_with_moderation_flag_can_access(): void
    {
        $admin = User::factory()->create(['steam_id' => (string) self::STEAM64]);

        DB::connection('swiftly')->table('admin_admins')->insert([
            'steamid' => self::STEAM64,
            'name' => 'Mod',
            'flags' => 'admin.ban',
            'groups' => null,
            'immunity' => 1,
            'created_at' => now(),
            'expires_at' => null,
        ]);

        $this->actingAs($admin)
            ->getJson('/api/bans')
            ->assertOk();
    }

    public function test_any_of_multiple_flags_grants_access(): void
    {
        // Distinct SteamID64 – avoids the per-SteamID flag cache shared with
        // the previous test.
        $kickerSteam64 = 76561197962734864;

        $admin = User::factory()->create(['steam_id' => (string) $kickerSteam64]);

        // Only admin.kick held – still one of the allowed moderation flags.
        DB::connection('swiftly')->table('admin_admins')->insert([
            'steamid' => $kickerSteam64,
            'name' => 'Kicker',
            'flags' => 'admin.kick',
            'groups' => null,
            'immunity' => 1,
            'created_at' => now(),
            'expires_at' => null,
        ]);

        $this->actingAs($admin)
            ->getJson('/api/bans')
            ->assertOk();
    }

    public function test_index_lists_only_active_bans_by_default(): void
    {
        $this->insertBan(['target_name' => 'Active']);
        $this->insertBan([
            'target_name' => 'Unbanned',
            'status' => 'unbanned',
            'unban_date' => now(),
            'unban_admin_name' => 'Owner',
        ]);
        $this->insertBan([
            'target_name' => 'Expired',
            'status' => 'active',
            'expires_at' => now()->subHour(),
        ]);

        $this->actingAs(User::factory()->owner()->create())
            ->getJson('/api/bans')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.target_name', 'Active');
    }

    public function test_index_with_status_expired_returns_lifted_bans(): void
    {
        $this->insertBan(['target_name' => 'Active']);
        $this->insertBan(['target_name' => 'Unbanned', 'status' => 'unbanned']);
        $this->insertBan([
            'target_name' => 'Expired',
            'status' => 'active',
            'expires_at' => now()->subHour(),
        ]);

        $this->actingAs(User::factory()->owner()->create())
            ->getJson('/api/bans?status=expired')
            ->assertOk()
            ->assertJsonCount(2, 'data');
    }

    public function test_index_normalizes_status_for_display(): void
    {
        $this->insertBan(['target_name' => 'StillActive', 'status' => 'active']);
        $this->insertBan(['target_name' => 'ManuallyLifted', 'status' => 'unbanned']);
        $this->insertBan(['target_name' => 'RanOut', 'status' => 'active', 'expires_at' => now()->subHour()]);

        $rows = $this->actingAs(User::factory()->owner()->create())
            ->getJson('/api/bans?status=all')
            ->assertOk()
            ->json('data');

        $byName = collect($rows)->keyBy('target_name');
        $this->assertSame('active', $byName['StillActive']['status']);
        $this->assertSame('removed', $byName['ManuallyLifted']['status']);
        $this->assertSame('expired', $byName['RanOut']['status']);
    }

    public function test_index_with_status_all_ignores_state(): void
    {
        $this->insertBan(['target_name' => 'Active']);
        $this->insertBan(['target_name' => 'Unbanned', 'status' => 'unbanned']);

        $this->actingAs(User::factory()->owner()->create())
            ->getJson('/api/bans?status=all')
            ->assertOk()
            ->assertJsonCount(2, 'data');
    }

    public function test_index_searches_by_player_name(): void
    {
        $this->insertBan(['target_name' => 'Johnny Bravo']);
        $this->insertBan(['target_name' => 'Jane Doe']);

        $this->actingAs(User::factory()->owner()->create())
            ->getJson('/api/bans?search=Johnny')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.target_name', 'Johnny Bravo');
    }

    public function test_index_searches_by_steamid64(): void
    {
        $this->insertBan(['target_name' => 'Targeted']);

        $this->actingAs(User::factory()->owner()->create())
            ->getJson('/api/bans?search=76561197962734863')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.steamid', self::STEAM64);
    }

    public function test_index_searches_by_steamid2_and_steamid3(): void
    {
        // steamid2: account 12345 odd → STEAM_0:1:6172 — compute steamid64.
        $steamId2 = 'STEAM_0:0:123456';    // account (123456<<1)|0 = 246912
        $steamId64 = 76561197960265728 + 246912;
        $this->insertBan(['target_name' => 'FromSteam2', 'steamid' => $steamId64]);

        $steamId3 = '[U:1:31337]';          // account 31337
        $steamId64b = 76561197960265728 + 31337;
        $this->insertBan(['target_name' => 'FromSteam3', 'steamid' => $steamId64b]);

        $this->actingAs(User::factory()->owner()->create())
            ->getJson('/api/bans?search='.$steamId2)
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.target_name', 'FromSteam2');

        $this->actingAs(User::factory()->owner()->create())
            ->getJson('/api/bans?search='.urlencode($steamId3))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.target_name', 'FromSteam3');
    }

    public function test_index_rejects_unknown_type(): void
    {
        $this->actingAs(User::factory()->owner()->create())
            ->getJson('/api/bans?type=slay')
            ->assertStatus(422)
            ->assertJsonPath('message', 'invalid_input');
    }

    public function test_index_rejects_unknown_status(): void
    {
        $this->actingAs(User::factory()->owner()->create())
            ->getJson('/api/bans?status=maybe')
            ->assertStatus(422)
            ->assertJsonPath('message', 'invalid_input');
    }

    public function test_index_supports_all_punishment_types(): void
    {
        DB::connection('swiftly')->table('admin_mutes')->insert([
            'steamid' => self::STEAM64, 'target_name' => 'Muted', 'status' => 'active', 'created_at' => now(),
        ]);
        DB::connection('swiftly')->table('admin_gags')->insert([
            'steamid' => self::STEAM64, 'target_name' => 'Gagged', 'status' => 'active', 'created_at' => now(),
        ]);
        DB::connection('swiftly')->table('admin_warns')->insert([
            'steamid' => self::STEAM64, 'target_name' => 'Warned', 'created_at' => now(),
        ]);

        $user = User::factory()->owner()->create();

        foreach (['mute' => 'Muted', 'gag' => 'Gagged', 'warn' => 'Warned'] as $type => $name) {
            $this->actingAs($user)
                ->getJson("/api/bans?type={$type}")
                ->assertOk()
                ->assertJsonCount(1, 'data')
                ->assertJsonPath('data.0.target_name', $name)
                ->assertJsonPath('meta.type', $type);
        }
    }

    public function test_index_paginates_and_reports_total(): void
    {
        for ($i = 1; $i <= 30; $i++) {
            $this->insertBan(['target_name' => 'Player '.$i]);
        }

        $this->actingAs(User::factory()->owner()->create())
            ->getJson('/api/bans?per_page=10')
            ->assertOk()
            ->assertJsonCount(10, 'data')
            ->assertJsonPath('meta.pagination.total', 30)
            ->assertJsonPath('meta.pagination.last_page', 3);
    }
}