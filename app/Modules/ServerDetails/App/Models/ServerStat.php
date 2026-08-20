<?php

namespace App\Modules\ServerDetails\App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One population sample for a server, taken every 5 minutes (see
 * SampleServerStats). Panel-owned - never written to by the game plugin.
 */
class ServerStat extends Model
{
    public $timestamps = false;

    protected $table = 'server_stats';

    protected $fillable = ['server_id', 'players', 'max_players', 'map', 'sampled_at'];

    protected function casts(): array
    {
        return [
            'server_id' => 'integer',
            'players' => 'integer',
            'max_players' => 'integer',
            'sampled_at' => 'datetime',
        ];
    }
}
