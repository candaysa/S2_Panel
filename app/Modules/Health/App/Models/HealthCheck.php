<?php

namespace App\Modules\Health\App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One health probe outcome (C16). Each row records a component's state at
 * a point in time; the newest row per component is its current status.
 */
class HealthCheck extends Model
{
    protected $table = 'health_checks';

    protected $fillable = [
        'component',
        'status',
        'message',
        'checked_at',
    ];

    protected function casts(): array
    {
        return [
            'checked_at' => 'datetime',
        ];
    }
}