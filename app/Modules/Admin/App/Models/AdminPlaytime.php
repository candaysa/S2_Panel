<?php

namespace App\Modules\Admin\App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Swiftly admin_playtime row (C3). A separate plugin table, not part of
 * admin_admins - some installs run it, some don't, so every read has to
 * tolerate the table being absent (see AdminService::playtimeMinutesFor()).
 */
class AdminPlaytime extends Model
{
    protected $connection = 'swiftly';

    protected $table = 'admin_playtime';

    protected $fillable = ['steamid', 'player_name', 'playtime_minutes'];

    protected function casts(): array
    {
        return [
            'steamid' => 'string',
            'playtime_minutes' => 'integer',
        ];
    }
}
