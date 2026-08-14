<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\Support\CreatesPluginTables;
use Tests\TestCase;

class AppealTest extends TestCase
{
    use CreatesPluginTables;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createSwiftlyCoreTables();
        $this->createSwiftlyPunishmentTables();
        Mail::fake();
        Cache::flush();
    }

    public function test_page_renders_for_an_authenticated_user(): void
    {
        $this->actingAs(User::factory()->create())
            ->get('/appeals')
            ->assertOk();
    }

    private function giveActiveBan(int $steamid64, array $overrides = []): void
    {
        DB::connection('swiftly')->table('admin_bans')->insert(array_merge([
            'steamid' => $steamid64,
            'target_name' => 'Banned Player',
            'reason' => 'Wallhack',
            'status' => 'active',
            'expires_at' => null,
            'created_at' => now(),
        ], $overrides));
    }

    private function createStaff(int $steamid64, string $flags = 'admin.generic'): User
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

    private function openAppeal(int $steamid64, array $overrides = []): void
    {
        DB::table('appeals')->insert(array_merge([
            'steamid' => $steamid64,
            'name' => 'Banned Player',
            'ban_id' => null,
            'reason' => 'I want to appeal my ban',
            'status' => 'PENDING',
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides));
    }

    public function test_index_requires_authentication(): void
    {
        $this->getJson('/api/appeals')->assertStatus(401);
    }

    public function test_user_sees_only_own_appeals(): void
    {
        $user = User::factory()->create(['steam_id' => '76561197960512640']);
        $this->openAppeal(76561197960512640);
        $this->openAppeal(76561197960512641);

        $this->actingAs($user)
            ->getJson('/api/appeals')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.steamid', 76561197960512640)
            ->assertJsonPath('meta.visible', 'mine');
    }

    public function test_staff_sees_all_appeals(): void
    {
        $staff = $this->createStaff(76561197960512610);
        $this->openAppeal(76561197960512640);
        $this->openAppeal(76561197960512641);

        $this->actingAs($staff)
            ->getJson('/api/appeals')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('meta.visible', 'all');
    }

    public function test_store_requires_authentication(): void
    {
        $this->postJson('/api/appeals', ['reason' => 'please unban'])->assertStatus(401);
    }

    public function test_store_rejects_without_active_ban(): void
    {
        $user = User::factory()->create(['steam_id' => '76561197960512640']);

        $this->actingAs($user)
            ->postJson('/api/appeals', ['reason' => 'I am innocent'])
            ->assertStatus(422)
            ->assertJsonPath('message', 'invalid_input')
            ->assertJsonPath('errors.reason.0', 'no_active_ban');
    }

    public function test_store_creates_pending_appeal_with_active_ban(): void
    {
        $user = User::factory()->create(['steam_id' => '76561197960512640', 'name' => 'Banned Player']);
        $this->giveActiveBan(76561197960512640);

        $this->actingAs($user)
            ->postJson('/api/appeals', ['reason' => 'It was a mistake', 'ban_id' => 7])
            ->assertOk()
            ->assertJsonPath('data.status', 'PENDING')
            ->assertJsonPath('data.steamid', 76561197960512640)
            ->assertJsonPath('data.reason', 'It was a mistake')
            ->assertJsonPath('data.ban_id', 7)
            ->assertJsonPath('meta.created', true);

        $this->assertDatabaseHas('appeals', [
            'steamid' => 76561197960512640,
            'status' => 'PENDING',
        ]);

        $this->assertDatabaseHas('panel_logs', [
            'action' => 'appeal.created',
            'target_type' => 'appeal',
        ]);
    }

    public function test_store_rejects_duplicate_pending_appeal(): void
    {
        $user = User::factory()->create(['steam_id' => '76561197960512640']);
        $this->giveActiveBan(76561197960512640);
        $this->openAppeal(76561197960512640);

        $this->actingAs($user)
            ->postJson('/api/appeals', ['reason' => 'Second appeal'])
            ->assertStatus(422)
            ->assertJsonPath('message', 'invalid_input')
            ->assertJsonPath('errors.reason.0', 'duplicate_pending_appeal');

        $this->assertSame(1, DB::table('appeals')->where('steamid', 76561197960512640)->count());
    }

    public function test_store_allows_new_appeal_after_rejection(): void
    {
        $user = User::factory()->create(['steam_id' => '76561197960512640']);
        $this->giveActiveBan(76561197960512640);
        $this->openAppeal(76561197960512640, ['status' => 'REJECTED', 'decided_at' => now()]);

        $this->actingAs($user)
            ->postJson('/api/appeals', ['reason' => 'Appeal after rejection'])
            ->assertOk()
            ->assertJsonPath('data.status', 'PENDING');
    }

    public function test_store_requires_reason(): void
    {
        $user = User::factory()->create(['steam_id' => '76561197960512640']);
        $this->giveActiveBan(76561197960512640);

        $this->actingAs($user)
            ->postJson('/api/appeals', [])
            ->assertStatus(422)
            ->assertJsonPath('message', 'validation_failed');
    }

    public function test_show_requires_authentication(): void
    {
        $this->openAppeal(76561197960512640);

        $this->getJson('/api/appeals/1')->assertStatus(401);
    }

    public function test_owner_can_view_own_appeal(): void
    {
        $user = User::factory()->create(['steam_id' => '76561197960512640']);
        $this->openAppeal(76561197960512640);

        $this->actingAs($user)
            ->getJson('/api/appeals/1')
            ->assertOk()
            ->assertJsonPath('data.id', 1)
            ->assertJsonPath('data.reason', 'I want to appeal my ban');
    }

    public function test_other_user_cannot_view_appeal(): void
    {
        $other = User::factory()->create(['steam_id' => '76561197960512641']);
        $this->openAppeal(76561197960512640);

        $this->actingAs($other)
            ->getJson('/api/appeals/1')
            ->assertStatus(403)
            ->assertJsonPath('message', 'forbidden');
    }

    public function test_staff_can_view_any_appeal(): void
    {
        $staff = $this->createStaff(76561197960512610);
        $this->openAppeal(76561197960512640);

        $this->actingAs($staff)
            ->getJson('/api/appeals/1')
            ->assertOk();
    }

    public function test_show_unknown_appeal_returns_404(): void
    {
        $user = User::factory()->create(['steam_id' => '76561197960512640']);

        $this->actingAs($user)
            ->getJson('/api/appeals/999')
            ->assertStatus(404)
            ->assertJsonPath('message', 'not_found');

        $this->actingAs($user)
            ->getJson('/api/appeals/not-a-number')
            ->assertStatus(404);
    }

    public function test_decide_requires_admin_root_flag(): void
    {
        $user = User::factory()->create(['steam_id' => '76561197960512640']);
        $staff = $this->createStaff(76561197960512610);
        $this->openAppeal(76561197960512640);

        $this->actingAs($user)
            ->postJson('/api/appeals/1/decide', ['status' => 'APPROVED'])
            ->assertStatus(403);

        $this->actingAs($staff)
            ->postJson('/api/appeals/1/decide', ['status' => 'APPROVED'])
            ->assertStatus(403);
    }

    public function test_owner_can_decide_own_appeal(): void
    {
        $owner = User::factory()->owner()->create(['steam_id' => '76561197960512640']);
        $this->openAppeal(76561197960512640);

        $this->actingAs($owner)
            ->postJson('/api/appeals/1/decide', ['status' => 'APPROVED', 'decision_note' => 'Seems fair'])
            ->assertOk()
            ->assertJsonPath('data.status', 'APPROVED')
            ->assertJsonPath('data.decision_note', 'Seems fair')
            ->assertJsonPath('data.decided_by', 76561197960512640)
            ->assertJsonPath('data.decided_at', fn ($value) => $value !== null)
            ->assertJsonPath('meta.decided', true);

        $this->assertDatabaseHas('appeals', [
            'id' => 1,
            'status' => 'APPROVED',
            'decided_by' => 76561197960512640,
        ]);

        $this->assertDatabaseHas('panel_logs', [
            'action' => 'appeal.decided',
            'target_type' => 'appeal',
        ]);

        Mail::assertNothingSent();
    }

    public function test_decide_rejects_with_note(): void
    {
        $owner = User::factory()->owner()->create(['steam_id' => '76561197960512640']);
        $this->openAppeal(76561197960512640);

        $this->actingAs($owner)
            ->postJson('/api/appeals/1/decide', ['status' => 'REJECTED', 'decision_note' => 'Evidence is clear'])
            ->assertOk()
            ->assertJsonPath('data.status', 'REJECTED');
    }

    public function test_decide_blocks_second_decision(): void
    {
        $owner = User::factory()->owner()->create(['steam_id' => '76561197960512640']);
        $this->openAppeal(76561197960512640, ['status' => 'APPROVED', 'decided_at' => now()]);

        $this->actingAs($owner)
            ->postJson('/api/appeals/1/decide', ['status' => 'REJECTED'])
            ->assertStatus(422)
            ->assertJsonPath('message', 'invalid_input')
            ->assertJsonPath('errors.status.0', 'already_decided');
    }

    public function test_decide_rejects_invalid_status(): void
    {
        $owner = User::factory()->owner()->create(['steam_id' => '76561197960512640']);
        $this->openAppeal(76561197960512640);

        $this->actingAs($owner)
            ->postJson('/api/appeals/1/decide', ['status' => 'MAYBE'])
            ->assertStatus(422)
            ->assertJsonPath('message', 'validation_failed');
    }
}