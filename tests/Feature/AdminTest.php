<?php

namespace Tests\Feature;

use App\Modules\Admin\App\Models\AdminAdmin;
use App\Modules\Admin\App\Models\AdminGroup;
use App\Modules\Admin\App\Services\AdminService;
use App\Modules\Admin\Events\AdminCreated;
use App\Modules\Admin\Events\AdminDisabled;
use App\Modules\Admin\Events\AdminUpdated;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\Support\CreatesPluginTables;
use Tests\TestCase;

class AdminTest extends TestCase
{
    use CreatesPluginTables;
    use RefreshDatabase;

    private const STEAM64 = 76561197962734863;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createSwiftlyCoreTables();
    }

    /**
     * Create a non-owner user that holds the admin.root flag in the plugin DB.
     */
    private function makeRootAdmin(): User
    {
        $user = User::factory()->create(['steam_id' => (string) self::STEAM64]);

        AdminAdmin::query()->create([
            'steamid' => self::STEAM64,
            'name' => 'Root Admin',
            'flags' => 'admin.root',
            'groups' => null,
            'immunity' => 100,
            'expires_at' => null,
        ]);

        return $user;
    }

    public function test_index_requires_authentication(): void
    {
        $this->getJson('/api/admin')->assertStatus(401);
    }

    public function test_index_requires_admin_root_flag(): void
    {
        $this->actingAs(User::factory()->create())
            ->getJson('/api/admin')
            ->assertStatus(403);
    }

    public function test_owner_can_access_without_flag(): void
    {
        $this->actingAs(User::factory()->owner()->create())
            ->getJson('/api/admin')
            ->assertOk();
    }

    public function test_root_flagged_admin_can_access(): void
    {
        $this->actingAs($this->makeRootAdmin())
            ->getJson('/api/admin')
            ->assertOk();
    }

    public function test_index_returns_empty_list(): void
    {
        $this->actingAs(User::factory()->owner()->create())
            ->getJson('/api/admin')
            ->assertOk()
            ->assertJsonPath('data', [])
            ->assertJsonPath('meta.pagination.total', 0);
    }

    public function test_index_lists_admins_and_searches_by_name(): void
    {
        AdminAdmin::query()->create(['steamid' => 1, 'name' => 'Alice', 'flags' => 'kick', 'immunity' => 1]);
        AdminAdmin::query()->create(['steamid' => 2, 'name' => 'Bob', 'flags' => 'ban', 'immunity' => 5]);

        $this->actingAs(User::factory()->owner()->create())
            ->getJson('/api/admin?search=Ali')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Alice')
            ->assertJsonPath('meta.pagination.total', 1);
    }

    public function test_index_searches_by_steamid64(): void
    {
        AdminAdmin::query()->create(['steamid' => self::STEAM64, 'name' => 'Alice', 'flags' => 'kick', 'immunity' => 1]);

        $this->actingAs(User::factory()->owner()->create())
            ->getJson('/api/admin?search=76561197962734863')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.steamid', self::STEAM64);
    }

    public function test_index_filters_active_admins(): void
    {
        AdminAdmin::query()->create(['steamid' => 1, 'name' => 'Active', 'flags' => 'kick', 'immunity' => 1]);
        AdminAdmin::query()->create([
            'steamid' => 2, 'name' => 'Expired', 'flags' => 'kick', 'immunity' => 1,
            'expires_at' => now()->subDay(),
        ]);

        $this->actingAs(User::factory()->owner()->create())
            ->getJson('/api/admin?active=1')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Active');
    }

    public function test_index_filters_inactive_admins(): void
    {
        AdminAdmin::query()->create(['steamid' => 1, 'name' => 'Active', 'flags' => 'kick', 'immunity' => 1]);
        AdminAdmin::query()->create([
            'steamid' => 2, 'name' => 'Expired', 'flags' => 'kick', 'immunity' => 1,
            'expires_at' => now()->subDay(),
        ]);

        $this->actingAs(User::factory()->owner()->create())
            ->getJson('/api/admin?active=0')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Expired');
    }

    public function test_groups_returns_plugin_groups(): void
    {
        AdminGroup::query()->create(['name' => 'Root', 'flags' => 'admin.root', 'immunity' => 100]);
        AdminGroup::query()->create(['name' => 'Mod', 'flags' => 'kick,mute', 'immunity' => 10]);

        $this->actingAs(User::factory()->owner()->create())
            ->getJson('/api/admin/groups')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.name', 'Mod');
    }

    public function test_store_creates_admin_and_normalizes_csv(): void
    {
        Event::fake([AdminCreated::class]);

        $this->actingAs(User::factory()->owner()->create())
            ->postJson('/api/admin', [
                'steamid' => 'STEAM_0:0:123456',
                'name' => 'New Admin',
                'flags' => 'ban, unban',
                'groups' => 'mod',
                'immunity' => 50,
            ])
            ->assertOk()
            ->assertJsonPath('data.steamid', 76561197960512640)
            ->assertJsonPath('data.flags', 'ban,unban')
            ->assertJsonPath('data.groups', 'mod');

        Event::assertDispatched(AdminCreated::class);

        // Audit trail row must be present.
        $this->assertDatabaseHas('panel_logs', ['action' => 'admin.created', 'target_type' => 'admin']);
    }

    public function test_store_accepts_steamid3(): void
    {
        $this->actingAs(User::factory()->owner()->create())
            ->postJson('/api/admin', [
                'steamid' => '[U:1:123456]',
                'name' => 'Steam3 Admin',
            ])
            ->assertOk()
            ->assertJsonPath('data.steamid', 76561197960389184);
    }

    public function test_store_rejects_invalid_steamid(): void
    {
        $this->actingAs(User::factory()->owner()->create())
            ->postJson('/api/admin', ['steamid' => 'not-a-steamid', 'name' => 'Fake'])
            ->assertStatus(422)
            ->assertJsonPath('message', 'validation_failed');
    }

    public function test_store_rejects_duplicate_steamid(): void
    {
        AdminAdmin::query()->create(['steamid' => self::STEAM64, 'name' => 'Existing', 'flags' => 'ban', 'immunity' => 1]);

        $this->actingAs(User::factory()->owner()->create())
            ->postJson('/api/admin', ['steamid' => (string) self::STEAM64, 'name' => 'Duplicate'])
            ->assertStatus(422)
            ->assertJsonPath('message', 'already_exists');
    }

    public function test_store_requires_name(): void
    {
        $this->actingAs(User::factory()->owner()->create())
            ->postJson('/api/admin', ['steamid' => (string) self::STEAM64])
            ->assertStatus(422);
    }

    public function test_update_modifies_admin_and_writes_audit(): void
    {
        Event::fake([AdminUpdated::class]);

        $admin = AdminAdmin::query()->create([
            'steamid' => self::STEAM64, 'name' => 'Old Name',
            'flags' => 'kick', 'immunity' => 1,
        ]);

        $this->actingAs(User::factory()->owner()->create())
            ->putJson('/api/admin/'.$admin->id, ['name' => 'New Name', 'flags' => 'ban,unban', 'immunity' => 90])
            ->assertOk()
            ->assertJsonPath('data.name', 'New Name')
            ->assertJsonPath('data.flags', 'ban,unban')
            ->assertJsonPath('data.immunity', 90);

        Event::assertDispatched(AdminUpdated::class);
        $this->assertDatabaseHas('panel_logs', ['action' => 'admin.updated', 'target_type' => 'admin']);
    }

    public function test_update_rejects_steamid_collision(): void
    {
        AdminAdmin::query()->create(['steamid' => self::STEAM64, 'name' => 'First', 'flags' => 'kick', 'immunity' => 1]);
        $second = AdminAdmin::query()->create(['steamid' => 999, 'name' => 'Second', 'flags' => 'kick', 'immunity' => 1]);

        $this->actingAs(User::factory()->owner()->create())
            ->putJson('/api/admin/'.$second->id, ['steamid' => (string) self::STEAM64])
            ->assertStatus(422)
            ->assertJsonPath('message', 'already_exists');
    }

    public function test_update_returns_404_for_missing_admin(): void
    {
        $this->actingAs(User::factory()->owner()->create())
            ->putJson('/api/admin/424242', ['name' => 'Nobody'])
            ->assertStatus(404)
            ->assertJsonPath('message', 'not_found');
    }

    public function test_destroy_disables_instead_of_deleting(): void
    {
        Event::fake([AdminDisabled::class]);

        $admin = AdminAdmin::query()->create([
            'steamid' => self::STEAM64, 'name' => 'Doomed',
            'flags' => 'ban', 'immunity' => 5,
        ]);

        $this->actingAs(User::factory()->owner()->create())
            ->deleteJson('/api/admin/'.$admin->id)
            ->assertOk()
            ->assertJsonPath('meta.disabled', true);

        // Row must still exist – project rule: never delete from plugin DB.
        $this->assertDatabaseHas('admin_admins', ['id' => $admin->id, 'name' => 'Doomed'], 'swiftly');
        $this->assertTrue($admin->refresh()->expires_at->isPast());

        Event::assertDispatched(AdminDisabled::class);
        $this->assertDatabaseHas('panel_logs', ['action' => 'admin.disabled', 'target_type' => 'admin']);
    }

    public function test_destroy_returns_404_for_missing_admin(): void
    {
        $this->actingAs(User::factory()->owner()->create())
            ->deleteJson('/api/admin/424242')
            ->assertStatus(404);
    }

    public function test_create_invalidates_flags_cache(): void
    {
        $this->actingAs(User::factory()->owner()->create())
            ->postJson('/api/admin', ['steamid' => (string) self::STEAM64, 'name' => 'Cached', 'flags' => 'admin.root'])
            ->assertOk();

        $this->assertTrue(\App\Support\Flags::hasFlag(self::STEAM64, 'admin.root'));
    }
}