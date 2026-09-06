<?php

namespace App\Modules\Skin\App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Swiftly CS2_Skin wp_player_skins row (C6). One row per weapon/team slot;
 * unique (steamid, weapon_team, weapon_defindex). steamid is stored as a
 * SteamID64 string by the plugin.
 */
class SkinWeapon extends Model
{
    protected $connection = 'weaponskins';

    protected $table = 'wp_player_skins';

    public $timestamps = false;

    /**
     * Only the columns a panel may safely touch when upserting a weapon
     * skin (the row key steamid/team/defindex is supplied by the caller and
     * never mass-assigned from the request).
     */
    protected $fillable = [
        'weapon_paint_id',
        'weapon_wear',
        'weapon_seed',
        'weapon_nametag',
        'weapon_stattrak',
        'weapon_stattrak_count',
        'weapon_sticker_0',
        'weapon_sticker_1',
        'weapon_sticker_2',
        'weapon_sticker_3',
        'weapon_sticker_4',
        'weapon_sticker_5',
        'weapon_keychain',
    ];

    protected function casts(): array
    {
        return [
            'weapon_team' => 'integer',
            'weapon_defindex' => 'integer',
            'weapon_paint_id' => 'integer',
            'weapon_wear' => 'float',
            'weapon_seed' => 'integer',
            'weapon_stattrak' => 'boolean',
            'weapon_stattrak_count' => 'integer',
        ];
    }
}