<?php

namespace App\Modules\Webhook\App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One delivery attempt of a webhook (C17). Panel-owned table.
 *
 * status: "sent" | "failed" – every queue attempt appends a row, so the
 * table doubles as the retry trail required by the spec.
 */
class WebhookDelivery extends Model
{
    protected $table = 'webhook_deliveries';

    protected $fillable = [
        'webhook_id',
        'event',
        'status',
        'response_status',
        'error',
        'attempted_at',
    ];

    protected function casts(): array
    {
        return [
            'response_status' => 'integer',
            'attempted_at' => 'datetime',
        ];
    }
}