<?php

namespace App\Modules\Admin;

use App\Modules\Admin\App\Services\AdminService;
use App\Modules\Settings\App\Services\SettingService;
use App\Support\AdminPlugin\AdminManagerInterface;
use App\Support\AdminPlugin\SwiftlyAdminsService;
use App\Support\ModuleServiceProvider;

class AdminServiceProvider extends ModuleServiceProvider
{
    public function moduleKey(): string
    {
        return 'admin';
    }

    protected function registerModule(): void
    {
        // Which plugin's schema AdminController (and Flags, separately)
        // reads is a per-install choice, not something to detect - see
        // config/settings.php's `admin_plugin` entry.
        $this->app->bind(AdminManagerInterface::class, function ($app): AdminManagerInterface {
            $plugin = $app->make(SettingService::class)->get('admin_plugin', 'cs2_admin');

            return $plugin === 'swiftly_admins'
                ? $app->make(SwiftlyAdminsService::class)
                : $app->make(AdminService::class);
        });
    }

    protected function bootModule(): void
    {
        //
    }
}