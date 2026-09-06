<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Health monitor (C16)
    |--------------------------------------------------------------------------
    |
    | The health:check command (scheduled every 5 minutes while the module
    | is enabled) probes every named database connection with a SELECT 1
    | and attempts an RCON authentication against every server that has a
    | stored rcon_settings row. A component flipping to "down" records a
    | health_checks row, creates a notification for the panel owner and
    | dispatches the HealthAlert event (C17 webhooks listen for it).
    |
    */

    'databases' => ['swiftly', 'ranks', 'weaponskins'],

    'rcon' => [
        'enabled' => env('HEALTH_RCON', true),
        'timeout' => env('HEALTH_RCON_TIMEOUT', 2.0),
    ],

];