<?php

namespace App\Modules\Skin\App\Services;

use App\Modules\Audit\App\Services\AuditService;
use App\Modules\Skin\App\Models\SkinAgent;
use App\Modules\Skin\App\Models\SkinGloves;
use App\Modules\Skin\App\Models\SkinKnife;
use App\Modules\Skin\App\Models\SkinMusic;
use App\Modules\Skin\App\Models\SkinWeapon;
use App\Support\SteamId;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Skin module (C6) – read/write the CS2_Skin wp_player_* tables.
 *
 * All keys are assigned explicitly (never mass-assigned) and every mutation
 * is audit-logged. Team must be 2 (CT) or 3 (T) – the plugin's two loadout
 * teams. Nothing here ever touches the panel's own schema.
 *
 * The plugin tables have NO integer primary key (composite key rows), so
 * writes go through the query builder's updateOrInsert() instead of
 * Eloquent save() – Eloquent would build WHERE id = null and silently
 * update zero rows on existing records.
 */
class SkinService
{
    public function __construct(private readonly AuditService $audit)
    {
    }

    private const TEAMS = [2, 3];

    public function teams(): array
    {
        return self::TEAMS;
    }

    /**
     * Normalize any SteamID format to the plugin's stored form (SteamID64).
     */
    private function steam64(string $steamid): string
    {
        if (! SteamId::isValid($steamid)) {
            throw new InvalidArgumentException('invalid_steamid');
        }

        return SteamId::parse($steamid)->steamId64();
    }

    private function assertTeam(int $team): void
    {
        if (! in_array($team, self::TEAMS, true)) {
            throw new InvalidArgumentException('invalid_team');
        }
    }

    /**
     * Full loadout for one player, grouped by slot.
     *
     * @return array{steamid: string, skins: Collection<int, SkinWeapon>, knife: Collection<int, SkinKnife>, gloves: Collection<int, SkinGloves>, agents: Collection<int, SkinAgent>, music: Collection<int, SkinMusic>}
     */
    public function profile(string $steamid): array
    {
        $steam64 = $this->steam64($steamid);

        return [
            'steamid' => $steam64,
            'skins' => SkinWeapon::query()->where('steamid', $steam64)->orderBy('weapon_defindex')->get(),
            'knife' => SkinKnife::query()->where('steamid', $steam64)->orderBy('weapon_team')->get(),
            'gloves' => SkinGloves::query()->where('steamid', $steam64)->orderBy('weapon_team')->get(),
            'agents' => SkinAgent::query()->where('steamid', $steam64)->orderBy('weapon_team')->get(),
            'music' => SkinMusic::query()->where('steamid', $steam64)->orderBy('weapon_team')->get(),
        ];
    }

    /**
     * Upsert one weapon skin (unique: steamid + team + defindex).
     *
     * @param  array<string, mixed>  $data
     */
    public function setWeapon(string $steamid, int $team, int $defindex, array $data): SkinWeapon
    {
        $steam64 = $this->steam64($steamid);
        $this->assertTeam($team);

        DB::connection('weaponskins')->table('wp_player_skins')->updateOrInsert(
            ['steamid' => $steam64, 'weapon_team' => $team, 'weapon_defindex' => $defindex],
            $this->weaponFields($data),
        );

        $this->audit->log('skin.weapon.upsert', 'skin_player', $steam64, [
            'team' => $team,
            'defindex' => $defindex,
            'paint_id' => $data['weapon_paint_id'] ?? 0,
        ]);

        // No integer primary key on this table (composite key) – Eloquent's
        // refresh() would resolve findOrFail(null) and throw a 404. Re-query.
        return SkinWeapon::query()->where('steamid', $steam64)
            ->where('weapon_team', $team)
            ->where('weapon_defindex', $defindex)
            ->firstOrFail();
    }

    public function removeWeapon(string $steamid, int $team, int $defindex): bool
    {
        $steam64 = $this->steam64($steamid);
        $this->assertTeam($team);

        $deleted = SkinWeapon::query()->where('steamid', $steam64)
            ->where('weapon_team', $team)
            ->where('weapon_defindex', $defindex)
            ->delete();

        if ($deleted > 0) {
            $this->audit->log('skin.weapon.deleted', 'skin_player', $steam64, [
                'team' => $team,
                'defindex' => $defindex,
            ]);
        }

        return $deleted > 0;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function setKnife(string $steamid, int $team, array $data): SkinKnife
    {
        $steam64 = $this->steam64($steamid);
        $this->assertTeam($team);

        DB::connection('weaponskins')->table('wp_player_knife')->updateOrInsert(
            ['steamid' => $steam64, 'weapon_team' => $team],
            ['knife' => (string) ($data['knife'] ?? '')],
        );

        $this->audit->log('skin.knife.upsert', 'skin_player', $steam64, [
            'team' => $team,
            'knife' => (string) ($data['knife'] ?? ''),
        ]);

        return SkinKnife::query()->where('steamid', $steam64)
            ->where('weapon_team', $team)
            ->firstOrFail();
    }

    public function removeKnife(string $steamid, int $team): bool
    {
        $steam64 = $this->steam64($steamid);
        $this->assertTeam($team);

        $deleted = SkinKnife::query()->where('steamid', $steam64)
            ->where('weapon_team', $team)
            ->delete();

        if ($deleted > 0) {
            $this->audit->log('skin.knife.deleted', 'skin_player', $steam64, ['team' => $team]);
        }

        return $deleted > 0;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function setGloves(string $steamid, int $team, array $data): SkinGloves
    {
        $steam64 = $this->steam64($steamid);
        $this->assertTeam($team);

        DB::connection('weaponskins')->table('wp_player_gloves')->updateOrInsert(
            ['steamid' => $steam64, 'weapon_team' => $team],
            ['weapon_defindex' => (int) ($data['weapon_defindex'] ?? 0)],
        );

        $this->audit->log('skin.gloves.upsert', 'skin_player', $steam64, [
            'team' => $team,
            'defindex' => (int) ($data['weapon_defindex'] ?? 0),
        ]);

        return SkinGloves::query()->where('steamid', $steam64)
            ->where('weapon_team', $team)
            ->firstOrFail();
    }

    public function removeGloves(string $steamid, int $team): bool
    {
        $steam64 = $this->steam64($steamid);
        $this->assertTeam($team);

        $deleted = SkinGloves::query()->where('steamid', $steam64)
            ->where('weapon_team', $team)
            ->delete();

        if ($deleted > 0) {
            $this->audit->log('skin.gloves.deleted', 'skin_player', $steam64, ['team' => $team]);
        }

        return $deleted > 0;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function setAgent(string $steamid, int $team, array $data): SkinAgent
    {
        $steam64 = $this->steam64($steamid);
        $this->assertTeam($team);

        DB::connection('weaponskins')->table('wp_player_agents')->updateOrInsert(
            ['steamid' => $steam64, 'weapon_team' => $team],
            ['agent_index' => (int) ($data['agent_index'] ?? 0)],
        );

        $this->audit->log('skin.agent.upsert', 'skin_player', $steam64, [
            'team' => $team,
            'agent_index' => (int) ($data['agent_index'] ?? 0),
        ]);

        return SkinAgent::query()->where('steamid', $steam64)
            ->where('weapon_team', $team)
            ->firstOrFail();
    }

    public function removeAgent(string $steamid, int $team): bool
    {
        $steam64 = $this->steam64($steamid);
        $this->assertTeam($team);

        $deleted = SkinAgent::query()->where('steamid', $steam64)
            ->where('weapon_team', $team)
            ->delete();

        if ($deleted > 0) {
            $this->audit->log('skin.agent.deleted', 'skin_player', $steam64, ['team' => $team]);
        }

        return $deleted > 0;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function setMusic(string $steamid, int $team, array $data): SkinMusic
    {
        $steam64 = $this->steam64($steamid);
        $this->assertTeam($team);

        DB::connection('weaponskins')->table('wp_player_music')->updateOrInsert(
            ['steamid' => $steam64, 'weapon_team' => $team],
            ['music_id' => (int) ($data['music_id'] ?? 0)],
        );

        $this->audit->log('skin.music.upsert', 'skin_player', $steam64, [
            'team' => $team,
            'music_id' => (int) ($data['music_id'] ?? 0),
        ]);

        return SkinMusic::query()->where('steamid', $steam64)
            ->where('weapon_team', $team)
            ->firstOrFail();
    }

    public function removeMusic(string $steamid, int $team): bool
    {
        $steam64 = $this->steam64($steamid);
        $this->assertTeam($team);

        $deleted = SkinMusic::query()->where('steamid', $steam64)
            ->where('weapon_team', $team)
            ->delete();

        if ($deleted > 0) {
            $this->audit->log('skin.music.deleted', 'skin_player', $steam64, ['team' => $team]);
        }

        return $deleted > 0;
    }

    /**
     * Whitelisted weapon skin columns (exact schema names from the plugin).
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function weaponFields(array $data): array
    {
        $defaults = [
            'weapon_paint_id' => 0,
            'weapon_wear' => 0.000001,
            'weapon_seed' => 0,
            'weapon_nametag' => null,
            'weapon_stattrak' => false,
            'weapon_stattrak_count' => 0,
            'weapon_sticker_0' => '0;0;0;0;0;0;0',
            'weapon_sticker_1' => '0;0;0;0;0;0;0',
            'weapon_sticker_2' => '0;0;0;0;0;0;0',
            'weapon_sticker_3' => '0;0;0;0;0;0;0',
            'weapon_sticker_4' => '0;0;0;0;0;0;0',
            'weapon_sticker_5' => '0;0;0;0;0;0;0',
            'weapon_keychain' => '0;0;0;0;0',
        ];

        foreach (array_keys($defaults) as $field) {
            if (array_key_exists($field, $data)) {
                $defaults[$field] = $data[$field];
            }
        }

        return $defaults;
    }
}