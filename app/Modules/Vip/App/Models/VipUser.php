<?php

namespace App\Modules\Vip\App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * VIPCore's vip_users row (https://github.com/SwiftlyS2-Plugins/VIPCore).
 *
 * Schema (VIPCore/src/Database/Migrations/Migration001.cs):
 *   account_id BIGINT  - player's SteamID32 (AccountID, not SteamID64)
 *   name       VARCHAR - last known player name
 *   lastvisit  BIGINT  - unix timestamp of the last VIP session
 *   sid        BIGINT  - server id (vip_servers.serverId)
 *   group      VARCHAR - VIP group name; group DEFINITIONS live in
 *                        VIPCore's own server-side config, not in the
 *                        database, so the panel treats it as free text
 *   expires    BIGINT  - unix timestamp; 0 = never expires
 *
 * PRIMARY KEY (account_id, sid, group) - one player can hold several
 * groups, and the same group on several servers, at once. Eloquent has no
 * native composite-key support, so every read/write in this codebase goes
 * through the query builder (VipUser::query()->where([...])) rather than
 * instance-level save()/update()/delete() - those rely on a single
 * getKeyName()/getKey() pair and would silently target the wrong row(s).
 */
class VipUser extends Model
{
    protected $connection = 'vip';

    protected $table = 'vip_users';

    public $incrementing = false;

    public $timestamps = false;

    protected $fillable = [
        'account_id',
        'name',
        'lastvisit',
        'sid',
        'group',
        'expires',
    ];

    protected function casts(): array
    {
        return [
            'account_id' => 'integer',
            'lastvisit' => 'integer',
            'sid' => 'integer',
            'expires' => 'integer',
        ];
    }
}
