<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Health\App\Events\HealthAlert;
use App\Modules\Health\App\Models\PanelNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Tests\Support\CreatesPluginTables;
use Tests\TestCase;

/**
 * Health monitoring (C16). Database probes run against the sqlite
 * connections; RCON probes use the same fake Source RCON server as the
 * Rcon module, exercised over real TCP.
 */
class HealthTest extends TestCase
{
    use CreatesPluginTables;
    use RefreshDatabase;

    /** @var array<int, array{resource, array}> */
    private array $procs = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->createSwiftlyCoreTables();
        config(['health.rcon.timeout' => 0.5]);
        config(['health.rcon.enabled' => true]);
        Cache::flush();
    }

    protected function tearDown(): void
    {
        foreach ($this->procs as [$proc]) {
            if (is_resource($proc)) {
                proc_terminate($proc);
                proc_close($proc);
            }
        }

        $this->procs = [];

        parent::tearDown();
    }

    public function test_page_renders_for_the_owner(): void
    {
        $this->actingAs(User::factory()->owner()->create())
            ->get('/health')
            ->assertOk();
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

    private function addRconSetting(int $serverId, string $password): void
    {
        DB::table('rcon_settings')->insert([
            'server_id' => $serverId,
            'password' => Crypt::encryptString($password),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * @return array{0: resource, 1: int, 2: string}
     */
    private function startFakeServer(string $password = 'secret'): array
    {
        $logFile = tempnam(sys_get_temp_dir(), 'health_rcon_');
        @unlink($logFile);

        $proc = proc_open([
            PHP_BINARY,
            base_path('tests/Support/fake_rcon_server.php'),
            $password,
            $logFile,
        ], [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ], $pipes);

        if (! is_resource($proc)) {
            $this->fail('failed to start fake rcon server');
        }

        stream_set_timeout($pipes[1], 5);
        $line = fgets($pipes[1]);

        if ($line === false || ! str_starts_with(trim($line), 'PORT ')) {
            proc_terminate($proc);
            proc_close($proc);
            $this->fail('fake rcon server did not report a port');
        }

        $port = (int) trim(substr(trim($line), 5));
        $this->procs[] = [$proc, $pipes];

        return [$proc, $port, $logFile];
    }

    private function createOwner(int $steamid64 = 76561197960512640): User
    {
        return User::factory()->create([
            'steam_id' => (string) $steamid64,
            'name' => 'Owner',
            'is_owner' => true,
        ]);
    }

    public function test_health_status_requires_authentication(): void
    {
        $this->getJson('/api/health')->assertStatus(401);
    }

    public function test_health_status_requires_owner(): void
    {
        $staff = User::factory()->create(['steam_id' => '76561197960512640']);

        $this->actingAs($staff)
            ->getJson('/api/health')
            ->assertStatus(403);
    }

    public function test_health_status_reports_latest_checks(): void
    {
        $owner = $this->createOwner();

        DB::table('health_checks')->insert([
            ['component' => 'db:swiftly', 'status' => 'ok', 'message' => null, 'checked_at' => now()->subMinutes(10), 'created_at' => now(), 'updated_at' => now()],
            ['component' => 'db:ranks', 'status' => 'down', 'message' => 'database connection failed', 'checked_at' => now()->subMinutes(5), 'created_at' => now(), 'updated_at' => now()],
        ]);

        $this->actingAs($owner)
            ->getJson('/api/health')
            ->assertOk()
            ->assertJsonPath('data.healthy', false)
            ->assertJsonCount(2, 'data.checks')
            ->assertJsonPath('data.checks.0.component', 'db:ranks')
            ->assertJsonPath('data.checks.0.status', 'down')
            ->assertJsonPath('data.checks.1.component', 'db:swiftly')
            ->assertJsonPath('data.checks.1.status', 'ok');
    }

    public function test_health_check_records_database_probes(): void
    {
        $this->createOwner();

        $this->artisan('health:check')
            ->expectsOutputToContain('db:swiftly: ok')
            ->assertExitCode(0);

        $this->assertDatabaseHas('health_checks', [
            'component' => 'db:swiftly',
            'status' => 'ok',
        ]);

        $this->assertDatabaseHas('health_checks', [
            'component' => 'db:ranks',
            'status' => 'ok',
        ]);

        $this->assertDatabaseHas('health_checks', [
            'component' => 'db:weaponskins',
            'status' => 'ok',
        ]);

        $this->assertDatabaseCount('notifications', 0);
    }

    public function test_health_check_detects_db_failure_and_notifies_owner(): void
    {
        Event::fake([HealthAlert::class]);
        $owner = $this->createOwner();

        config(['health.databases' => ['swiftly', 'missing-connection']]);

        $this->artisan('health:check')->assertExitCode(1);

        $this->assertDatabaseHas('health_checks', [
            'component' => 'db:swiftly',
            'status' => 'ok',
        ]);

        $this->assertDatabaseHas('health_checks', [
            'component' => 'db:missing-connection',
            'status' => 'down',
            'message' => 'database connection failed',
        ]);

        $this->assertDatabaseHas('notifications', [
            'user_id' => $owner->id,
            'type' => 'health.alert',
            'title' => 'Health alert',
        ]);

        Event::assertDispatched(
            HealthAlert::class,
            fn (HealthAlert $event): bool => $event->component === 'db:missing-connection' && $event->status === 'down',
        );
    }

    public function test_health_check_does_not_repeat_notifications_for_steady_outage(): void
    {
        $this->createOwner();

        config(['health.databases' => ['swiftly', 'missing-connection']]);

        $this->artisan('health:check')->assertExitCode(1);
        $this->artisan('health:check')->assertExitCode(1);
        $this->artisan('health:check')->assertExitCode(1);

        // Down is recorded once (first observation), notification once.
        $this->assertDatabaseCount('health_checks', 2);
        $this->assertDatabaseCount('notifications', 1);
    }

    public function test_health_check_rcon_probe_success_and_no_alert(): void
    {
        $this->createOwner();
        [, $port] = $this->startFakeServer('secret');
        $id = $this->addServer(['server_ip' => '127.0.0.1', 'server_port' => $port]);
        $this->addRconSetting($id, 'secret');

        $this->artisan('health:check')->assertExitCode(0);

        $this->assertDatabaseHas('health_checks', [
            'component' => 'rcon:'.$id,
            'status' => 'ok',
        ]);

        $this->assertDatabaseCount('notifications', 0);
    }

    public function test_health_check_rcon_probe_wrong_password_alerts_owner(): void
    {
        Event::fake([HealthAlert::class]);
        $owner = $this->createOwner();

        [, $port] = $this->startFakeServer('right-password');
        $id = $this->addServer(['server_ip' => '127.0.0.1', 'server_port' => $port]);
        $this->addRconSetting($id, 'wrong-password');

        $this->artisan('health:check')->assertExitCode(1);

        $this->assertDatabaseHas('health_checks', [
            'component' => 'rcon:'.$id,
            'status' => 'down',
            'message' => 'rcon authentication failed',
        ]);

        $this->assertDatabaseHas('notifications', [
            'user_id' => $owner->id,
            'type' => 'health.alert',
        ]);

        Event::assertDispatched(HealthAlert::class);
    }

    public function test_health_check_rcon_recovers_after_outage(): void
    {
        $this->createOwner();

        [$proc, $port] = $this->startFakeServer('secret');
        $id = $this->addServer(['server_ip' => '127.0.0.1', 'server_port' => $port]);
        $this->addRconSetting($id, 'secret');

        // First sweep: server is up -> ok, no alert.
        $this->artisan('health:check')->assertExitCode(0);
        $this->assertDatabaseCount('notifications', 0);

        // Kill the server -> down, exactly one alert.
        proc_terminate($proc);
        proc_close($proc);
        usleep(200_000);

        $this->artisan('health:check')->assertExitCode(1);
        $this->assertDatabaseHas('health_checks', [
            'component' => 'rcon:'.$id,
            'status' => 'down',
        ]);
        $this->assertDatabaseCount('notifications', 1);

        // Bring a server back on the same port -> ok, no second alert.
        [, $port2] = $this->startFakeServer('secret');

        DB::connection('swiftly')->table('admin_servers')
            ->where('id', $id)
            ->update(['server_port' => $port2]);

        $this->artisan('health:check')->assertExitCode(0);
        $this->assertDatabaseHas('health_checks', [
            'component' => 'rcon:'.$id,
            'status' => 'ok',
        ]);
        $this->assertDatabaseCount('notifications', 1);
    }

    public function test_notifications_index_requires_owner(): void
    {
        $staff = User::factory()->create(['steam_id' => '76561197960512640']);

        $this->actingAs($staff)
            ->getJson('/api/notifications')
            ->assertStatus(403);
    }

    public function test_notifications_index_lists_only_own_notifications(): void
    {
        $owner = $this->createOwner();
        $otherOwner = User::factory()->create(['steam_id' => '76561197960512641', 'is_owner' => true]);

        PanelNotification::query()->create([
            'user_id' => $owner->id,
            'type' => 'health.alert',
            'title' => 'Health alert',
            'body' => 'db:ranks is down',
        ]);

        PanelNotification::query()->create([
            'user_id' => $otherOwner->id,
            'type' => 'health.alert',
            'title' => 'Health alert',
            'body' => 'rcon:1 is down',
        ]);

        $this->actingAs($owner)
            ->getJson('/api/notifications')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.read', false);
    }

    public function test_notifications_mark_read(): void
    {
        $owner = $this->createOwner();

        $notification = PanelNotification::query()->create([
            'user_id' => $owner->id,
            'type' => 'health.alert',
            'title' => 'Health alert',
            'body' => 'db:ranks is down',
        ]);

        $this->actingAs($owner)
            ->postJson("/api/notifications/{$notification->id}/read")
            ->assertOk()
            ->assertJsonPath('data.read', true);

        $this->assertDatabaseHas('notifications', [
            'id' => $notification->id,
            'user_id' => $owner->id,
        ]);

        $this->assertNotNull(PanelNotification::query()->find($notification->id)?->read_at);
    }

    public function test_notifications_mark_read_unknown_returns_404(): void
    {
        $owner = $this->createOwner();

        $this->actingAs($owner)
            ->postJson('/api/notifications/999/read')
            ->assertStatus(404)
            ->assertJsonPath('message', 'not_found');
    }

    public function test_notifications_mark_read_only_own_notification(): void
    {
        $owner = $this->createOwner();
        $otherOwner = User::factory()->create(['steam_id' => '76561197960512641', 'is_owner' => true]);

        $notification = PanelNotification::query()->create([
            'user_id' => $otherOwner->id,
            'type' => 'health.alert',
            'title' => 'Health alert',
            'body' => 'rcon:1 is down',
        ]);

        $this->actingAs($owner)
            ->postJson("/api/notifications/{$notification->id}/read")
            ->assertStatus(404);
    }

    public function test_notifications_mark_all_read(): void
    {
        $owner = $this->createOwner();

        PanelNotification::query()->create([
            'user_id' => $owner->id,
            'type' => 'health.alert',
            'title' => 'Health alert',
            'body' => 'db:ranks is down',
        ]);

        PanelNotification::query()->create([
            'user_id' => $owner->id,
            'type' => 'health.alert',
            'title' => 'Health alert',
            'body' => 'rcon:1 is down',
        ]);

        $this->actingAs($owner)
            ->postJson('/api/notifications/read-all')
            ->assertOk()
            ->assertJsonPath('data.updated', 2);

        $this->assertDatabaseMissing('notifications', ['read_at' => null]);
    }
}
