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
            'reporter_steamid' => 'integer',
            'target_steamid' => 'integer',
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