<?php

namespace App\Http\Middleware;

use App\Support\ModuleRegistry;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Requires a module to be enabled before its page will render.
 *
 * A module's own Routes/api.php already disappears when it is switched off
 * (see ModuleServiceProvider::boot), but the Blade pages in routes/web.php
 * are registered by the app itself and so survived the module that fed
 * them - the page loaded, every fetch behind it 404'd, and the result
 * looked like a broken panel rather than a disabled feature. This closes
 * that half: with the module off the page is simply not there.
 *
 * 404 rather than 403 on purpose - a disabled feature does not exist for
 * this install, which is a different statement from "you may not see it"
 * and should not hint that it might be reachable with better flags.
 *
 * Usage: Route::middleware('module:vip')
 *        Route::middleware('module:report,appeal')  (any-of)
 */
class RequireModule
{
    public function handle(Request $request, Closure $next, string ...$modules): Response
    {
        $registry = app(ModuleRegistry::class);

        foreach ($modules as $key) {
            if ($registry->isEnabled(trim($key))) {
                return $next($request);
            }
        }

        abort(404);
    }
}
