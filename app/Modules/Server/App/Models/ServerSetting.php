<?php

namespace App\Modules\Server\App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Panel-owned presentation settings for a server row in the plugin's
 * admin_servers table. Lives on the panel connection, never the plugin's.
 */
class ServerSetting extends Model
{
    protected $table = 'server_settings';

    protected $fillable = ['server_id', 'hidden', 'display_name', 'sort_order'];

    protected function casts(): array
    {
        return [
            'server_id' => 'integer',
            'hidden' => 'boolean',
            'sort_order' => 'integer',
        ];
    }
}
