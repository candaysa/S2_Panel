<?php

namespace App\Modules\Rank\App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * K4-LevelRanks-SwiftlyS2 lvl_base_weapons row (C5) - per-weapon breakdown,
 * new in this plugin (the old Swiftly CS2_Ranks schema had no equivalent).
 * Optional server-side (the plugin's own WeaponStats module can be switched
 * off, in which case this table simply never exists - see RankService and
 * DependencyProbe for how that's handled as a missing-but-not-required
 * integration, not an error).
 *
 * Composite key (steam, classname), same reasoning SkinWeapon documents for
 * its own composite-key table: no single $primaryKey, and this model is
 * read-only here (the panel never writes weapon stats), so Eloquent never
 * needs one to resolve a single row for save().
 */
class RankWeapon extends Model
{
    protected $connection = 'ranks';

    protected $table = 'lvl_base_weapons';

    public $incrementing = false;

    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'kills' => 'integer',
            'deaths' => 'integer',
            'headshots' => 'integer',
            'hits' => 'integer',
            'shots' => 'integer',
            'damage' => 'integer',
        ];
    }
}
