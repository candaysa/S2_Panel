<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Discord webhooks (C17)
    |--------------------------------------------------------------------------
    |
    | The webhook module posts Discord embeds to user-configured webhook
    | URLs. Every configured webhook selects which event types it wants;
    | delivery runs through the queue (async) and every attempt is logged
    | into webhook_deliveries (sent/failed) for a retry trail.
    |
    */

    'queue' => env('WEBHOOK_QUEUE', 'default'),

    'timeout' => env('WEBHOOK_TIMEOUT', 5),

    'embed' => [
        'username' => env('WEBHOOK_USERNAME', 'S2 Panel'),
    ],

    /*
    | Per-event embed defaults. Listeners may override title/description;
    | the color always comes from here.
    */
    'events' => [
        'admin.action' => [
            'title' => 'Admin action',
            'color' => 0xE67E22,
        ],
        'user.registered' => [
            'title' => 'New user registered',
            'color' => 0x3498DB,
        ],
        'report.created' => [
            'title' => 'New report',
            'color' => 0xF1C40F,
        ],
        'report.replied' => [
            'title' => 'Report reply',
            'color' => 0x2ECC71,
        ],
        'report.closed' => [
            'title' => 'Report closed',
            'color' => 0x95A5A6,
        ],
        'appeal.created' => [
            'title' => 'New appeal',
            'color' => 0x9B59B6,
        ],
        'appeal.decided' => [
            'title' => 'Appeal decided',
            'color' => 0x8E44AD,
        ],
        'health.alert' => [
            'title' => 'Health alert',
            'color' => 0xE74C3C,
        ],
    ],

];