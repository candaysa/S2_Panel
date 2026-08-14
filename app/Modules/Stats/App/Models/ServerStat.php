<?php

namespace App\Modules\Stats\App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One A2S sample for a server (C12). Panel-owned table – appended by the
 * stats:collect command; used for the server history graph.
 */
class ServerStat extends Model
{
    protected $table = 'server_stats';

    public $timestamps = false;

    protected $fillable = [
        'server_id',
        'players',
        'max_players',
        'map',
        'recorded_at',
    ];

    protected function casts(): array
    {
        return [
            'server_id' => 'integer',
            'players' => 'integer',
            'max_players' => 'integer',
            'recorded_at' => 'datetime',
        ];
    }
}