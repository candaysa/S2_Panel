<?php

namespace App\Modules\Vip\App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * VIPCore's vip_servers row. READ-ONLY on the panel side: VIPCore owns
 * serverId/GUID assignment through its own runtime logic (see
 * UserRepository.TryMoveServerIdAsync / ClearGuidFromOtherServersAsync in
 * the plugin) - the panel only needs this table to resolve a serverId to
 * an IP:port for display, never to create or renumber servers.
 */
class VipServer extends Model
{
    protected $connection = 'vip';

    protected $table = 'vip_servers';

    protected $primaryKey = 'serverId';

    public $timestamps = false;

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'serverId' => 'integer',
            'port' => 'integer',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }
}
