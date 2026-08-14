<?php

namespace App\Modules\Rcon\App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * RCON credentials for one game server (C11). Panel-owned table – the
 * password is encrypted at rest with the application key and is NEVER
 * written to the Swiftly admin_servers table. server_id maps to
 * AdminServer::id (no FK – the plugin owns that table).
 */
class RconSetting extends Model
{
    protected $table = 'rcon_settings';

    protected $fillable = [
        'server_id',
        'password',
    ];

    protected function casts(): array
    {
        return [
            'server_id' => 'integer',
            'password' => 'encrypted',
        ];
    }
}