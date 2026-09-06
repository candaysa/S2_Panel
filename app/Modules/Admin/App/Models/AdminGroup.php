<?php

namespace App\Modules\Admin\App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Swiftly plugin admin group (C3). Read-only reference for the panel: the
 * admin UI lists groups so an owner can pick one for a new/updated admin.
 */
class AdminGroup extends Model
{
    protected $connection = 'swiftly';

    protected $table = 'admin_groups';

    public $timestamps = false;

    protected $fillable = [
        'name',
        'flags',
        'immunity',
    ];

    protected function casts(): array
    {
        return [
            'immunity' => 'integer',
        ];
    }
}