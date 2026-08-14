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
            'actor_steamid' => 'integer',
            'details' => 'array',
            'created_at' => 'datetime',
        ];
    }
}