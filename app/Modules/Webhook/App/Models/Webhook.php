<?php

namespace App\Modules\Webhook\App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One Discord webhook configuration (C17). Panel-owned table.
 *
 * url is encrypted at rest (Discord token lives inside the URL) and the
 * API never returns it. events holds the selected event type strings.
 */
class Webhook extends Model
{
    protected $table = 'webhooks';

    protected $fillable = [
        'name',
        'url',
        'events',
        'enabled',
    ];

    protected function casts(): array
    {
        return [
            'url' => 'encrypted',
            'events' => 'array',
            'enabled' => 'boolean',
        ];
    }

    public function hasEvent(string $event): bool
    {
        return in_array($event, $this->events ?? [], true);
    }
}