<?php

namespace App\Modules\Updater\App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Updater\App\Services\UpdateChecker;
use App\Modules\Updater\App\Services\UpdateInstaller;
use App\Support\Api;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;
use Throwable;

/**
 * Owner-only update endpoints.
 *
 * status  - what version is running, what is available, and whether this
 *           server is even able to install it
 * install - download and swap, then finalise on the next request
 */
class UpdateController extends Controller
{
    public function __construct(
        private readonly UpdateChecker $checker,
        private readonly UpdateInstaller $installer,
    ) {
    }

    public function status(Request $request): JsonResponse
    {
        $release = $this->checker->check($request->boolean('force'));
        $preflight = $this->installer->preflight();

        return Api::success([
            'release' => $release,
            'can_install' => $release['available'] && $release['asset_url'] !== null && $preflight['ready'],
            'preflight' => $preflight,
        ]);
    }

    public function install(Request $request): JsonResponse
    {
        $release = $this->checker->check(true);

        if (! $release['available']) {
            return Api::error('already_up_to_date', [], 409);
        }

        if ($release['asset_url'] === null) {
            return Api::error('no_installable_asset', [], 409);
        }

        try {
            $result = $this->installer->install($release['asset_url'], (string) $release['latest']);
        } catch (RuntimeException $e) {
            // The message carries the specific failure so the owner is not
            // left guessing which check or step went wrong.
            return Api::error('install_failed', ['reason' => [$e->getMessage()]], 422);
        } catch (Throwable) {
            return Api::error('install_failed', ['reason' => ['unexpected_error']], 500);
        }

        return Api::success($result, ['installed' => true]);
    }

    /**
     * Run migrations and clear caches against the freshly installed code.
     *
     * A separate request on purpose: the process that performed the swap is
     * still running the old classes, so migrating from there would run the
     * previous release's migrations.
     */
    public function finalise(): JsonResponse
    {
        try {
            $this->installer->finalise();
        } catch (Throwable $e) {
            return Api::error('finalise_failed', ['reason' => [$e->getMessage()]], 500);
        }

        return Api::success(['version' => config('panel.version')], ['finalised' => true]);
    }
}
