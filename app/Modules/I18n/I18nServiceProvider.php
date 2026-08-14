<?php

namespace App\Modules\I18n;

use App\Support\ModuleServiceProvider;

class I18nServiceProvider extends ModuleServiceProvider
{
    public function moduleKey(): string
    {
        return 'i18n';
    }

    protected function registerModule(): void
    {
        //
    }

    protected function bootModule(): void
    {
        $this->loadTranslationsFrom($this->modulePath().'/lang', 'i18n');
    }
}