<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Appeal\App\Models\Appeal;
use App\Modules\Report\App\Models\Report;
use App\Modules\Vip\App\Services\VipService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\CreatesPluginTables;
use Tests\TestCase;

/**
 * GET /api/ranks/{steamid}/activity - the player profile's moderation/
 * community section (punishment summary, VIP standing/history, reports +
 * appeals timeline). Unlike the rest of the Rank module this is
 * staff-only (flag:admin.generic), not public - see RankController::activity().
 */
class PlayerActivityTest extends TestCase
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
        $this->createSwiftlyPunishmentTables();
        $this->createVipTables();
    }

    /**
     * A non-owner user holding admin.generic - the realistic caller for
     * this endpoint (most staff reviewing a profile are not the owner).
     */
    private function staff(): User
    {
        DB::connection('swiftly')->table('admin_admins')->insert([
            'steamid' => 76561197960287930,
            'name' => 'Staff',
            'flags' => 'admin.generic',
            'groups' => null,
            'immunity' => 1,
            'created_at' => now(),
            'expires_at' => null,
        ]);

        return User::factory()->create(['steam_id' => '76561197960287930', 'name' => 'Staff']);
    }

    public function test_requires_authentication(): void
    {
        $this->getJson('/api/ranks/'.self::STEAM_ID2.'/activity')->assertStatus(401);
    }

    public function test_requires_admin_generic_flag(): void
    {
        $this->actingAs(User::factory()->create())
            ->getJson('/api/ranks/'.self::STEAM_ID2.'/activity')
            ->assertStatus(403);
    }

    public function test_rejects_an_invalid_steamid(): void
    {
        $this->actingAs($this->staff())
            ->getJson('/api/ranks/not-a-steamid/activity')
            ->assertStatus(422);
    }

    public function test_aggregates_punishments_vip_and_timeline_for_a_player(): void
    {
        // Punishments: one active ban, one expired mute - both must be
        // counted in "total", only the ban in "active".
        DB::connection('swiftly')->table('admin_bans')->insert([
            'steamid' => self::STEAM_ID64, 'admin_name' => 'Admin', 'reason' => 'cheating',
            'status' => 'active', 'created_at' => now(), 'expires_at' => null,
        ]);
        DB::connection('swiftly')->table('admin_mutes')->insert([
            'steamid' => self::STEAM_ID64, 'admin_name' => 'Admin', 'reason' => 'spam',
            'status' => 'expired', 'created_at' => now()->subDays(2), 'expires_at' => now()->subDay(),
        ]);

        // VIP: grant through the real service so the audit trail this
        // endpoint reads back is the one the app actually produces.
        DB::connection('vip')->table('vip_servers')->insert([
            'serverId' => 1, 'serverIp' => '127.0.0.1', 'port' => 27015,
        ]);
        app(VipService::class)->grant(self::STEAM_ID2, 'Player', 'vip', 1, null, User::factory()->create());

        // Reports: one as the target, filed by someone else.
        Report::query()->create([
            'ticket_type' => 'report', 'status' => 'open',
            'reporter_steamid' => 76561197960111222, 'reporter_name' => 'Reporter',
            'target_steamid' => self::STEAM_ID64, 'target_name' => 'Player',
            'report_reason' => 'teamkilling',
        ]);

        // Appeal: filed by the player themself.
        Appeal::query()->create([
            'steamid' => self::STEAM_ID64, 'name' => 'Player',
            'reason' => 'it was a mistake', 'status' => 'PENDING',
        ]);

        $response = $this->actingAs($this->staff())
            ->getJson('/api/ranks/'.self::STEAM_ID2.'/activity')
            ->assertOk();

        $response->assertJsonPath('data.punishments.ban.active', 1)
            ->assertJsonPath('data.punishments.ban.total', 1)
            ->assertJsonPath('data.punishments.mute.active', 0)
            ->assertJsonPath('data.punishments.mute.total', 1)
            ->assertJsonPath('data.punishments.gag.total', 0)
            ->assertJsonPath('data.punishments.warn.total', 0);

        $response->assertJsonPath('data.vip.active.0.group', 'vip')
            ->assertJsonPath('data.vip.history.0.action', 'vip.granted');

        $timeline = $response->json('data.timeline');
        $this->assertCount(2, $timeline);
        $types = array_column($timeline, 'type');
        $this->assertContains('report', $types);
        $this->assertContains('appeal', $types);

        $report = collect($timeline)->firstWhere('type', 'report');
        $this->assertSame('target', $report['role']);

        $this->assertSame([], $response->json('data.notes'));
    }

    public function test_a_staff_note_can_be_added_and_appears_in_activity(): void
    {
        $staff = $this->staff();

        $this->actingAs($staff)
            ->postJson('/api/ranks/'.self::STEAM_ID2.'/notes', ['note' => 'Watch this one closely.'])
            ->assertOk()
            ->assertJsonPath('data.note', 'Watch this one closely.')
            ->assertJsonPath('data.author_name', 'Staff');

        $response = $this->actingAs($staff)
            ->getJson('/api/ranks/'.self::STEAM_ID2.'/activity')
            ->assertOk();

        $this->assertCount(1, $response->json('data.notes'));
        $this->assertSame('Watch this one closely.', $response->json('data.notes.0.note'));

        $this->assertDatabaseHas('panel_logs', [
            'action' => 'player_note.created',
            'target_type' => 'player',
            'target_id' => (string) self::STEAM_ID64,
        ]);
    }

    public function test_a_staff_note_requires_admin_generic_flag(): void
    {
        $this->actingAs(User::factory()->create())
            ->postJson('/api/ranks/'.self::STEAM_ID2.'/notes', ['note' => 'Nope'])
            ->assertStatus(403);
    }

    public function test_a_staff_note_can_be_deleted(): void
    {
        $staff = $this->staff();

        $created = $this->actingAs($staff)
            ->postJson('/api/ranks/'.self::STEAM_ID2.'/notes', ['note' => 'Temporary'])
            ->json('data');

        $this->actingAs($staff)
            ->deleteJson('/api/ranks/notes/'.$created['id'])
            ->assertOk();

        $this->assertDatabaseMissing('player_notes', ['id' => $created['id']]);
    }

    public function test_deleting_an_unknown_note_returns_404(): void
    {
        $this->actingAs($this->staff())
            ->deleteJson('/api/ranks/notes/999999')
            ->assertStatus(404);
    }
}
