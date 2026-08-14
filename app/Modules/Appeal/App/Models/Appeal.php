<?php

namespace App\Modules\Appeal\App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Ban appeal (C9). Panel-owned table.
 *
 * status: PENDING | APPROVED | REJECTED
 * decided_at/decided_by are null until an admin decides the appeal.
 */
class Appeal extends Model
{
    protected $table = 'appeals';

    protected $fillable = [
        'steamid',
        'name',
        'ban_id',
        'reason',
        'status',
        'decided_by',
        'decision_note',
        'decided_at',
    ];

    protected function casts(): array
    {
        return [
            'steamid' => 'integer',
            'ban_id' => 'integer',
            'decided_by' => 'integer',
            'decided_at' => 'datetime',
        ];
    }
}