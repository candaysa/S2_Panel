<?php

namespace App\Modules\Health\App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Panel-owned notification center row (C16). Notifications are created
 * for the panel owner only (user_id -> users.id) when a health component
 * goes down; the owner marks them read from the notification center.
 */
class PanelNotification extends Model
{
    protected $table = 'notifications';

    protected $fillable = [
        'user_id',
        'type',
        'title',
        'body',
        'read_at',
    ];

    protected function casts(): array
    {
        return [
            'read_at' => 'datetime',
        ];
    }
}