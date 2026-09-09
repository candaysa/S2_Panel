<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Rcon\App\Models\RconSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Tests\Support\CreatesPluginTables;
use Tests\TestCase;

/**
 * RCON endpoints (C11). Real TCP integration: a fake Source RCON server
 * is spawned as a separate PHP process (proc_open) and the panel talks to
 * it over the loopback interface, so the hand-rolled Rcon client is
 * exercised end-to-end (auth handshake + exec + packet parsing).
 */
class RconTest extends TestCase
{
    use CreatesPluginTables;
    use RefreshDatabase;

    /** @var array<int, array{resource, array}> */
    private array $procs = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->createSwiftlyCoreTables();
        config(['rcon.timeout' => 0.5]);
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

    public function test_page_renders_for_a_user_with_the_rcon_flag(): void
    {
        $staff = $this->createStaff(76561197960287930);

        $this->actingAs($staff)
            ->get('/rcon')
            ->assertOk();
    }

    /**
     * The route carries flag:admin.rcon (routes/web.php); a plain
     * authenticated user with no Swiftly admin row at all must be denied,
     * not shown the page. This used to assert assertOk() for exactly this
     * user - passing only because nothing had actually exercised the
     * flag check - which would have masked a real authorization regression
     * here reading as green.
     */
    public function test_page_is_forbidden_without_the_rcon_flag(): void
    {
        $this->actingAs(User::factory()->create())
            ->get('/rcon')
            ->assertForbidden();
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

    private function createStaff(int $steamid64, string $flags = 'admin.rcon'): User
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

    /**
     * Spawn the fake RCON server and return [proc, port, logfile].
     *
     * @return array{0: resource, 1: int, 2: string}
     */
    private function startFakeServer(string $password = 'secret', ?string $logFile = null): array
    {
        $logFile ??= tempnam(sys_get_temp_dir(), 'rcon_log_');
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

        return [$proc, $port, $logFile];
    }

    /** @return list<string> */
    private function loggedCommands(string $logFile): array
    {
        $content = is_file($logFile) ? file_get_contents($logFile) : '';

        return array_values(array_filter(array_map('trim', explode("\n", (string) $content))));
    }

    public function test_settings_save_requires_authentication(): void
    {
        $this->postJson('/api/rcon/settings', [
            'server_id' => 1,
            'password' => 'secret',
        ])->assertStatus(401);
    }

    public function test_settings_save_requires_admin_rcon_flag(): void
    {
        $user = User::factory()->create(['steam_id' => '76561197960512640']);
        $id = $this->addServer();

        $this->actingAs($user)
            ->postJson('/api/rcon/settings', ['server_id' => $id, 'password' => 'secret'])
            ->assertStatus(403);
    }

    public function test_settings_save_stores_encrypted_password_and_audits(): void
    {
        $staff = $this->createStaff(76561197960512640);
        $id = $this->addServer();

        $this->actingAs($staff)
            ->postJson('/api/rcon/settings', ['server_id' => $id, 'password' => 'hunter2'])
            ->assertOk()
            ->assertJsonPath('data.server_id', $id);

        $row = DB::table('rcon_settings')->where('server_id', $id)->first();
        $this->assertNotNull($row);
        $this->assertNotSame('hunter2', $row->password, 'password must be encrypted at rest');
        $this->assertSame('hunter2', Crypt::decryptString($row->password));

        $this->assertDatabaseHas('panel_logs', [
            'action' => 'rcon.settings.saved',
            'target_type' => 'server',
            'target_id' => (string) $id,
        ]);
    }

    public function test_settings_save_validates_input(): void
    {
        $staff = $this->createStaff(76561197960512640);
        $id = $this->addServer();

        $this->actingAs($staff)
            ->postJson('/api/rcon/settings', ['server_id' => $id])
            ->assertStatus(422);

        $this->actingAs($staff)
            ->postJson('/api/rcon/settings', ['password' => 'secret'])
            ->assertStatus(422);
    }

    public function test_settings_save_unknown_server_returns_404(): void
    {
        $staff = $this->createStaff(76561197960512640);

        $this->actingAs($staff)
            ->postJson('/api/rcon/settings', ['server_id' => 999, 'password' => 'secret'])
            ->assertStatus(404);
    }

    public function test_settings_remove_deletes_and_audits(): void
    {
        $staff = $this->createStaff(76561197960512640);
        $id = $this->addServer();
        DB::table('rcon_settings')->insert([
            'server_id' => $id,
            'password' => Crypt::encryptString('hunter2'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($staff)
            ->deleteJson("/api/rcon/settings/{$id}")
            ->assertOk()
            ->assertJsonPath('data.server_id', $id);

        $this->assertDatabaseMissing('rcon_settings', ['server_id' => $id]);
        $this->assertDatabaseHas('panel_logs', [
            'action' => 'rcon.settings.removed',
            'target_type' => 'server',
            'target_id' => (string) $id,
        ]);
    }

    public function test_settings_remove_unknown_server_returns_404(): void
    {
        $staff = $this->createStaff(76561197960512640);

        $this->actingAs($staff)
            ->deleteJson('/api/rcon/settings/999')
            ->assertStatus(404);
    }

    public function test_command_requires_flag(): void
    {
        $user = User::factory()->create(['steam_id' => '76561197960512640']);
        $id = $this->addServer();

        $this->actingAs($user)
            ->postJson("/api/rcon/{$id}/command", ['command' => 'say hi'])
            ->assertStatus(403);
    }

    public function test_command_without_settings_returns_422(): void
    {
        $staff = $this->createStaff(76561197960512640);
        $id = $this->addServer();

        $this->actingAs($staff)
            ->postJson("/api/rcon/{$id}/command", ['command' => 'say hi'])
            ->assertStatus(422)
            ->assertJsonPath('errors.rcon.0', 'rcon_not_configured');
    }

    public function test_command_unknown_server_returns_404(): void
    {
        $staff = $this->createStaff(76561197960512640);

        $this->actingAs($staff)
            ->postJson('/api/rcon/999/command', ['command' => 'say hi'])
            ->assertStatus(404)
            ->assertJsonPath('message', 'not_found');
    }

    public function test_command_offline_server_returns_502(): void
    {
        $staff = $this->createStaff(76561197960512640);
        $id = $this->addServer(['server_ip' => '127.0.0.1', 'server_port' => 1]);
        DB::table('rcon_settings')->insert([
            'server_id' => $id,
            'password' => Crypt::encryptString('secret'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($staff)
            ->postJson("/api/rcon/{$id}/command", ['command' => 'say hi'])
            ->assertStatus(502)
            ->assertJsonPath('message', 'rcon_unreachable');
    }

    public function test_command_wrong_password_returns_502(): void
    {
        [$proc, $port, $logFile] = $this->startFakeServer('right-password');

        $staff = $this->createStaff(76561197960512640);
        $id = $this->addServer(['server_ip' => '127.0.0.1', 'server_port' => $port]);
        DB::table('rcon_settings')->insert([
            'server_id' => $id,
            'password' => Crypt::encryptString('wrong-password'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($staff)
            ->postJson("/api/rcon/{$id}/command", ['command' => 'say hi'])
            ->assertStatus(502)
            ->assertJsonPath('message', 'rcon_unreachable');

        $this->assertSame([], $this->loggedCommands($logFile), 'no command may reach a server that rejected auth');
    }

    public function test_command_runs_on_live_server_and_audits(): void
    {
        [, $port, $logFile] = $this->startFakeServer('secret');

        $staff = $this->createStaff(76561197960512640);
        $id = $this->addServer(['server_ip' => '127.0.0.1', 'server_port' => $port]);
        DB::table('rcon_settings')->insert([
            'server_id' => $id,
            'password' => Crypt::encryptString('secret'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($staff)
            ->postJson("/api/rcon/{$id}/command", ['command' => 'say hello'])
            ->assertOk()
            ->assertJsonPath('data.response', 'OK')
            ->assertJsonPath('data.server_id', $id);

        $this->assertSame(['say hello'], $this->loggedCommands($logFile));

        $this->assertDatabaseHas('panel_logs', [
            'action' => 'rcon.command.executed',
            'target_type' => 'server',
            'target_id' => (string) $id,
        ]);
    }

    public function test_history_returns_past_commands_newest_last(): void
    {
        [, $port, $logFile] = $this->startFakeServer('secret');

        $staff = $this->createStaff(76561197960512640);
        $id = $this->addServer(['server_ip' => '127.0.0.1', 'server_port' => $port]);
        DB::table('rcon_settings')->insert([
            'server_id' => $id,
            'password' => Crypt::encryptString('secret'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // An earlier entry, inserted directly rather than through a second
        // live round trip - the fake server this suite spins up handles one
        // connection per test, matching every other test in this file.
        // command()'s own audit write is exercised for real below; this
        // only needs to seed something for it to sort after.
        DB::table('panel_logs')->insert([
            'action' => 'rcon.command.executed',
            'target_type' => 'server',
            'target_id' => (string) $id,
            'actor_name' => 'Staff',
            'details' => json_encode(['server_id' => $id, 'command' => 'say first', 'ok' => true]),
            'created_at' => now()->subMinute(),
        ]);

        $this->actingAs($staff)->postJson("/api/rcon/{$id}/command", ['command' => 'say second'])->assertOk();

        $response = $this->actingAs($staff)
            ->getJson("/api/rcon/{$id}/history")
            ->assertOk();

        $commands = collect($response->json('data'))->pluck('command')->all();
        $this->assertSame(['say first', 'say second'], $commands);
        $this->assertTrue($response->json('data.1.ok'));
        $this->assertSame('Staff', $response->json('data.1.actor_name'));
    }

    public function test_history_requires_the_rcon_flag(): void
    {
        $id = $this->addServer();

        $this->actingAs(User::factory()->create())
            ->getJson("/api/rcon/{$id}/history")
            ->assertStatus(403);
    }

    public function test_history_unknown_server_returns_404(): void
    {
        $this->actingAs($this->createStaff(76561197960512640))
            ->getJson('/api/rcon/999999/history')
            ->assertStatus(404);
    }

    /**
     * @return array{0: User, 1: int, 2: string} [staff, serverId, logFile]
     */
    private function liveServerWithStaff(): array
    {
        [, $port, $logFile] = $this->startFakeServer('secret');

        $staff = $this->createStaff(76561197960512640);
        $id = $this->addServer(['server_ip' => '127.0.0.1', 'server_port' => $port]);
        DB::table('rcon_settings')->insert([
            'server_id' => $id,
            'password' => Crypt::encryptString('secret'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return [$staff, $id, $logFile];
    }

    public function test_mute_sends_sw_mute_with_quoted_arguments(): void
    {
        [$staff, $id, $logFile] = $this->liveServerWithStaff();

        $this->actingAs($staff)
            ->postJson("/api/rcon/{$id}/mute", ['target' => 'SKINS UzayLegit', 'duration' => '60', 'reason' => 'mic spam'])
            ->assertOk();

        // Quoted, so a name with a space stays one argument instead of the
        // plugin reading "UzayLegit" as the duration.
        $this->assertSame(['sw_mute "SKINS UzayLegit" 60 "mic spam"'], $this->loggedCommands($logFile));
    }

    public function test_gag_sends_sw_gag_command(): void
    {
        [$staff, $id, $logFile] = $this->liveServerWithStaff();

        $this->actingAs($staff)
            ->postJson("/api/rcon/{$id}/gag", ['target' => 'Player', 'duration' => '0'])
            ->assertOk();

        $this->assertSame(['sw_gag "Player" 0'], $this->loggedCommands($logFile));
    }

    public function test_warn_sends_sw_warn_without_a_duration(): void
    {
        [$staff, $id, $logFile] = $this->liveServerWithStaff();

        $this->actingAs($staff)
            ->postJson("/api/rcon/{$id}/warn", ['target' => 'Player', 'reason' => 'first warning'])
            ->assertOk();

        $this->assertSame(['sw_warn "Player" "first warning"'], $this->loggedCommands($logFile));
    }

    public function test_unban_sends_sw_unban_command(): void
    {
        [$staff, $id, $logFile] = $this->liveServerWithStaff();

        $this->actingAs($staff)
            ->postJson("/api/rcon/{$id}/unban", ['target' => '76561197960287930'])
            ->assertOk();

        $this->assertSame(['sw_unban "76561197960287930"'], $this->loggedCommands($logFile));
    }

    public function test_unmute_and_ungag_send_their_own_commands(): void
    {
        [$staff, $id, $logFile] = $this->liveServerWithStaff();

        $this->actingAs($staff)
            ->postJson("/api/rcon/{$id}/unmute", ['target' => 'Player'])
            ->assertOk();

        $this->assertSame(['sw_unmute "Player"'], $this->loggedCommands($logFile));
    }

    public function test_lift_rejects_an_action_outside_the_whitelist(): void
    {
        $id = $this->addServer();

        $this->actingAs($this->createStaff(76561197960512640))
            ->postJson("/api/rcon/{$id}/unsomething", ['target' => 'Player'])
            ->assertStatus(404);
    }

    /**
     * The official swiftlys2-plugins/admins plugin uses unprefixed command
     * names, and has no warn concept at all - see RconService::COMMANDS.
     */
    /**
     * Acts as the owner rather than createStaff(): switching admin_plugin
     * also switches which table Flags reads (admin_admins vs the official
     * plugin's own admins/Permissions schema), and this is testing command
     * naming, not the flag source. RequireFlag always lets the owner
     * through, which keeps the two concerns apart.
     */
    public function test_official_plugin_uses_unprefixed_names(): void
    {
        app(\App\Modules\Settings\App\Services\SettingService::class)->set('admin_plugin', 'swiftly_admins');

        [, $id, $logFile] = $this->liveServerWithStaff();

        $this->actingAs(User::factory()->owner()->create())
            ->postJson("/api/rcon/{$id}/mute", ['target' => 'Player', 'duration' => '30'])
            ->assertOk();

        $this->assertSame(['mute "Player" 30'], $this->loggedCommands($logFile));
    }

    public function test_official_plugin_refuses_warn_with_a_clear_error(): void
    {
        app(\App\Modules\Settings\App\Services\SettingService::class)->set('admin_plugin', 'swiftly_admins');

        [, $id] = $this->liveServerWithStaff();

        $this->actingAs(User::factory()->owner()->create())
            ->postJson("/api/rcon/{$id}/warn", ['target' => 'Player'])
            ->assertStatus(422)
            ->assertJsonPath('errors.rcon.0', 'warn_unsupported_for_plugin');
    }

    public function test_kick_sends_sw_kick_command(): void
    {
        [, $port, $logFile] = $this->startFakeServer('secret');

        $staff = $this->createStaff(76561197960512640);
        $id = $this->addServer(['server_ip' => '127.0.0.1', 'server_port' => $port]);
        DB::table('rcon_settings')->insert([
            'server_id' => $id,
            'password' => Crypt::encryptString('secret'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($staff)
            ->postJson("/api/rcon/{$id}/kick", ['target' => 'STEAM_1:0:123', 'reason' => 'rule break'])
            ->assertOk();

        $this->assertSame(['sw_kick "STEAM_1:0:123" "rule break"'], $this->loggedCommands($logFile));
    }

    public function test_kick_requires_target(): void
    {
        $staff = $this->createStaff(76561197960512640);
        $id = $this->addServer();

        $this->actingAs($staff)
            ->postJson("/api/rcon/{$id}/kick", ['reason' => 'x'])
            ->assertStatus(422);
    }

    public function test_ban_sends_sw_ban_command(): void
    {
        [, $port, $logFile] = $this->startFakeServer('secret');

        $staff = $this->createStaff(76561197960512640);
        $id = $this->addServer(['server_ip' => '127.0.0.1', 'server_port' => $port]);
        DB::table('rcon_settings')->insert([
            'server_id' => $id,
            'password' => Crypt::encryptString('secret'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($staff)
            ->postJson("/api/rcon/{$id}/ban", [
                'target' => 'STEAM_1:0:123',
                'duration' => '1440',
                'reason' => 'wallhack',
            ])
            ->assertOk();

        $this->assertSame(['sw_ban "STEAM_1:0:123" 1440 "wallhack"'], $this->loggedCommands($logFile));
    }

    public function test_ban_requires_duration(): void
    {
        $staff = $this->createStaff(76561197960512640);
        $id = $this->addServer();

        $this->actingAs($staff)
            ->postJson("/api/rcon/{$id}/ban", ['target' => 'STEAM_1:0:123'])
            ->assertStatus(422);
    }

    public function test_slay_sends_sw_slay_command(): void
    {
        [, $port, $logFile] = $this->startFakeServer('secret');

        $staff = $this->createStaff(76561197960512640);
        $id = $this->addServer(['server_ip' => '127.0.0.1', 'server_port' => $port]);
        DB::table('rcon_settings')->insert([
            'server_id' => $id,
            'password' => Crypt::encryptString('secret'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($staff)
            ->postJson("/api/rcon/{$id}/slay", ['target' => 'STEAM_1:0:123'])
            ->assertOk();

        $this->assertSame(['sw_slay "STEAM_1:0:123"'], $this->loggedCommands($logFile));
    }

    public function test_slay_requires_target(): void
    {
        $staff = $this->createStaff(76561197960512640);
        $id = $this->addServer();

        $this->actingAs($staff)
            ->postJson("/api/rcon/{$id}/slay", [])
            ->assertStatus(422);
    }

    /**
     * Regression tests for command injection via kick/ban/slay: the Source
     * console splits multiple commands on ";" and newlines, and the
     * service builds the command by plain string concatenation. An admin
     * restricted to "kick" must not be able to smuggle a second command
     * (e.g. granting themselves rcon on another server, or banning another
     * admin) through the target/reason/duration fields.
     */
    public function test_kick_rejects_command_separators_in_target(): void
    {
        [, $port, $logFile] = $this->startFakeServer('secret');
        $staff = $this->createStaff(76561197960512640);
        $id = $this->addServer(['server_ip' => '127.0.0.1', 'server_port' => $port]);
        DB::table('rcon_settings')->insert([
            'server_id' => $id, 'password' => Crypt::encryptString('secret'),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->actingAs($staff)
            ->postJson("/api/rcon/{$id}/kick", ['target' => "STEAM_1:0:123; sw_ban 1 0 gotcha"])
            ->assertStatus(422);

        $this->actingAs($staff)
            ->postJson("/api/rcon/{$id}/kick", ['target' => "STEAM_1:0:123\nsw_ban 1 0 gotcha"])
            ->assertStatus(422);

        $this->assertSame([], $this->loggedCommands($logFile), 'no injected command may reach the server');
    }

    public function test_kick_rejects_command_separators_in_reason(): void
    {
        $staff = $this->createStaff(76561197960512640);
        $id = $this->addServer();

        $this->actingAs($staff)
            ->postJson("/api/rcon/{$id}/kick", ['target' => 'STEAM_1:0:123', 'reason' => "x; sw_exec evil"])
            ->assertStatus(422);
    }

    public function test_ban_rejects_command_separators_and_non_numeric_duration(): void
    {
        $staff = $this->createStaff(76561197960512640);
        $id = $this->addServer();

        $this->actingAs($staff)
            ->postJson("/api/rcon/{$id}/ban", ['target' => "x; sw_exec evil", 'duration' => '0'])
            ->assertStatus(422);

        $this->actingAs($staff)
            ->postJson("/api/rcon/{$id}/ban", ['target' => 'STEAM_1:0:123', 'duration' => '0; sw_exec evil'])
            ->assertStatus(422);

        $this->actingAs($staff)
            ->postJson("/api/rcon/{$id}/ban", ['target' => 'STEAM_1:0:123', 'duration' => '0', 'reason' => "x\nsw_exec evil"])
            ->assertStatus(422);
    }

    /**
     * -1 is the plugins' own "permanent" convention - 0 is a real,
     * near-instant duration, not permanent, so the validator has to accept
     * the one negative value that actually means something here.
     */
    public function test_ban_accepts_a_duration_of_negative_one_as_permanent(): void
    {
        [$staff, $id, $logFile] = $this->liveServerWithStaff();

        $this->actingAs($staff)
            ->postJson("/api/rcon/{$id}/ban", ['target' => 'Player', 'duration' => '-1', 'reason' => 'cheating'])
            ->assertOk();

        $this->assertSame(['sw_ban "Player" -1 "cheating"'], $this->loggedCommands($logFile));
    }

    public function test_ban_rejects_a_negative_duration_other_than_negative_one(): void
    {
        $staff = $this->createStaff(76561197960512640);
        $id = $this->addServer();

        $this->actingAs($staff)
            ->postJson("/api/rcon/{$id}/ban", ['target' => 'Player', 'duration' => '-2'])
            ->assertStatus(422);
    }

    public function test_slay_rejects_command_separators_in_target(): void
    {
        $staff = $this->createStaff(76561197960512640);
        $id = $this->addServer();

        $this->actingAs($staff)
            ->postJson("/api/rcon/{$id}/slay", ['target' => "STEAM_1:0:123; sw_exec evil"])
            ->assertStatus(422);
    }
}