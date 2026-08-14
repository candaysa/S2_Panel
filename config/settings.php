<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Settings module configuration
    |--------------------------------------------------------------------------
    |
    | The panel keeps its own settings in the `settings` table (not .env).
    | `defaults` seeds values until an owner overwrites them.
    | `whitelist` is the only set of keys an owner may update via the API.
    | `upload_path` receives logo/favicon uploads (public web root).
    |
    */

    'defaults' => [
        'site_name' => 'S2 Panel',
        'site_description' => 'CS2 server management panel',
        'default_locale' => 'en',
        'timezone' => 'UTC',
        'logo' => '',
        'favicon' => '',
        // Sampled from the Swiftly logo mark - the factory accent color,
        // not a fixed brand identity. --color-brand-strong/-soft in
        // app.css derive from this via color-mix(), so overriding just
        // this one hex re-tints every accent surface in the panel.
        'brand_color' => '#00ffe3',
    ],

    'whitelist' => [
        'site_name',
        'site_description',
        'default_locale',
        'timezone',
        'brand_color',
    ],

    'upload_path' => public_path('uploads'),

];