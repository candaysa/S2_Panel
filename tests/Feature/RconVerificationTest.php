<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Tests\Support\CreatesPluginTables;
use Tests\TestCase;

/**
 * RequireRconVerified (C16/C11): Bans, RCON, Admins and Groups are blocked
 * entirely while any currently *online* server has no verified RCON
 * password - see RconVerificationService's docblock for the reasoning
 * (every action any of the four can take goes out as a console command).
 * An offline server never blocks anything; Settings > Servers, where the
 * password is actually fixed, is never gated.
 */
class RconVerificationTest extends TestCase
{
    use CreatesPluginTables;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createSwiftlyCoreTables();
        $this->createSwiftlyPunishmentTables();
    }

    private function owner(): User
    {
        return User::factory()->owner()->create();
    }

    private function player(): User
    {
        return User::factory()->create();
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

    private function markOnline(int $port): void
    {
        Cache::put("server.live.127.0.0.1:{$port}", ['v' => ['players' => 1, 'max_players' => 10, 'map' => 'de_dust2']], 60);
    }

    private function markRconVerified(int $serverId): void
    {
        DB::table('health_checks')->insert([
            'component' => 'rcon:'.$serverId,
            'status' => 'ok',
            'message' => null,
            'checked_at' => now(),
        ]);
    }

    public function test_no_servers_at_all_does_not_block_anything(): void
    {
        $this->actingAs($this->owner())->get('/bans')->assertOk();
    }

    public function test_an_offline_server_with_no_rcon_password_does_not_block(): void
    {
        // Never marked online - liveFor() has nothing cached for it.
        $this->addServer(['server_port' => 27020]);

        $this->actingAs($this->owner())->get('/bans')->assertOk();
    }

    public function test_an_online_server_with_no_rcon_password_blocks_bans_rcon_admins_and_groups(): void
    {
        $id = $this->addServer(['server_port' => 27021]);
        $this->markOnline(27021);
        $owner = $this->owner();

        foreach (['/bans', '/bans/mutes', '/rcon', '/admins', '/groups'] as $uri) {
            $this->actingAs($owner)->get($uri)->assertStatus(503);
        }

        $this->actingAs($owner)
            ->getJson('/api/bans')
            ->assertStatus(503)
            ->assertJsonPath('message', 'rcon_not_verified')
            ->assertJsonPath('errors.servers.0.server_id', $id);
    }

    public function test_a_non_owner_blocked_on_bans_sees_no_server_details_in_html(): void
    {
        // Bans is open to any signed-in player - this is the one page a
        // non-owner can actually hit while the gate is tripped.
        $this->addServer(['server_port' => 27026]);
        $this->markOnline(27026);

        $response = $this->actingAs($this->player())->get('/bans');

        $response->assertStatus(503);
        $response->assertSee(__('i18n::messages.errors.rcon_not_verified_body_generic'));
        $response->assertDontSee('27026');
        $response->assertDontSee(__('i18n::messages.errors.rcon_not_verified_fix'));
    }

    public function test_the_owner_still_sees_full_server_details_in_html(): void
    {
        $this->addServer(['server_port' => 27027]);
        $this->markOnline(27027);

        $response = $this->actingAs($this->owner())->get('/bans');

        $response->assertStatus(503);
        $response->assertSee('127.0.0.1:27027');
        $response->assertSee(__('i18n::messages.errors.rcon_not_verified_fix'));
    }

    public function test_a_non_owner_gets_an_empty_servers_list_in_json(): void
    {
        $this->addServer(['server_port' => 27028]);
        $this->markOnline(27028);

        $this->actingAs($this->player())
            ->getJson('/api/bans')
            ->assertStatus(503)
            ->assertJsonPath('errors.servers', []);
    }

    public function test_a_hidden_server_with_no_rcon_password_does_not_block(): void
    {
        // Hidden means "not in use on this panel" - it should never be able
        // to lock everyone else out of Bans/RCON/Admins/Groups just because
        // nobody bothered to set a password on a server nobody uses.
        $id = $this->addServer(['server_port' => 27029]);
        $this->markOnline(27029);
        app(\App\Modules\Server\App\Services\ServerService::class)->setHidden($id, true);

        $this->actingAs($this->owner())->get('/bans')->assertOk();
    }

    public function test_settings_servers_page_and_rcon_settings_api_stay_reachable_while_blocked(): void
    {
        $this->addServer(['server_port' => 27022]);
        $this->markOnline(27022);
        $owner = $this->owner();

        $this->actingAs($owner)->get('/settings/servers')->assertOk();
        $this->actingAs($owner)->getJson('/api/rcon/settings')->assertOk();
    }

    public function test_a_verified_online_server_does_not_block(): void
    {
        $id = $this->addServer(['server_port' => 27023]);
        $this->markOnline(27023);
        $this->markRconVerified($id);

        $this->actingAs($this->owner())->getJson('/api/bans')->assertOk();
    }

    public function test_one_unverified_server_blocks_even_when_another_is_fine(): void
    {
        $good = $this->addServer(['server_port' => 27024]);
        $this->markOnline(27024);
        $this->markRconVerified($good);

        $this->addServer(['server_ip' => '127.0.0.1', 'server_port' => 27025]);
        $this->markOnline(27025);
        // No health_checks row and no rcon_settings row for this one.

        $this->actingAs($this->owner())->getJson('/api/bans')->assertStatus(503);
    }

    public function test_saving_a_correct_rcon_password_unblocks_immediately_without_waiting_for_the_next_health_tick(): void
    {
        config(['rcon.timeout' => 0.5, 'health.rcon.timeout' => 0.5]);

        [, $port] = $this->startFakeServer('secret');
        $id = $this->addServer(['server_port' => $port]);
        $this->markOnline($port);
        $owner = $this->owner();

        // Blocked before any password is on file.
        $this->actingAs($owner)->getJson('/api/bans')->assertStatus(503);

        DB::table('rcon_settings')->insert([
            'server_id' => $id,
            'password' => Crypt::encryptString('secret'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // No health:check has run since - the gate's own on-demand recheck
        // (RconVerificationService) is what has to pick this up.
        $this->actingAs($owner)->getJson('/api/bans')->assertOk();
    }

    // --- fake RCON server plumbing, mirrors RconTest ---

    /** @var array<int, array{resource, array}> */
    private array $procs = [];

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

    /**
     * @return array{0: resource, 1: int}
     */
    private function startFakeServer(string $password): array
    {
        $logFile = tempnam(sys_get_temp_dir(), 'rcon_verify_log_');
        @unlink($logFile);

        $cmd = [
            PHP_BINARY,
            base_path('tests/Support/fake_rcon_server.php'),
            $password,
            $logFile,
        ];

        $proc = proc_open($cmd, [
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

        return [$proc, $port];
    }
}
