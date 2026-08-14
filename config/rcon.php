<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Rcon module settings
    |--------------------------------------------------------------------------
    |
    | timeout controls how long a single RCON call (connect + auth + exec)
    | may take before the server is reported as unreachable (seconds).
    | Keep it small – console endpoints are synchronous.
    |
    */

    'timeout' => env('RCON_TIMEOUT', 2.0),

];