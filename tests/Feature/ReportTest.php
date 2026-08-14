<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\Support\CreatesPluginTables;
use Tests\TestCase;

class ReportTest extends TestCase
{
    use CreatesPluginTables;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createSwiftlyCoreTables();
        Cache::flush();
    }

    public function test_page_renders_for_an_authenticated_user(): void
    {
        $this->actingAs(User::factory()->create())
            ->get('/reports')
            ->assertOk();
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

    private function openTicket(int $reporterSteamid, array $overrides = []): void
    {
        DB::table('reports')->insert(array_merge([
            'ticket_type' => 'report',
            'status' => 'open',
            'resolution' => null,
            'reporter_steamid' => $reporterSteamid,
            'reporter_name' => 'Reporter',
            'target_steamid' => null,
            'target_name' => null,
            'report_reason' => 'Wallhacking on mirage',
            'server_id' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides));
    }

    public function test_index_requires_authentication(): void
    {
        $this->getJson('/api/reports')->assertStatus(401);
    }

    public function test_user_sees_only_own_tickets(): void
    {
        $user = User::factory()->create(['steam_id' => '76561197960512640']);
        $this->openTicket(76561197960512640);
        $this->openTicket(76561197960512641, ['report_reason' => 'Other user ticket']);

        $this->actingAs($user)
            ->getJson('/api/reports')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.reporter_steamid', 76561197960512640)
            ->assertJsonPath('meta.visible', 'mine');
    }

    public function test_staff_sees_all_tickets(): void
    {
        $staff = $this->createStaff(76561197960512610);
        $this->openTicket(76561197960512640);
        $this->openTicket(76561197960512641);

        $this->actingAs($staff)
            ->getJson('/api/reports')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('meta.visible', 'all');
    }

    public function test_index_filters_by_status(): void
    {
        $user = User::factory()->create(['steam_id' => '76561197960512640']);
        $this->openTicket(76561197960512640);
        $this->openTicket(76561197960512640, ['status' => 'closed', 'resolution' => 'APPROVED']);

        $this->actingAs($user)
            ->getJson('/api/reports?status=closed')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.status', 'closed');
    }

    public function test_store_requires_authentication(): void
    {
        $this->postJson('/api/reports', ['report_reason' => 'Custom reasons'])->assertStatus(401);
    }

    public function test_any_user_can_open_report_ticket(): void
    {
        $user = User::factory()->create(['steam_id' => '76561197960512640']);

        $this->actingAs($user)
            ->postJson('/api/reports', [
                'ticket_type' => 'report',
                'report_reason' => 'He is wallhacking',
                'target_steamid' => '76561197960512641',
                'target_name' => 'Suspect',
                'server_id' => 2,
            ])
            ->assertOk()
            ->assertJsonPath('data.ticket_type', 'report')
            ->assertJsonPath('data.status', 'open')
            ->assertJsonPath('data.reporter_steamid', 76561197960512640)
            ->assertJsonPath('data.target_steamid', 76561197960512641)
            ->assertJsonPath('meta.created', true);

        $this->assertDatabaseHas('reports', [
            'reporter_steamid' => 76561197960512640,
            'report_reason' => 'He is wallhacking',
        ]);

        $this->assertDatabaseHas('panel_logs', [
            'action' => 'report.created',
            'target_type' => 'report',
        ]);
    }

    public function test_any_user_can_open_admin_application(): void
    {
        $user = User::factory()->create(['steam_id' => '76561197960512640']);

        $this->actingAs($user)
            ->postJson('/api/reports', [
                'ticket_type' => 'admin_application',
                'report_reason' => 'I want to become an admin',
            ])
            ->assertOk()
            ->assertJsonPath('data.ticket_type', 'admin_application');
    }

    public function test_store_rejects_invalid_ticket_type(): void
    {
        $user = User::factory()->create(['steam_id' => '76561197960512640']);

        $this->actingAs($user)
            ->postJson('/api/reports', ['ticket_type' => 'bogus', 'report_reason' => 'x'])
            ->assertStatus(422)
            ->assertJsonPath('message', 'validation_failed');
    }

    public function test_store_requires_reason(): void
    {
        $user = User::factory()->create(['steam_id' => '76561197960512640']);

        $this->actingAs($user)
            ->postJson('/api/reports', [])
            ->assertStatus(422)
            ->assertJsonPath('message', 'validation_failed');
    }

    public function test_store_rejects_non_numeric_target_steamid(): void
    {
        $user = User::factory()->create(['steam_id' => '76561197960512640']);

        $this->actingAs($user)
            ->postJson('/api/reports', ['report_reason' => 'x', 'target_steamid' => 'STEAM_0:1:2'])
            ->assertStatus(422)
            ->assertJsonPath('message', 'invalid_input');
    }

    public function test_show_requires_authentication(): void
    {
        $this->openTicket(76561197960512640);

        $this->getJson('/api/reports/1')->assertStatus(401);
    }

    public function test_owner_can_view_own_ticket(): void
    {
        $user = User::factory()->create(['steam_id' => '76561197960512640']);
        $this->openTicket(76561197960512640);

        $this->actingAs($user)
            ->getJson('/api/reports/1')
            ->assertOk()
            ->assertJsonPath('data.id', 1)
            ->assertJsonPath('data.report_reason', 'Wallhacking on mirage')
            ->assertJsonCount(0, 'data.replies');
    }

    public function test_other_user_cannot_view_ticket(): void
    {
        $other = User::factory()->create(['steam_id' => '76561197960512641']);
        $this->openTicket(76561197960512640);

        $this->actingAs($other)
            ->getJson('/api/reports/1')
            ->assertStatus(403)
            ->assertJsonPath('message', 'forbidden');
    }

    public function test_staff_can_view_any_ticket(): void
    {
        $staff = $this->createStaff(76561197960512610);
        $this->openTicket(76561197960512640);

        $this->actingAs($staff)
            ->getJson('/api/reports/1')
            ->assertOk();
    }

    public function test_show_unknown_ticket_returns_404(): void
    {
        $user = User::factory()->create(['steam_id' => '76561197960512640']);

        $this->actingAs($user)
            ->getJson('/api/reports/999')
            ->assertStatus(404)
            ->assertJsonPath('message', 'not_found');

        $this->actingAs($user)
            ->getJson('/api/reports/not-a-number')
            ->assertStatus(404);
    }

    public function test_owner_can_reply_to_own_ticket(): void
    {
        $user = User::factory()->create(['steam_id' => '76561197960512640']);
        $this->openTicket(76561197960512640);

        $this->actingAs($user)
            ->postJson('/api/reports/1/reply', ['message' => 'More details: he prefires everything'])
            ->assertOk()
            ->assertJsonPath('data.message', 'More details: he prefires everything');

        $this->assertDatabaseHas('report_replies', [
            'report_id' => 1,
            'author_steamid' => 76561197960512640,
        ]);

        $this->assertDatabaseHas('panel_logs', [
            'action' => 'report.replied',
            'target_type' => 'report',
        ]);
    }

    public function test_other_user_cannot_reply(): void
    {
        $other = User::factory()->create(['steam_id' => '76561197960512641']);
        $this->openTicket(76561197960512640);

        $this->actingAs($other)
            ->postJson('/api/reports/1/reply', ['message' => 'hi'])
            ->assertStatus(403);
    }

    public function test_reply_requires_message(): void
    {
        $user = User::factory()->create(['steam_id' => '76561197960512640']);
        $this->openTicket(76561197960512640);

        $this->actingAs($user)
            ->postJson('/api/reports/1/reply', [])
            ->assertStatus(422)
            ->assertJsonPath('message', 'validation_failed');
    }

    public function test_close_requires_admin_root_flag(): void
    {
        $user = User::factory()->create(['steam_id' => '76561197960512640']);
        $staff = $this->createStaff(76561197960512610);
        $this->openTicket(76561197960512640);

        $this->actingAs($user)
            ->postJson('/api/reports/1/close', ['resolution' => 'APPROVED'])
            ->assertStatus(403);

        $this->actingAs($staff)
            ->postJson('/api/reports/1/close', ['resolution' => 'APPROVED'])
            ->assertStatus(403);
    }

    public function test_owner_can_close_ticket_with_resolution(): void
    {
        $owner = User::factory()->owner()->create(['steam_id' => '76561197960512640']);
        $this->openTicket(76561197960512640);

        $this->actingAs($owner)
            ->postJson('/api/reports/1/close', ['resolution' => 'APPROVED'])
            ->assertOk()
            ->assertJsonPath('data.status', 'closed')
            ->assertJsonPath('data.resolution', 'APPROVED')
            ->assertJsonPath('meta.closed', true);

        $this->assertDatabaseHas('reports', ['id' => 1, 'status' => 'closed', 'resolution' => 'APPROVED']);

        $this->assertDatabaseHas('panel_logs', [
            'action' => 'report.closed',
            'target_type' => 'report',
        ]);
    }

    public function test_close_rejects_invalid_resolution(): void
    {
        $owner = User::factory()->owner()->create(['steam_id' => '76561197960512640']);
        $this->openTicket(76561197960512640);

        $this->actingAs($owner)
            ->postJson('/api/reports/1/close', ['resolution' => 'MAYBE'])
            ->assertStatus(422)
            ->assertJsonPath('message', 'validation_failed');
    }

    public function test_destroy_requires_flag(): void
    {
        $user = User::factory()->create(['steam_id' => '76561197960512640']);
        $this->openTicket(76561197960512640);

        $this->actingAs($user)
            ->deleteJson('/api/reports/1')
            ->assertStatus(403);
    }

    public function test_staff_can_destroy_ticket(): void
    {
        $staff = $this->createStaff(76561197960512610);
        $this->openTicket(76561197960512640);
        DB::table('report_replies')->insert([
            'report_id' => 1,
            'author_steamid' => 76561197960512640,
            'author_name' => 'Reporter',
            'message' => 'reply payload',
            'created_at' => now(),
        ]);

        $this->actingAs($staff)
            ->deleteJson('/api/reports/1')
            ->assertOk()
            ->assertJsonPath('data.deleted', true);

        $this->assertDatabaseMissing('reports', ['id' => 1]);
        $this->assertDatabaseMissing('report_replies', ['report_id' => 1]);

        $this->assertDatabaseHas('panel_logs', [
            'action' => 'report.deleted',
            'target_type' => 'report',
        ]);
    }

    public function test_index_paginates(): void
    {
        $user = User::factory()->create(['steam_id' => '76561197960512640']);

        for ($i = 1; $i <= 6; $i++) {
            $this->openTicket(76561197960512640, ['report_reason' => "Ticket {$i}"]);
        }

        $this->actingAs($user)
            ->getJson('/api/reports?per_page=2')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('meta.pagination.total', 6)
            ->assertJsonPath('meta.pagination.last_page', 3);
    }
}