<?php

namespace App\Modules\Rank\App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * K4-LevelRanks-SwiftlyS2 lvl_base_hits row (C5) – per-bodypart damage
 * stats. Column names come straight from the plugin migration (pascal case,
 * "Neak" typo included) and are kept verbatim.
 */
class RankHit extends Model
{
    protected $connection = 'ranks';

    protected $table = 'lvl_base_hits';

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