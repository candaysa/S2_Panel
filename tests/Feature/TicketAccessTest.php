<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Settings\App\Services\SettingService;
use App\Support\TicketAccess;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\Support\CreatesPluginTables;
use Tests\TestCase;

/**
 * TicketAccess (Settings > Tickets): who staffs each ticket category -
 * nobody but the owner, a specific admin group, or ANY_ADMIN ("every admin,
 * regardless of group"). Checked against both supported admin plugins,
 * since Flags::for() (what ANY_ADMIN and the group check both read) has a
 * completely different table per plugin.
 */
class TicketAccessTest extends TestCase
{
    use CreatesPluginTables;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createSwiftlyCoreTables();
        Cache::flush();
    }

    private function owner(): User
    {
        return User::factory()->owner()->create();
    }

    private function player(int $steamId64): User
    {
        return User::factory()->create(['steam_id' => (string) $steamId64]);
    }

    private function setCategory(string $category, ?string $value): void
    {
        app(SettingService::class)->set(TicketAccess::settingKey($category), $value);
    }

    // --- owner ---

    public function test_owner_is_always_staff_regardless_of_setting(): void
    {
        $owner = $this->owner();

        $this->assertTrue(TicketAccess::isStaff($owner, 'report'));

        $this->setCategory('report', TicketAccess::ANY_ADMIN);
        $this->assertTrue(TicketAccess::isStaff($owner, 'report'));
    }

    // --- unconfigured category ---

    public function test_an_unconfigured_category_is_owner_only(): void
    {
        $admin = $this->player(76561197960512610);
        DB::connection('swiftly')->table('admin_admins')->insert([
            'steamid' => 76561197960512610,
            'name' => 'Staff',
            'flags' => '',
            'groups' => null,
            'immunity' => 1,
            'created_at' => now(),
            'expires_at' => null,
        ]);

        $this->assertFalse(TicketAccess::isStaff($admin, 'report'));
    }

    // --- CS2_Admin backend (default) ---

    public function test_cs2_admin_specific_group_only_staffs_its_own_members(): void
    {
        $this->setCategory('report', 'Moderator');

        DB::connection('swiftly')->table('admin_admins')->insert([
            ['steamid' => 111, 'name' => 'In group', 'flags' => '', 'groups' => 'Moderator', 'immunity' => 1, 'created_at' => now(), 'expires_at' => null],
            ['steamid' => 222, 'name' => 'Other group', 'flags' => '', 'groups' => 'Support', 'immunity' => 1, 'created_at' => now(), 'expires_at' => null],
        ]);

        $this->assertTrue(TicketAccess::isStaff($this->player(111), 'report'));
        $this->assertFalse(TicketAccess::isStaff($this->player(222), 'report'));
    }

    public function test_cs2_admin_any_admin_staffs_every_admin_regardless_of_group(): void
    {
        $this->setCategory('report', TicketAccess::ANY_ADMIN);

        DB::connection('swiftly')->table('admin_admins')->insert([
            ['steamid' => 111, 'name' => 'Grouped', 'flags' => '', 'groups' => 'Moderator', 'immunity' => 1, 'created_at' => now(), 'expires_at' => null],
            ['steamid' => 222, 'name' => 'No group at all', 'flags' => 'admin.generic', 'groups' => null, 'immunity' => 1, 'created_at' => now(), 'expires_at' => null],
        ]);

        $this->assertTrue(TicketAccess::isStaff($this->player(111), 'report'));
        $this->assertTrue(TicketAccess::isStaff($this->player(222), 'report'));

        // A regular player with no admin_admins row at all is still not staff.
        $this->assertFalse(TicketAccess::isStaff($this->player(333), 'report'));
    }

    // --- official swiftlys2-plugins/admins backend ---

    public function test_official_plugin_any_admin_staffs_every_admin_regardless_of_group(): void
    {
        $this->createSwiftlyAdminsTables();
        app(SettingService::class)->set('admin_plugin', 'swiftly_admins');
        $this->setCategory('ban_appeal', TicketAccess::ANY_ADMIN);

        DB::connection('swiftly')->table('admins')->insert([
            [
                'SteamId64' => 444,
                'Name' => 'Grouped',
                'Permissions' => json_encode([]),
                'Groups' => json_encode(['Moderator']),
                'Immunity' => 1,
            ],
            [
                'SteamId64' => 555,
                'Name' => 'No group at all',
                'Permissions' => json_encode(['@css/generic']),
                'Groups' => json_encode([]),
                'Immunity' => 1,
            ],
        ]);

        $this->assertTrue(TicketAccess::isStaff($this->player(444), 'ban_appeal'));
        $this->assertTrue(TicketAccess::isStaff($this->player(555), 'ban_appeal'));
        $this->assertFalse(TicketAccess::isStaff($this->player(666), 'ban_appeal'));
    }

    public function test_official_plugin_specific_group_only_staffs_its_own_members(): void
    {
        $this->createSwiftlyAdminsTables();
        app(SettingService::class)->set('admin_plugin', 'swiftly_admins');
        $this->setCategory('ban_appeal', 'Moderator');

        DB::connection('swiftly')->table('admins')->insert([
            ['SteamId64' => 444, 'Name' => 'In group', 'Permissions' => json_encode([]), 'Groups' => json_encode(['Moderator']), 'Immunity' => 1],
            ['SteamId64' => 555, 'Name' => 'Other group', 'Permissions' => json_encode([]), 'Groups' => json_encode(['Support']), 'Immunity' => 1],
        ]);

        $this->assertTrue(TicketAccess::isStaff($this->player(444), 'ban_appeal'));
        $this->assertFalse(TicketAccess::isStaff($this->player(555), 'ban_appeal'));
    }

    // --- decide permission stays independent of the category setting ---

    public function test_any_admin_does_not_grant_decide_permission(): void
    {
        $this->setCategory('report', TicketAccess::ANY_ADMIN);

        DB::connection('swiftly')->table('admin_admins')->insert([
            'steamid' => 777,
            'name' => 'Generic admin',
            'flags' => 'admin.generic',
            'groups' => null,
            'immunity' => 1,
            'created_at' => now(),
            'expires_at' => null,
        ]);

        $admin = $this->player(777);

        $this->assertTrue(TicketAccess::isStaff($admin, 'report'));
        $this->assertFalse(TicketAccess::canDecide($admin));
    }
}
