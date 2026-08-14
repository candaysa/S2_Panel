<?php

namespace App\Modules\Rank\App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Swiftly CS2_Ranks rank_hits row (C5) – per-bodypart damage stats. Column
 * names come straight from the plugin migration (pascal case) and are kept
 * verbatim.
 */
class RankHit extends Model
{
    protected $connection = 'ranks';

    protected $table = 'rank_hits';

    protected $primaryKey = 'SteamID';

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'DmgHealth' => 'integer',
            'DmgArmor' => 'integer',
            'Head' => 'integer',
            'Chest' => 'integer',
            'Belly' => 'integer',
            'LeftArm' => 'integer',
            'RightArm' => 'integer',
            'LeftLeg' => 'integer',
            'RightLeg' => 'integer',
            'Neak' => 'integer',
        ];
    }
}