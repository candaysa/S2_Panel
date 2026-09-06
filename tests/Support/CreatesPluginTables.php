<?php

namespace Tests\Support;

use Illuminate\Support\Facades\Schema;

/**
 * Creates the Swiftly plugin tables inside tests (sqlite :memory:).
 *
 * The real panel never migrates, alters or drops plugin tables – these
 * helpers exist only so feature tests can exercise the factories and
 * endpoints against the exact schema the Swiftly plugin owns.
 */
trait CreatesPluginTables
{
    /**
     * Minimal schema mirroring the Swiftly admin plugin tables used by the
     * panel (only the columns our queries touch).
     */
    protected function createSwiftlyCoreTables(): void
    {
        Schema::connection('swiftly')->create('admin_admins', function ($table): void {
            $table->increments('id');
            $table->bigInteger('steamid');
            $table->string('name', 64)->nullable();
            $table->text('flags')->nullable();
            $table->text('groups')->nullable();
            $table->integer('immunity')->default(0);
            $table->timestamp('created_at')->nullable();
            $table->timestamp('expires_at')->nullable();
        });

        Schema::connection('swiftly')->create('admin_groups', function ($table): void {
            $table->increments('id');
            $table->string('name', 64);
            $table->text('flags')->nullable();
            $table->integer('immunity')->default(0);
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
        });

        Schema::connection('swiftly')->create('admin_servers', function ($table): void {
            $table->increments('id');
            $table->string('server_id', 64);
            $table->string('server_ip', 45);
            $table->integer('server_port');
            $table->timestamp('last_seen_at')->nullable();
        });
    }

    /**
     * Swiftly ban/mute/gag/warn tables (subset used by the Ban module).
     */
    protected function createSwiftlyPunishmentTables(): void
    {
        foreach (['admin_bans', 'admin_mutes', 'admin_gags'] as $tableName) {
            Schema::connection('swiftly')->create($tableName, function ($table): void {
                $table->increments('id');
                $table->bigInteger('steamid')->nullable();
                $table->string('target_name', 64)->nullable();
                $table->string('target_type', 16)->default('steamid');
                $table->string('ip_address', 45)->nullable();
                $table->string('admin_name', 64)->nullable();
                $table->bigInteger('admin_steamid')->nullable();
                $table->text('reason')->nullable();
                $table->boolean('is_global')->default(false);
                $table->integer('server_id')->nullable();
                $table->string('server_ip', 45)->nullable();
                $table->integer('server_port')->nullable();
                $table->timestamp('created_at')->nullable();
                $table->timestamp('expires_at')->nullable();
                $table->string('status', 16)->default('active');
                $table->string('unban_admin_name', 64)->nullable();
                $table->bigInteger('unban_admin_steamid')->nullable();
                $table->text('unban_reason')->nullable();
                $table->timestamp('unban_date')->nullable();
            });
        }

        Schema::connection('swiftly')->create('admin_warns', function ($table): void {
            $table->increments('id');
            $table->bigInteger('steamid');
            $table->string('target_name', 64)->nullable();
            $table->string('admin_name', 64)->nullable();
            $table->bigInteger('admin_steamid')->nullable();
            $table->text('reason')->nullable();
            $table->integer('server_id')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('expires_at')->nullable();
        });
    }

    /**
     * K4-LevelRanks-SwiftlyS2 tables (subset used by the Rank module) - see
     * M001_CoreTables/M002_WeaponStatsTable/M003_HitStatsTable in that
     * plugin's own source for the schema this mirrors.
     */
    protected function createRankTables(): void
    {
        Schema::connection('ranks')->create('lvl_base', function ($table): void {
            $table->string('steam', 32)->primary();
            $table->string('name', 64)->default('');
            $table->integer('value')->default(0);
            $table->integer('rank')->default(0);
            $table->integer('kills')->default(0);
            $table->integer('deaths')->default(0);
            $table->bigInteger('shoots')->default(0);
            $table->bigInteger('hits')->default(0);
            $table->integer('headshots')->default(0);
            $table->integer('assists')->default(0);
            $table->integer('round_win')->default(0);
            $table->integer('round_lose')->default(0);
            $table->bigInteger('playtime')->default(0);
            $table->integer('lastconnect')->default(0);
            $table->integer('game_wins')->default(0);
            $table->integer('game_losses')->default(0);
            $table->integer('games_played')->default(0);
            $table->integer('rounds_played')->default(0);
            $table->bigInteger('damage')->default(0);
        });

        Schema::connection('ranks')->create('lvl_base_hits', function ($table): void {
            $table->string('SteamID', 32)->primary();
            $table->bigInteger('DmgHealth')->default(0);
            $table->bigInteger('DmgArmor')->default(0);
            $table->integer('Head')->default(0);
            $table->integer('Chest')->default(0);
            $table->integer('Belly')->default(0);
            $table->integer('LeftArm')->default(0);
            $table->integer('RightArm')->default(0);
            $table->integer('LeftLeg')->default(0);
            $table->integer('RightLeg')->default(0);
            $table->integer('Neak')->default(0);
        });

        // Optional module server-side (WeaponStatsEnabled in modules.json) -
        // created here so tests can cover both "present" and "table simply
        // doesn't exist" (RankService::weaponsFor()'s try/catch) by dropping
        // it again in the specific test that needs that.
        Schema::connection('ranks')->create('lvl_base_weapons', function ($table): void {
            $table->string('steam', 32);
            $table->string('classname', 64);
            $table->integer('kills')->default(0);
            $table->integer('deaths')->default(0);
            $table->integer('headshots')->default(0);
            $table->bigInteger('hits')->default(0);
            $table->bigInteger('shots')->default(0);
            $table->bigInteger('damage')->default(0);
            $table->primary(['steam', 'classname']);
        });

        Schema::connection('ranks')->create('lvl_base_settings', function ($table): void {
            $table->string('steam', 32)->primary();
            $table->boolean('messages')->default(true);
            $table->boolean('summary')->default(false);
            $table->boolean('rankchanges')->default(true);
        });
    }

    /**
     * Swiftly CS2_Skin tables (subset used by the Skin module).
     */
    protected function createSkinTables(): void
    {
        Schema::connection('weaponskins')->create('wp_player_skins', function ($table): void {
            $table->string('steamid', 18);
            $table->smallInteger('weapon_team');
            $table->integer('weapon_defindex');
            $table->integer('weapon_paint_id')->default(0);
            $table->float('weapon_wear')->default(0.000001);
            $table->integer('weapon_seed')->default(0);
            $table->string('weapon_nametag', 128)->nullable();
            $table->boolean('weapon_stattrak')->default(false);
            $table->integer('weapon_stattrak_count')->default(0);
            $table->string('weapon_sticker_0', 128)->default('0;0;0;0;0;0;0');
            $table->string('weapon_sticker_1', 128)->default('0;0;0;0;0;0;0');
            $table->string('weapon_sticker_2', 128)->default('0;0;0;0;0;0;0');
            $table->string('weapon_sticker_3', 128)->default('0;0;0;0;0;0;0');
            $table->string('weapon_sticker_4', 128)->default('0;0;0;0;0;0;0');
            $table->string('weapon_sticker_5', 128)->default('0;0;0;0;0;0;0');
            $table->string('weapon_keychain', 128)->default('0;0;0;0;0');
            $table->unique(['steamid', 'weapon_team', 'weapon_defindex'], 'wp_player_skins_steamid_weapon_team_weapon_defindex_unique');
        });

        Schema::connection('weaponskins')->create('wp_player_knife', function ($table): void {
            $table->string('steamid', 18);
            $table->smallInteger('weapon_team');
            $table->string('knife', 64);
            $table->unique(['steamid', 'weapon_team'], 'wp_player_knife_steamid_weapon_team_unique');
        });

        Schema::connection('weaponskins')->create('wp_player_gloves', function ($table): void {
            $table->string('steamid', 18);
            $table->smallInteger('weapon_team');
            $table->integer('weapon_defindex');
            $table->unique(['steamid', 'weapon_team'], 'wp_player_gloves_steamid_weapon_team_unique');
        });

        Schema::connection('weaponskins')->create('wp_player_agents', function ($table): void {
            $table->string('steamid', 18);
            $table->smallInteger('weapon_team');
            $table->integer('agent_index');
            $table->unique(['steamid', 'weapon_team'], 'wp_player_agents_steamid_weapon_team_unique');
        });

        Schema::connection('weaponskins')->create('wp_player_music', function ($table): void {
            $table->string('steamid', 64);
            $table->integer('weapon_team');
            $table->integer('music_id');
            $table->unique(['steamid', 'weapon_team'], 'UQ_wp_player_music_steamid_team');
        });
    }

    /**
     * VIPCore plugin tables (https://github.com/SwiftlyS2-Plugins/VIPCore),
     * mirroring VIPCore/src/Database/Migrations/Migration001-003.cs.
     */
    protected function createVipTables(): void
    {
        Schema::connection('vip')->create('vip_users', function ($table): void {
            $table->bigInteger('account_id');
            $table->string('name', 64);
            $table->bigInteger('lastvisit');
            $table->bigInteger('sid');
            $table->string('group', 64);
            $table->bigInteger('expires');
            $table->primary(['account_id', 'sid', 'group']);
        });

        Schema::connection('vip')->create('vip_servers', function ($table): void {
            $table->id('serverId');
            $table->string('serverIp', 45);
            $table->integer('port');
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->string('GUID', 36)->nullable()->unique();
        });
    }

    /**
     * Swiftly admin action log table.
     */
    protected function createSwiftlyLogTables(): void
    {
        Schema::connection('swiftly')->create('admin_log', function ($table): void {
            $table->increments('id');
            $table->string('admin_name', 64)->nullable();
            $table->bigInteger('admin_steamid')->nullable();
            $table->string('action', 64);
            $table->bigInteger('target_steamid')->nullable();
            $table->string('target_ip', 45)->nullable();
            $table->text('details')->nullable();
            $table->integer('server_id')->nullable();
            $table->string('server_ip', 45)->nullable();
            $table->integer('server_port')->nullable();
            $table->timestamp('created_at')->nullable();
        });
    }
}