<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Server module settings
    |--------------------------------------------------------------------------
    |
    | a2s_timeout controls how long a live A2S query may wait before the
    | server is reported as offline (seconds). Keep it small – the list
    | endpoint queries every registered server sequentially.
    |
    */

    'a2s_timeout' => env('SERVER_A2S_TIMEOUT', 2.0),

];
