<?php

namespace App\Modules\Skin\App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Swiftly CS2_Skin wp_player_knife row (C6). Unique (steamid, weapon_team).
 */
class SkinKnife extends Model
{
    protected $connection = 'weaponskins';

    protected $table = 'wp_player_knife';

    public $timestamps = false;

    protected $fillable = ['knife'];

    protected function casts(): array
    {
        return [
            'weapon_team' => 'integer',
        ];
    }
}