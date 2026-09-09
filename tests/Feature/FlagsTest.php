<?php

namespace Tests\Feature;

use App\Modules\Settings\App\Services\SettingService;
use App\Support\Flags;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\Support\CreatesPluginTables;
use Tests\TestCase;

class FlagsTest extends TestCase
{
    use CreatesPluginTables;
    use RefreshDatabase;

    private const STEAM64 = 76561197962734863;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createSwiftlyCoreTables();
    }

    public function test_returns_null_when_no_admin_row_exists(): void
    {
        $this->assertNull(Flags::for(self::STEAM64));
        $this->assertFalse(Flags::hasFlag(self::STEAM64, 'ban'));
    }

    public function test_returns_flag_profile(): void
    {
        DB::connection('swiftly')->table('admin_admins')->insert([
            'steamid' => self::STEAM64,
            'name' => 'Owner',
            'flags' => 'ban,unban,kick',
            'groups' => 'owner,root',
            'immunity' => 99,
            'created_at' => now(),
            'expires_at' => null,
        ]);

        $profile = Flags::for(self::STEAM64);

        $this->assertSame(['ban', 'unban', 'kick'], $profile['flags']);
        $this->assertSame(['owner', 'root'], $profile['groups']);
        $this->assertSame(99, $profile['immunity']);
    }

    public function test_has_flag_and_any_flag(): void
    {
        DB::connection('swiftly')->table('admin_admins')->insert([
            'steamid' => self::STEAM64,
            'name' => 'Mod',
            'flags' => 'kick',
            'groups' => null,
            'immunity' => 1,
            'created_at' => now(),
        ]);

        $this->assertTrue(Flags::hasFlag(self::STEAM64, 'kick'));
        $this->assertFalse(Flags::hasFlag(self::STEAM64, 'ban'));
        $this->assertTrue(Flags::hasAnyFlag(self::STEAM64, ['ban', 'kick']));
        $this->assertFalse(Flags::hasAnyFlag(self::STEAM64, ['ban', 'slay']));
    }

    public function test_expired_admin_is_ignored(): void
    {
        DB::connection('swiftly')->table('admin_admins')->insert([
            'steamid' => self::STEAM64,
            'name' => 'Expired',
            'flags' => 'ban',
            'groups' => null,
            'immunity' => 0,
            'created_at' => now(),
            'expires_at' => now()->subDay(),
        ]);

        $this->assertNull(Flags::for(self::STEAM64));
    }

    public function test_result_is_cached_and_invalidated(): void
    {
        DB::connection('swiftly')->table('admin_admins')->insert([
            'steamid' => self::STEAM64,
            'name' => 'Cached',
            'flags' => 'ban',
            'groups' => null,
            'immunity' => 0,
            'created_at' => now(),
        ]);

        $this->assertTrue(Flags::hasFlag(self::STEAM64, 'ban'));

        // Mutate behind the cache – still served from cache.
        DB::connection('swiftly')->table('admin_admins')->update(['flags' => 'kick']);
        $this->assertTrue(Flags::hasFlag(self::STEAM64, 'ban'));

        // Invalidate – fresh read sees the mutation.
        Flags::forget(self::STEAM64);
        $this->assertFalse(Flags::hasFlag(self::STEAM64, 'ban'));
        $this->assertTrue(Flags::hasFlag(self::STEAM64, 'kick'));
    }

    public function test_empty_flags_explode_to_empty_array(): void
    {
        DB::connection('swiftly')->table('admin_admins')->insert([
            'steamid' => self::STEAM64,
            'name' => 'NoFlags',
            'flags' => null,
            'groups' => '  ',
            'immunity' => 0,
            'created_at' => now(),
        ]);

        $profile = Flags::for(self::STEAM64);

        $this->assertSame([], $profile['flags']);
        $this->assertSame([], $profile['groups']);
    }

    public function test_cache_store_is_used(): void
    {
        Cache::shouldReceive('remember')->andReturn(['flags' => [], 'groups' => [], 'immunity' => 0]);

        Flags::for(12345);

        Cache::shouldHaveReceived('remember')
            ->with('flags:cs2_admin:12345', \Mockery::any(), \Mockery::any());
    }

    public function test_profile_is_cached_per_admin_backend(): void
    {
        DB::connection('swiftly')->table('admin_admins')->insert([
            'steamid' => self::STEAM64,
            'name' => 'Owner',
            'flags' => 'admin.root',
            'groups' => '',
            'immunity' => 100,
            'created_at' => now(),
        ]);

        $this->assertSame(['admin.root'], Flags::for(self::STEAM64)['flags']);

        // Switching the panel to the other admin plugin must not keep
        // answering out of the profile cached from the previous one: the
        // swiftly_admins tables have no row for this SteamID, so the right
        // answer after the switch is "not an admin", not the cached
        // admin.root from CS2_Admin.
        $this->createSwiftlyAdminsTables();
        app(SettingService::class)->set('admin_plugin', 'swiftly_admins');

        $this->assertNull(Flags::for(self::STEAM64));
    }
}