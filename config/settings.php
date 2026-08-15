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
        // Which admin GROUP sees every ticket in a category (reports, admin
        // applications, ban appeals) instead of only their own - one group
        // per category, not one shared flag list, so e.g. a generic
        // moderation group can triage reports while only a root-level group
        // sees admin applications. Empty means "owner only" until
        // configured. See App\Support\TicketAccess. Deciding a ticket
        // (close/approve/reject) still requires admin.root regardless of
        // this setting - see TicketAccess::canDecide().
        'ticket_staff_group_report' => '',
        'ticket_staff_group_admin_application' => '',
        'ticket_staff_group_ban_appeal' => '',
        // Which admin plugin owns the permission/admin/group data on the
        // 'swiftly' connection: 'cs2_admin' (admin_admins/admin_groups,
        // CSV columns) or 'swiftly_admins' (admins/groups, JSON-array
        // columns - github.com/swiftlys2-plugins/admins). Chosen once at
        // install time (InstallController), not auto-detected and not in
        // `whitelist` below on purpose - flipping it post-install without a
        // data migration would silently point every admin/ban query at the
        // wrong schema. See App\Support\AdminPlugin\AdminManagerInterface.
        'admin_plugin' => 'cs2_admin',
    ],

    'whitelist' => [
        'site_name',
        'site_description',
        'default_locale',
        'timezone',
        'brand_color',
        'ticket_staff_group_report',
        'ticket_staff_group_admin_application',
        'ticket_staff_group_ban_appeal',
    ],

    'upload_path' => public_path('uploads'),

];