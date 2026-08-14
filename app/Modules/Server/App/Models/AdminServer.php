<?php

namespace App\Modules\Server\App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Swiftly admin_servers row (C10). Read-only model on the "swiftly"
 * connection – the CS2_Admin plugin owns this table and upserts its own
 * rows at startup. server_id is the plugin's IP:PORT identity string;
 * live hostname/map/players come from A2S, never from this table.
 */
class AdminServer extends Model
{
    protected $connection = 'swiftly';

    protected $table = 'admin_servers';

    public $timestamps = false;

    protected $fillable = [
        'server_id',
        'server_ip',
        'server_port',
        'last_seen_at',
    ];

    protected function casts(): array
    {
        return [
            'server_port' => 'integer',
            'last_seen_at' => 'datetime',
        ];
    }
}