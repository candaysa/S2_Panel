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
            // SteamID64 exceeds JavaScript's safe integer range, so a
            // JSON number silently loses its last digit in the browser
            // and every profile link built from it points at a
            // different account. It is an identifier, never an
            // arithmetic value - keep it a string on the wire.
            'steamid' => 'string',
            'ban_id' => 'integer',
            'decided_by' => 'integer',
            'decided_at' => 'datetime',
        ];
    }
}