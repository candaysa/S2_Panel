<?php

namespace App\Modules\Skin\App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Swiftly CS2_Skin wp_player_gloves row (C6). Unique (steamid, weapon_team).
 */
class SkinGloves extends Model
{
    protected $connection = 'weaponskins';

    protected $table = 'wp_player_gloves';

    public $timestamps = false;

    protected $fillable = ['weapon_defindex'];

    protected function casts(): array
    {
        return [
            'weapon_team' => 'integer',
            'weapon_defindex' => 'integer',
        ];
    }
}