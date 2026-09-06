<?php

namespace App\Modules\Rank\App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * K4-LevelRanks-SwiftlyS2 lvl_base row (C5). The plugin stores the player
 * key as a STEAM_0:x:y string; the panel reads on the "ranks" connection and
 * never mutates the plugin schema beyond the one `value` column (Decision
 * 5 - point editing).
 */
class RankPlayer extends Model
{
    protected $connection = 'ranks';

    protected $table = 'lvl_base';

    protected $primaryKey = 'steam';

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;

    /**
     * Only lvl_base.value may be written by the panel (Decision 5 – point
     * editing); every other column belongs to the plugin.
     */
    protected $fillable = ['value'];

    protected function casts(): array
    {
        return [
            'value' => 'integer',
            'rank' => 'integer',
            'kills' => 'integer',
            'deaths' => 'integer',
            'shoots' => 'integer',
            'hits' => 'integer',
            'headshots' => 'integer',
            'assists' => 'integer',
            'round_win' => 'integer',
            'round_lose' => 'integer',
            'playtime' => 'integer',
            'lastconnect' => 'integer',
            'game_wins' => 'integer',
            'game_losses' => 'integer',
            'games_played' => 'integer',
            'rounds_played' => 'integer',
            'damage' => 'integer',
        ];
    }
}