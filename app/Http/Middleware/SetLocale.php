<?php

namespace App\Http\Middleware;

use App\Modules\Settings\App\Services\SettingService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

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

        // Before install there may be no settings table - or, since the
        // panel's database is only chosen in the wizard now, no reachable
        // database at all, and hasTable() is itself a query. Either way the
        // config default has to stand in; a language lookup must never be
        // the reason the installer itself cannot load.
        if ($locale === null) {
            try {
                if (Schema::hasTable('settings')) {
                    $locale = app(SettingService::class)->get('default_locale');
                }
            } catch (Throwable) {
                $locale = null;
            }
        }

        if ($locale === null) {
            $locale = config('app.locale', 'en');
        }

        app()->setLocale((string) $locale);

        return $next($request);
    }
}