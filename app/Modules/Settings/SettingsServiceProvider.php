<?php

namespace App\Modules\Settings;

use App\Modules\Settings\App\Services\SettingService;
use App\Support\MailConfig;
use App\Support\ModuleServiceProvider;
use Throwable;

class SettingsServiceProvider extends ModuleServiceProvider
{
    public function moduleKey(): string
    {
        return 'settings';
    }

    protected function registerModule(): void
    {
        //
    }

    protected function bootModule(): void
    {
        // Layer the owner's stored SMTP credentials over config/mail.php.
        // Has to happen at boot rather than at send time because a Mailable
        // is dispatched from wherever it happens to be needed (an approved
        // ban appeal, for one) and none of those callers should have to know
        // the mailer is configurable.
        //
        // Never fatal: this runs on every request, including before the
        // settings table exists (fresh checkout, pre-migrate). A failure
        // here just leaves config/mail.php's .env-driven values in place,
        // which is exactly the un-configured behaviour anyway.
        try {
            MailConfig::apply($this->app->make(SettingService::class));
        } catch (Throwable) {
            //
        }
    }
}
