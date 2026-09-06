<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * The in-game admin action log (admin_log), as read by the Audit module.
 * Distinct from AuditTest, which covers the panel's own trail.
 */
class AdminLogTest extends TestCase
{
    use RefreshDatabase;

    private const ADMIN_STEAM64 = '76561198000000001';

    private const OTHER_STEAM64 = '76561198000000002';

    private const TARGET_STEAM64 = '76561198000000003';

    private function seedLog(): void
    {
        DB::connection('swiftly')->table('admin_log')->insert([
            [
                'admin_name' => 'Root',
                'admin_steamid' => (int) self::ADMIN_STEAM64,
                'action' => 'ban',
                'target_steamid' => (int) self::TARGET_STEAM64,
                'details' => 'cheating',
                'server_id' => 1,
                'created_at' => now()->subHour(),
            ],
            [
                'admin_name' => 'Root',
                'admin_steamid' => (int) self::ADMIN_STEAM64,
                'action' => 'kick',
                'target_steamid' => (int) self::TARGET_STEAM64,
                'details' => 'afk',
                'server_id' => 1,
                'created_at' => now()->subMinutes(30),
            ],
            [
                'admin_name' => 'Other',
                'admin_steamid' => (int) self::OTHER_STEAM64,
                'action' => 'slay',
                'target_steamid' => (int) self::TARGET_STEAM64,
                'details' => null,
                'server_id' => 2,
                'created_at' => now(),
            ],
        ]);
    }

    public function test_index_requires_authentication(): void
    {
        $this->getJson('/api/audit/admin-log')->assertStatus(401);
    }

    public function test_index_requires_admin_root_flag(): void
    {
        $this->actingAs(User::factory()->create())
            ->getJson('/api/audit/admin-log')
            ->assertStatus(403);
    }

    public function test_owner_sees_every_entry_newest_first(): void
    {
        $this->seedLog();

        $this->actingAs(User::factory()->owner()->create())
            ->getJson('/api/audit/admin-log')
            ->assertOk()
            ->assertJsonPath('meta.available', true)
            ->assertJsonPath('meta.pagination.total', 3)
            ->assertJsonPath('data.0.action', 'slay');
    }

    public function test_admin_filter_narrows_to_one_admins_history(): void
    {
        $this->seedLog();

        $response = $this->actingAs(User::factory()->owner()->create())
            ->getJson('/api/audit/admin-log?admin='.self::ADMIN_STEAM64)
            ->assertOk()
            ->assertJsonPath('meta.pagination.total', 2);

        foreach ($response->json('data') as $row) {
            $this->assertSame(self::ADMIN_STEAM64, $row['admin_steamid']);
        }
    }

    public function test_search_matches_the_target_steamid_too(): void
    {
        $this->seedLog();

        $this->actingAs(User::factory()->owner()->create())
            ->getJson('/api/audit/admin-log?search='.self::TARGET_STEAM64)
            ->assertOk()
            ->assertJsonPath('meta.pagination.total', 3);
    }

    public function test_steamids_stay_strings_on_the_wire(): void
    {
        // A SteamID64 exceeds JavaScript's safe integer range - as a JSON
        // number it silently loses its last digit in the browser.
        $this->seedLog();

        $row = $this->actingAs(User::factory()->owner()->create())
            ->getJson('/api/audit/admin-log')
            ->assertOk()
            ->json('data.0');

        $this->assertIsString($row['admin_steamid']);
        $this->assertIsString($row['target_steamid']);
    }

    public function test_filters_list_each_admin_once_with_a_count(): void
    {
        $this->seedLog();

        $data = $this->actingAs(User::factory()->owner()->create())
            ->getJson('/api/audit/admin-log/filters')
            ->assertOk()
            ->json('data');

        $this->assertCount(2, $data['admins']);
        // Ordered by how much they have done, so the busiest admin is first.
        $this->assertSame(self::ADMIN_STEAM64, $data['admins'][0]['steamid']);
        $this->assertSame(2, $data['admins'][0]['actions']);
        $this->assertSame(['ban', 'kick', 'slay'], $data['actions']);
    }

    public function test_a_plugin_that_keeps_no_such_log_reports_unavailable_rather_than_failing(): void
    {
        // The official admins plugin has no equivalent table. That is an
        // expected configuration, not an error - see AdminLogService.
        Schema::connection('swiftly')->drop('admin_log');

        $this->actingAs(User::factory()->owner()->create())
            ->getJson('/api/audit/admin-log')
            ->assertOk()
            ->assertJsonPath('meta.available', false)
            ->assertJsonPath('data', []);
    }
}
