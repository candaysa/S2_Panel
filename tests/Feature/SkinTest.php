<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Tests\Support\CreatesPluginTables;
use Tests\TestCase;

class SkinTest extends TestCase
{
    use CreatesPluginTables;
    use RefreshDatabase;

    /** STEAM_0:0:123456 */
    private const STEAM_ID2 = 'STEAM_0:0:123456';

    private const STEAM_ID64 = '76561197960512640';

    protected function setUp(): void
    {
        parent::setUp();
        $this->createSwiftlyCoreTables();
        $this->createSkinTables();
    }

    public function test_page_renders_for_an_authenticated_user(): void
    {
        $this->actingAs(User::factory()->create())
            ->get('/skins')
            ->assertOk();
    }

    private function insertWeapon(array $overrides = []): void
    {
        DB::connection('weaponskins')->table('wp_player_skins')->insert(array_merge([
            'steamid' => self::STEAM_ID64,
            'weapon_team' => 2,
            'weapon_defindex' => 7,
            'weapon_paint_id' => 38,
            'weapon_wear' => 0.25,
            'weapon_seed' => 42,
            'weapon_nametag' => 'TestStattrak',
            'weapon_stattrak' => true,
            'weapon_stattrak_count' => 1337,
            'weapon_sticker_0' => '0;0;0;0;0;0;0',
            'weapon_sticker_1' => '0;0;0;0;0;0;0',
            'weapon_sticker_2' => '0;0;0;0;0;0;0',
            'weapon_sticker_3' => '0;0;0;0;0;0;0',
            'weapon_sticker_4' => '0;0;0;0;0;0;0',
            'weapon_sticker_5' => '0;0;0;0;0;0;0',
            'weapon_keychain' => '0;0;0;0;0',
        ], $overrides));
    }

    private function insertKnife(array $overrides = []): void
    {
        DB::connection('weaponskins')->table('wp_player_knife')->insert(array_merge([
            'steamid' => self::STEAM_ID64,
            'weapon_team' => 2,
            'knife' => 'weapon_karambit',
        ], $overrides));
    }

    private function insertGloves(array $overrides = []): void
    {
        DB::connection('weaponskins')->table('wp_player_gloves')->insert(array_merge([
            'steamid' => self::STEAM_ID64,
            'weapon_team' => 2,
            'weapon_defindex' => 5027,
        ], $overrides));
    }

    private function insertAgent(array $overrides = []): void
    {
        DB::connection('weaponskins')->table('wp_player_agents')->insert(array_merge([
            'steamid' => self::STEAM_ID64,
            'weapon_team' => 2,
            'agent_index' => 153,
        ], $overrides));
    }

    private function insertMusic(array $overrides = []): void
    {
        DB::connection('weaponskins')->table('wp_player_music')->insert(array_merge([
            'steamid' => self::STEAM_ID64,
            'weapon_team' => 3,
            'music_id' => 2,
        ], $overrides));
    }

    public function test_profile_requires_authentication(): void
    {
        $this->getJson('/api/skins/'.self::STEAM_ID64)->assertStatus(401);
    }

    public function test_profile_visible_to_any_authenticated_user(): void
    {
        $this->insertWeapon();

        $this->actingAs(User::factory()->create())
            ->getJson('/api/skins/'.self::STEAM_ID64)
            ->assertOk()
            ->assertJsonPath('data.steamid', self::STEAM_ID64)
            ->assertJsonCount(1, 'data.skins')
            ->assertJsonPath('data.skins.0.weapon_defindex', 7)
            ->assertJsonPath('data.skins.0.weapon_paint_id', 38);
    }

    public function test_profile_groups_every_slot(): void
    {
        $this->insertWeapon();
        $this->insertKnife();
        $this->insertGloves();
        $this->insertAgent();
        $this->insertMusic();

        $this->actingAs(User::factory()->create())
            ->getJson('/api/skins/'.self::STEAM_ID64)
            ->assertOk()
            ->assertJsonCount(1, 'data.skins')
            ->assertJsonCount(1, 'data.knife')
            ->assertJsonCount(1, 'data.gloves')
            ->assertJsonCount(1, 'data.agents')
            ->assertJsonCount(1, 'data.music')
            ->assertJsonPath('data.knife.0.knife', 'weapon_karambit')
            ->assertJsonPath('data.gloves.0.weapon_defindex', 5027)
            ->assertJsonPath('data.agents.0.agent_index', 153)
            ->assertJsonPath('data.music.0.music_id', 2);
    }

    public function test_profile_accepts_steamid2_and_steamid3(): void
    {
        $this->insertWeapon();

        $this->actingAs(User::factory()->create())
            ->getJson('/api/skins/'.self::STEAM_ID2)
            ->assertOk()
            ->assertJsonPath('data.steamid', self::STEAM_ID64);

        $this->actingAs(User::factory()->create())
            ->getJson('/api/skins/'.urlencode('[U:1:246912]'))
            ->assertOk()
            ->assertJsonPath('data.steamid', self::STEAM_ID64);
    }

    public function test_profile_rejects_invalid_steamid(): void
    {
        $this->actingAs(User::factory()->create())
            ->getJson('/api/skins/not-a-steamid')
            ->assertStatus(422)
            ->assertJsonPath('message', 'invalid_input');
    }

    public function test_profile_returns_empty_loadout_for_unknown_player(): void
    {
        $this->actingAs(User::factory()->create())
            ->getJson('/api/skins/76561197960512641')
            ->assertOk()
            ->assertJsonPath('data.steamid', '76561197960512641')
            ->assertJsonCount(0, 'data.skins');
    }

    public function test_catalog_returns_entries_when_file_exists(): void
    {
        $dir = storage_path('app/testing-catalog');
        File::ensureDirectoryExists($dir);
        File::put($dir.'/items.json', json_encode([
            ['defindex' => 7, 'name' => 'AK-47'],
            ['defindex' => 1, 'name' => 'Desert Eagle'],
        ]));
        config(['catalog.path' => $dir]);

        $this->actingAs(User::factory()->create())
            ->getJson('/api/skins/catalog/items')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.name', 'AK-47')
            ->assertJsonPath('meta.type', 'items');

        File::deleteDirectory($dir);
    }

    public function test_catalog_returns_empty_list_when_file_missing(): void
    {
        $dir = storage_path('app/testing-catalog-empty');
        File::ensureDirectoryExists($dir);
        config(['catalog.path' => $dir]);

        $this->actingAs(User::factory()->create())
            ->getJson('/api/skins/catalog/agents')
            ->assertOk()
            ->assertJsonCount(0, 'data');

        File::deleteDirectory($dir);
    }

    public function test_catalog_rejects_unknown_type(): void
    {
        $this->actingAs(User::factory()->create())
            ->getJson('/api/skins/catalog/bogus')
            ->assertStatus(422)
            ->assertJsonPath('message', 'invalid_input');
    }

    public function test_store_requires_admin_root_flag(): void
    {
        $this->actingAs(User::factory()->create())
            ->putJson('/api/skins/'.self::STEAM_ID64.'/weapon', [
                'team' => 2,
                'defindex' => 7,
                'weapon_paint_id' => 38,
            ])
            ->assertStatus(403);
    }

    public function test_store_owner_can_upsert_weapon(): void
    {
        $this->actingAs(User::factory()->owner()->create())
            ->putJson('/api/skins/'.self::STEAM_ID64.'/weapon', [
                'team' => 2,
                'defindex' => 7,
                'weapon_paint_id' => 38,
                'weapon_wear' => 0.25,
                'weapon_seed' => 42,
                'weapon_nametag' => 'TestStattrak',
                'weapon_stattrak' => true,
                'weapon_stattrak_count' => 1337,
            ])
            ->assertOk()
            ->assertJsonPath('data.weapon_defindex', 7)
            ->assertJsonPath('data.weapon_paint_id', 38)
            ->assertJsonPath('data.weapon_stattrak', true);

        $this->assertDatabaseHas('wp_player_skins', [
            'steamid' => self::STEAM_ID64,
            'weapon_team' => 2,
            'weapon_defindex' => 7,
            'weapon_nametag' => 'TestStattrak',
        ], 'weaponskins');
    }

    public function test_store_weapon_upsert_updates_existing_row(): void
    {
        $this->insertWeapon();

        $this->actingAs(User::factory()->owner()->create())
            ->putJson('/api/skins/'.self::STEAM_ID64.'/weapon', [
                'team' => 2,
                'defindex' => 7,
                'weapon_paint_id' => 999,
            ])
            ->assertOk()
            ->assertJsonPath('data.weapon_paint_id', 999);

        $this->assertSame(1, DB::connection('weaponskins')->table('wp_player_skins')
            ->where('steamid', self::STEAM_ID64)
            ->where('weapon_team', 2)
            ->where('weapon_defindex', 7)
            ->count());
    }

    public function test_store_upserts_knife_gloves_agent_music(): void
    {
        $owner = User::factory()->owner()->create();

        $this->actingAs($owner)
            ->putJson('/api/skins/'.self::STEAM_ID64.'/knife', [
                'team' => 2,
                'knife' => 'weapon_karambit',
            ])
            ->assertOk()
            ->assertJsonPath('data.knife', 'weapon_karambit');

        $this->actingAs($owner)
            ->putJson('/api/skins/'.self::STEAM_ID64.'/gloves', [
                'team' => 3,
                'weapon_defindex' => 5027,
            ])
            ->assertOk()
            ->assertJsonPath('data.weapon_defindex', 5027);

        $this->actingAs($owner)
            ->putJson('/api/skins/'.self::STEAM_ID64.'/agent', [
                'team' => 2,
                'agent_index' => 153,
            ])
            ->assertOk()
            ->assertJsonPath('data.agent_index', 153);

        $this->actingAs($owner)
            ->putJson('/api/skins/'.self::STEAM_ID64.'/music', [
                'team' => 3,
                'music_id' => 2,
            ])
            ->assertOk()
            ->assertJsonPath('data.music_id', 2);

        $this->assertDatabaseHas('wp_player_knife', ['steamid' => self::STEAM_ID64, 'weapon_team' => 2, 'knife' => 'weapon_karambit'], 'weaponskins');
        $this->assertDatabaseHas('wp_player_gloves', ['steamid' => self::STEAM_ID64, 'weapon_team' => 3, 'weapon_defindex' => 5027], 'weaponskins');
        $this->assertDatabaseHas('wp_player_agents', ['steamid' => self::STEAM_ID64, 'weapon_team' => 2, 'agent_index' => 153], 'weaponskins');
        $this->assertDatabaseHas('wp_player_music', ['steamid' => self::STEAM_ID64, 'weapon_team' => 3, 'music_id' => 2], 'weaponskins');
    }

    public function test_store_rejects_invalid_team(): void
    {
        $this->actingAs(User::factory()->owner()->create())
            ->putJson('/api/skins/'.self::STEAM_ID64.'/knife', [
                'team' => 4,
                'knife' => 'weapon_karambit',
            ])
            ->assertStatus(422)
            ->assertJsonPath('message', 'validation_failed');
    }

    public function test_store_rejects_invalid_slot(): void
    {
        $this->actingAs(User::factory()->owner()->create())
            ->putJson('/api/skins/'.self::STEAM_ID64.'/bogus', ['team' => 2])
            ->assertStatus(422)
            ->assertJsonPath('message', 'invalid_input')
            ->assertJsonPath('errors.slot.0', 'invalid_slot');
    }

    public function test_destroy_removes_weapon(): void
    {
        $this->insertWeapon();

        $this->actingAs(User::factory()->owner()->create())
            ->deleteJson('/api/skins/'.self::STEAM_ID64.'/weapon?team=2&defindex=7')
            ->assertOk()
            ->assertJsonPath('data.deleted', true);

        $this->assertDatabaseMissing('wp_player_skins', ['steamid' => self::STEAM_ID64, 'weapon_team' => 2, 'weapon_defindex' => 7], 'weaponskins');
    }

    public function test_destroy_removes_knife(): void
    {
        $this->insertKnife();

        $this->actingAs(User::factory()->owner()->create())
            ->deleteJson('/api/skins/'.self::STEAM_ID64.'/knife?team=2')
            ->assertOk()
            ->assertJsonPath('data.deleted', true);

        $this->assertDatabaseMissing('wp_player_knife', ['steamid' => self::STEAM_ID64, 'weapon_team' => 2], 'weaponskins');
    }

    public function test_destroy_returns_false_when_nothing_matched(): void
    {
        $this->actingAs(User::factory()->owner()->create())
            ->deleteJson('/api/skins/'.self::STEAM_ID64.'/music?team=2')
            ->assertOk()
            ->assertJsonPath('data.deleted', false);
    }

    public function test_mutations_write_audit_log(): void
    {
        $this->actingAs(User::factory()->owner()->create())
            ->putJson('/api/skins/'.self::STEAM_ID64.'/weapon', [
                'team' => 2,
                'defindex' => 7,
                'weapon_paint_id' => 38,
            ])
            ->assertOk();

        $this->assertDatabaseHas('panel_logs', [
            'action' => 'skin.weapon.upsert',
            'target_type' => 'skin_player',
            'target_id' => self::STEAM_ID64,
        ]);
    }
}