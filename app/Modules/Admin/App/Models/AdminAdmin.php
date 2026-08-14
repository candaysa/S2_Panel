<?php

namespace App\Modules\Admin\App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Swiftly plugin admin row (C3). Lives on the "swiftly" connection – the
 * panel NEVER migrates, alters or drops this table; it only reads and
 * writes rows. `expires_at` in the past means the admin is disabled, which
 * matches the Swiftly permission lookup and the panel's non-destructive
 * rule (admins are disabled, never deleted).
 */
class AdminAdmin extends Model
{
    protected $connection = 'swiftly';

    protected $table = 'admin_admins';

    public $timestamps = false;

    protected $fillable = [
        'steamid',
        'name',
        'flags',
        'groups',
        'immunity',
        'expires_at',
    ];

    protected function casts(): array
    {
        return [
            'steamid' => 'integer',
            'immunity' => 'integer',
            'expires_at' => 'datetime',
        ];
    }
}