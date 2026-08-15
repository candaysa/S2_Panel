<?php

namespace App\Modules\Audit\App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Audit log row (C7). Panel-owned table; written by AuditService on every
 * sensitive mutation so module activity stays traceable even after flags
 * or privileges change.
 */
class PanelLog extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'actor_steamid',
        'actor_name',
        'action',
        'target_type',
        'target_id',
        'details',
        'ip_address',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            // SteamID64 exceeds JavaScript's safe integer range, so a
            // JSON number silently loses its last digit in the browser
            // and every profile link built from it points at a
            // different account. It is an identifier, never an
            // arithmetic value - keep it a string on the wire.
            'actor_steamid' => 'string',
            'details' => 'array',
            'created_at' => 'datetime',
        ];
    }
}