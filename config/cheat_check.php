<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Cheat check (C18)
    |--------------------------------------------------------------------------
    |
    | An admin issues a one-off link for a player; the player runs it in
    | PowerShell ("irm '<url>' | iex") and the scanner posts its findings
    | back to the panel. The link carries a single-use token, the result
    | callback is authenticated with the shared API key below.
    |
    */

    /*
    | Falls back to a value derived from APP_KEY so a fresh install works with
    | no extra setup step - an unset key would otherwise make every scanner
    | callback fail with 401 while the panel still handed out working links.
    */
    'api_key' => env('CHEAT_CHECK_API_KEY') ?: hash('sha256', 'cheat-check:'.env('APP_KEY', '')),

    /*
    | How long an issued link stays downloadable. The scan itself may run
    | far longer – expiry is only checked when the script is fetched.
    */
    'token_ttl_minutes' => env('CHEAT_CHECK_TOKEN_TTL', 30),

    /*
    | Scans an admin may start per hour. 0 disables the limit.
    */
    'rate_limit_per_hour' => env('CHEAT_CHECK_RATE_LIMIT', 10),

    /*
    | Scheme used when building the link handed to the player. Behind a
    | reverse proxy that terminates TLS, Laravel may see plain http.
    */
    'force_scheme' => env('CHEAT_CHECK_FORCE_SCHEME', 'https'),

];
