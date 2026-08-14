<?php

namespace Tests\Feature;

use App\Modules\Audit\App\Models\PanelLog;
use App\Modules\Audit\App\Services\AuditService;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuditTest extends TestCase
{
    use RefreshDatabase;

    public function test_index_requires_authentication(): void
    {
        $this->getJson('/api/audit')->assertStatus(401);
    }

    public function test_page_renders_for_an_authenticated_user(): void
    {
        $this->actingAs(User::factory()->create())
            ->get('/audit')
            ->assertOk();
    }

    public function test_index_requires_admin_root_flag(): void
    {
        $this->actingAs(User::factory()->create())
            ->getJson('/api/audit')
            ->assertStatus(403);
    }

    public function test_owner_can_access_without_flag(): void
    {
        $this->actingAs(User::factory()->owner()->create())
            ->getJson('/api/audit')
            ->assertOk()
            ->assertJsonPath('data', [])
            ->assertJsonPath('meta.pagination.total', 0);
    }

    public function test_index_lists_logs_newest_first_with_pagination(): void
    {
        PanelLog::query()->create(['action' => 'admin.created', 'target_type' => 'admin', 'created_at' => now()->subHour()]);
        PanelLog::query()->create(['action' => 'settings.updated', 'created_at' => now()]);

        $this->actingAs(User::factory()->owner()->create())
            ->getJson('/api/audit')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.action', 'settings.updated')
            ->assertJsonPath('data.1.action', 'admin.created')
            ->assertJsonPath('meta.pagination.total', 2);
    }

    public function test_index_filters_by_action(): void
    {
        PanelLog::query()->create(['action' => 'admin.created', 'created_at' => now()]);
        PanelLog::query()->create(['action' => 'admin.updated', 'created_at' => now()]);

        $this->actingAs(User::factory()->owner()->create())
            ->getJson('/api/audit?action=admin.created')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.action', 'admin.created');
    }

    public function test_index_filters_by_actor_steamid(): void
    {
        $actor = 76561197962734863;

        PanelLog::query()->create(['action' => 'admin.created', 'actor_steamid' => $actor, 'created_at' => now()]);
        PanelLog::query()->create(['action' => 'admin.created', 'actor_steamid' => 999, 'created_at' => now()]);

        $this->actingAs(User::factory()->owner()->create())
            ->getJson("/api/audit?actor_steamid={$actor}")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.actor_steamid', $actor);
    }

    public function test_index_filters_by_target_type(): void
    {
        PanelLog::query()->create(['action' => 'admin.created', 'target_type' => 'admin', 'created_at' => now()]);
        PanelLog::query()->create(['action' => 'user.registered', 'target_type' => 'user', 'created_at' => now()]);

        $this->actingAs(User::factory()->owner()->create())
            ->getJson('/api/audit?target_type=admin')
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    public function test_index_filters_by_date_range(): void
    {
        PanelLog::query()->create(['action' => 'a', 'created_at' => now()->subDays(5)]);
        PanelLog::query()->create(['action' => 'b', 'created_at' => now()]);

        $from = now()->subDay()->toDateTimeString();

        $this->actingAs(User::factory()->owner()->create())
            ->getJson("/api/audit?from={$from}")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.action', 'b');
    }

    public function test_show_returns_single_log(): void
    {
        $log = PanelLog::query()->create([
            'action' => 'admin.created', 'target_type' => 'admin',
            'target_id' => '123', 'details' => ['name' => 'Test'], 'created_at' => now(),
        ]);

        $this->actingAs(User::factory()->owner()->create())
            ->getJson('/api/audit/'.$log->id)
            ->assertOk()
            ->assertJsonPath('data.action', 'admin.created')
            ->assertJsonPath('data.details.name', 'Test');
    }

    public function test_show_returns_404_for_missing_log(): void
    {
        $this->actingAs(User::factory()->owner()->create())
            ->getJson('/api/audit/424242')
            ->assertStatus(404)
            ->assertJsonPath('message', 'not_found');
    }

    public function test_service_records_actor_and_ip_from_request(): void
    {
        $user = User::factory()->create(['steam_id' => '76561197962734863']);

        $this->actingAs($user);
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.7'])
            ->postJson('/api/i18n/locale', ['locale' => 'en']);

        app(AuditService::class)->log('ban.created', 'ban', '42', ['reason' => 'cheating']);

        $this->assertDatabaseHas('panel_logs', [
            'action' => 'ban.created',
            'target_type' => 'ban',
            'target_id' => '42',
            'actor_steamid' => 76561197962734863,
            'actor_name' => $user->name,
            'ip_address' => '203.0.113.7',
        ]);
    }

    public function test_service_allows_system_actor(): void
    {
        app(AuditService::class)->log('health.check', 'health', null, ['ok' => true], \Illuminate\Http\Request::create('/'));

        $this->assertDatabaseHas('panel_logs', ['action' => 'health.check']);
    }
}