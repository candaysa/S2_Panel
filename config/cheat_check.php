<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Cheat check (C18)
    |--------------------------------------------------------------------------
    |
    | An admin issues a link for a player; the player runs it in PowerShell
    | ("irm '<url>' | iex") and the scanner posts its findings back to the
    | panel. The link carries a token good for a handful of fetches (see
    | CheatScanToken::MAX_DOWNLOADS - the elevation bootstrap alone needs
    | two: the plain fetch and its UAC-elevated retry, and real-world
    | slack beyond that matters because the admin is rarely the one running
    | it - it gets pasted to a player who may re-run a command that visibly
    | did nothing while a UAC prompt was opening behind another window, or
    | get its link pre-fetched once by a chat client's own link scanner).
    | The result callback is authenticated with the shared API key below.
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
    |
    | 30 minutes was the original default, but the admin who generates the
    | link is almost never the one who runs it - it goes to a player over
    | Discord/chat, who has to notice the message and act on it first. That
    | routinely blew past 30 minutes for no reason related to the scan
    | itself, and the failure ("token_expired") reads identically to a
    | real problem to whoever is holding the link.
    */
    'token_ttl_minutes' => env('CHEAT_CHECK_TOKEN_TTL', 60),

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
