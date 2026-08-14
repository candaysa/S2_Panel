<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Module Registry
    |--------------------------------------------------------------------------
    |
    | Every feature lives in its own module under app/Modules/*. A module is
    | enabled via its MODULE_* environment variable (config is cached, so run
    | `php artisan config:cache` after changing .env). The installer writes
    | these variables; "auth" and "install" are always on because they are
    | required for the panel to function.
    |
    | Each module's ServiceProvider extends App\Support\ModuleServiceProvider
    | which handles the enable/disable gate automatically.
    |
    */

    'modules' => [
        'auth' => [
            'enabled' => true,
            'provider' => App\Modules\Auth\AuthServiceProvider::class,
            'depends' => [],
        ],

        'install' => [
            'enabled' => true,
            'provider' => App\Modules\Install\InstallServiceProvider::class,
            'depends' => [],
        ],

        // Owner-facing module management (the "Modules" tab): lets the owner
        // flip a subset of modules on/off at runtime without touching .env.
        // Always on for the same reason auth/install are - it is core panel
        // plumbing, not a removable feature. See ModuleRegistry::toggleable().
        'modules' => [
            'enabled' => true,
            'provider' => App\Modules\Modules\ModulesServiceProvider::class,
            'depends' => [],
        ],

        // Owner-facing plugin management (the "Plugins" tab): lets the
        // owner upload/enable/disable/remove third-party .zip plugins at
        // runtime. Always on for the same reason "modules" is - it's how
        // installed plugins get discovered in the first place. See
        // PluginManager and AppServiceProvider::register().
        'plugins' => [
            'enabled' => true,
            'provider' => App\Modules\Plugins\PluginsServiceProvider::class,
            'depends' => [],
        ],

        'admin' => [
            'enabled' => env('MODULE_ADMIN', false),
            'provider' => App\Modules\Admin\AdminServiceProvider::class,
            'depends' => ['auth'],
        ],

        'ban' => [
            'enabled' => env('MODULE_BAN', false),
            'provider' => App\Modules\Ban\BanServiceProvider::class,
            'depends' => ['auth'],
        ],

        'rank' => [
            'enabled' => env('MODULE_RANK', false),
            'provider' => App\Modules\Rank\RankServiceProvider::class,
            'depends' => ['auth'],
        ],

        'skin' => [
            'enabled' => env('MODULE_SKIN', false),
            'provider' => App\Modules\Skin\SkinServiceProvider::class,
            'depends' => ['auth'],
        ],

        'vip' => [
            'enabled' => env('MODULE_VIP', false),
            'provider' => App\Modules\Vip\VipServiceProvider::class,
            'depends' => ['auth'],
        ],

        'audit' => [
            'enabled' => env('MODULE_AUDIT', false),
            'provider' => App\Modules\Audit\AuditServiceProvider::class,
            'depends' => ['auth'],
        ],

        'report' => [
            'enabled' => env('MODULE_REPORTS', false),
            'provider' => App\Modules\Report\ReportServiceProvider::class,
            'depends' => ['auth'],
        ],

        'appeal' => [
            'enabled' => env('MODULE_APPEALS', false),
            'provider' => App\Modules\Appeal\AppealServiceProvider::class,
            'depends' => ['auth'],
        ],

        'server' => [
            'enabled' => env('MODULE_SERVERS', false),
            'provider' => App\Modules\Server\ServerServiceProvider::class,
            'depends' => ['auth'],
        ],

        'rcon' => [
            'enabled' => env('MODULE_RCON', false),
            'provider' => App\Modules\Rcon\RconServiceProvider::class,
            'depends' => ['auth', 'server'],
        ],

        'stats' => [
            'enabled' => env('MODULE_STATS', false),
            'provider' => App\Modules\Stats\StatsServiceProvider::class,
            'depends' => ['auth', 'server'],
        ],

        'settings' => [
            'enabled' => env('MODULE_SETTINGS', false),
            'provider' => App\Modules\Settings\SettingsServiceProvider::class,
            'depends' => ['auth'],
        ],

        'i18n' => [
            'enabled' => env('MODULE_I18N', false),
            'provider' => App\Modules\I18n\I18nServiceProvider::class,
            'depends' => ['auth'],
        ],

        'health' => [
            'enabled' => env('MODULE_HEALTH', false),
            'provider' => App\Modules\Health\HealthServiceProvider::class,
            'depends' => ['auth', 'rcon'],
        ],

        'webhook' => [
            'enabled' => env('MODULE_WEBHOOKS', false),
            'provider' => App\Modules\Webhook\WebhookServiceProvider::class,
            'depends' => ['auth'],
        ],
    ],

];
