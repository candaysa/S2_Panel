<?php

namespace App\Http\Middleware;

use App\Modules\Settings\App\Services\SettingService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\HttpFoundation\Response;

/**
 * Applies the active locale to the whole request lifecycle.
 *
 * Priority: session locale (user choice) > settings default_locale (owner
 * choice) > config app.locale. Runs after StartSession on both groups.
 */
class SetLocale
{
    public function handle(Request $request, Closure $next): Response
    {
        $locale = $request->session()->get('locale');

        // The settings table may not exist yet (fresh install). Falling
        // back to the config default must never raise a 500.
        if ($locale === null && Schema::hasTable('settings')) {
            $locale = app(SettingService::class)->get('default_locale');
        }

        if ($locale === null) {
            $locale = config('app.locale', 'en');
        }

        app()->setLocale((string) $locale);

        return $next($request);
    }
}