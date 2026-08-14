<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Resend, Postmark, AWS, and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Steam OpenID (Socialite)
    |--------------------------------------------------------------------------
    |
    | Steam OpenID 2.0 has no client id and no client secret. Socialite's
    | interface asks for both anyway, and its Steam provider repurposes
    | client_secret as the Web API key - it is interpolated straight into the
    | GetPlayerSummaries URL. Everything therefore comes from STEAM_API_KEY,
    | so there is one value to obtain and one place it can be wrong.
    |
    | The callback defaults to APP_URL, which is where it has to point;
    | STEAM_CALLBACK_URL only exists for installations serving the panel from
    | a different external address than the app knows about.
    |
    */

    'steam' => [
        'client_id' => null,
        'client_secret' => env('STEAM_API_KEY'),
        'redirect' => env('STEAM_CALLBACK_URL', rtrim((string) env('APP_URL'), '/').'/api/auth/callback'),
        'api_key' => env('STEAM_API_KEY'),
    ],

];
