<?php

namespace App\Modules\Skin\App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Swiftly CS2_Skin wp_player_agents row (C6). Unique (steamid, weapon_team).
 */
class SkinAgent extends Model
{
    protected $connection = 'weaponskins';

    protected $table = 'wp_player_agents';

    public $timestamps = false;

    protected $fillable = ['agent_index'];

    protected function casts(): array
    {
        return [
            'weapon_team' => 'integer',
            'agent_index' => 'integer',
        ];
    }
}