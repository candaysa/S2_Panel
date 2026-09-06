<?php

namespace App\Modules\Skin\App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Swiftly CS2_Skin wp_player_music row (C6). Unique (steamid, weapon_team);
 * note this table's steamid column is String(64) unlike the String(18) used
 * by the other wp_player_* tables.
 */
class SkinMusic extends Model
{
    protected $connection = 'weaponskins';

    protected $table = 'wp_player_music';

    public $timestamps = false;

    protected $fillable = ['music_id'];

    protected function casts(): array
    {
        return [
            'weapon_team' => 'integer',
            'music_id' => 'integer',
        ];
    }
}