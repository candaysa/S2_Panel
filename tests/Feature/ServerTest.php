<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\Support\CreatesPluginTables;
use Tests\TestCase;

class ServerTest extends TestCase
{
    use CreatesPluginTables;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createSwiftlyCoreTables();
        config(['server.a2s_timeout' => 0.1]);
        Cache::flush();
    }

    private function addServer(array $overrides = []): int
    {
        DB::connection('swiftly')->table('admin_servers')->insert(array_merge([
            'server_id' => '127.0.0.1:27015',
            'server_ip' => '127.0.0.1',
            'server_port' => 27015,
            'last_seen_at' => now(),
        ], $overrides));

        return (int) DB::connection('swiftly')->table('admin_servers')->max('id');
    }

    public function test_index_requires_authentication(): void
    {
        $this->getJson('/api/servers')->assertStatus(401);
    }

    public function test_index_lists_all_servers_offline(): void
    {
        $user = User::factory()->create(['steam_id' => '76561197960512640']);
        $this->addServer(['server_id' => '127.0.0.1:27015', 'server_ip' => '127.0.0.1', 'server_port' => 27015]);
        $this->addServer(['server_id' => '127.0.0.1:27016', 'server_ip' => '127.0.0.1', 'server_port' => 27016]);

        $this->actingAs($user)
            ->getJson('/api/servers')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.server_id', '127.0.0.1:27015')
            ->assertJsonPath('data.0.online', false)
            ->assertJsonPath('data.0.live', null)
            ->assertJsonPath('meta.pagination.total', 2);
    }

    public function test_index_paginates(): void
    {
        $user = User::factory()->create(['steam_id' => '76561197960512640']);

        for ($i = 1; $i <= 4; $i++) {
            $this->addServer(['server_id' => "10.0.0.{$i}:27015", 'server_ip' => "10.0.0.{$i}", 'server_port' => 27015]);
        }

        $this->actingAs($user)
            ->getJson('/api/servers?per_page=2')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('meta.pagination.total', 4)
            ->assertJsonPath('meta.pagination.last_page', 2);
    }

    public function test_index_searches_by_ip_or_id(): void
    {
        $user = User::factory()->create(['steam_id' => '76561197960512640']);
        $this->addServer(['server_id' => '10.0.0.1:27015', 'server_ip' => '10.0.0.1']);
        $this->addServer(['server_id' => '10.0.0.2:27015', 'server_ip' => '10.0.0.2']);

        $this->actingAs($user)
            ->getJson('/api/servers?search=10.0.0.2')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.server_ip', '10.0.0.2');
    }

    public function test_show_requires_authentication(): void
    {
        $id = $this->addServer();

        $this->getJson("/api/servers/{$id}")->assertStatus(401);
    }

    public function test_show_returns_server_with_live_null(): void
    {
        $user = User::factory()->create(['steam_id' => '76561197960512640']);
        $id = $this->addServer(['server_id' => '127.0.0.1:27015', 'server_ip' => '127.0.0.1', 'server_port' => 27015]);

        $this->actingAs($user)
            ->getJson("/api/servers/{$id}")
            ->assertOk()
            ->assertJsonPath('data.server_id', '127.0.0.1:27015')
            ->assertJsonPath('data.online', false)
            ->assertJsonPath('data.live', null);
    }

    public function test_show_unknown_server_returns_404(): void
    {
        $user = User::factory()->create(['steam_id' => '76561197960512640']);

        $this->actingAs($user)
            ->getJson('/api/servers/999')
            ->assertStatus(404)
            ->assertJsonPath('message', 'not_found');

        $this->actingAs($user)
            ->getJson('/api/servers/not-a-number')
            ->assertStatus(404);
    }
}