<?php

namespace App\Modules\Audit\App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One in-game admin action, as recorded by the admin plugin (admin_log on
 * the "swiftly" connection).
 *
 * Read-only and entirely separate from PanelLog, which is this panel's own
 * audit trail. The two answer different questions and must not be merged:
 * PanelLog says what someone did *in the panel*, admin_log says what an
 * admin did *on a server* - a slay, a kick, a map change - and the panel is
 * only ever a reader of it.
 */
class AdminLog extends Model
{
    protected $connection = 'swiftly';

    protected $table = 'admin_log';

    public $timestamps = false;

    protected function casts(): array
    {
        return [
            // SteamID64 exceeds JavaScript's safe integer range, so a JSON
            // number silently loses its last digit in the browser and every
            // profile link built from it points at a different account.
            // Identifiers, never arithmetic - keep them strings on the wire.
            'admin_steamid' => 'string',
            'target_steamid' => 'string',
            'created_at' => 'datetime',
        ];
    }
}
