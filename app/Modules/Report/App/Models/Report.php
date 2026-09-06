<?php

namespace App\Modules\Report\App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Player report / admin application ticket (C8). Panel-owned table.
 *
 * ticket_type: report | admin_application
 * status:      open | closed
 * resolution:  APPROVED | REJECTED (nullable until closed)
 */
class Report extends Model
{
    protected $table = 'reports';

    protected $fillable = [
        'ticket_type',
        'status',
        'resolution',
        'reporter_steamid',
        'reporter_name',
        'target_steamid',
        'target_name',
        'report_reason',
        'server_id',
    ];

    protected function casts(): array
    {
        return [
            // SteamID64 exceeds JavaScript's safe integer range, so a
            // JSON number silently loses its last digit in the browser
            // and every profile link built from it points at a
            // different account. It is an identifier, never an
            // arithmetic value - keep it a string on the wire.
            'reporter_steamid' => 'string',
            'target_steamid' => 'string',
            'server_id' => 'integer',
        ];
    }

    /**
     * @return HasMany<ReportReply, $this>
     */
    public function replies(): HasMany
    {
        return $this->hasMany(ReportReply::class, 'report_id');
    }
}