<?php

use App\Modules\Admin\AdminServiceProvider;
use App\Modules\Appeal\AppealServiceProvider;
use App\Modules\Audit\AuditServiceProvider;
use App\Modules\Auth\AuthServiceProvider;
use App\Modules\Ban\BanServiceProvider;
use App\Modules\CheatCheck\CheatCheckServiceProvider;
use App\Modules\Health\HealthServiceProvider;
use App\Modules\I18n\I18nServiceProvider;
use App\Modules\Install\InstallServiceProvider;
use App\Modules\Modules\ModulesServiceProvider;
use App\Modules\Plugins\PluginsServiceProvider;
use App\Modules\Rank\RankServiceProvider;
use App\Modules\Rcon\RconServiceProvider;
use App\Modules\Report\ReportServiceProvider;
use App\Modules\Server\ServerServiceProvider;
use App\Modules\Settings\SettingsServiceProvider;
use App\Modules\Skin\SkinServiceProvider;
use App\Modules\Stats\StatsServiceProvider;
use App\Modules\Vip\VipServiceProvider;
use App\Modules\Webhook\WebhookServiceProvider;
use App\Providers\AppServiceProvider;

return [
    AppServiceProvider::class,
    AuthServiceProvider::class,
    InstallServiceProvider::class,
    ModulesServiceProvider::class,
    PluginsServiceProvider::class,
    AdminServiceProvider::class,
    BanServiceProvider::class,
    RankServiceProvider::class,
    SkinServiceProvider::class,
    VipServiceProvider::class,
    AuditServiceProvider::class,
    ReportServiceProvider::class,
    AppealServiceProvider::class,
    ServerServiceProvider::class,
    RconServiceProvider::class,
    StatsServiceProvider::class,
    SettingsServiceProvider::class,
    I18nServiceProvider::class,
    HealthServiceProvider::class,
    WebhookServiceProvider::class,
    CheatCheckServiceProvider::class,
];