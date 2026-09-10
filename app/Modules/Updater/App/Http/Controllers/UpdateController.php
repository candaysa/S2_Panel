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
 * Owner-only update endpoints, driven from Settings > Updates.
 *
 * status   - what is running, what is available, whether this server can
 *            install it, and any update left half done
 * install  - download and apply in place (panel goes into maintenance)
 * finalise - migrate and come back up, in a fresh request (see there)
 * rollback - put the previous release back after an interrupted update
 *
 * install/finalise/rollback stay reachable while the panel is in
 * maintenance mode - see bootstrap/app.php.
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
            'pending' => $this->installer->pending(),
        ]);
    }

    public function install(Request $request): JsonResponse
    {
        // Always a fresh lookup, and always the server's own idea of what to
        // download - nothing about the bundle comes from the request.
        $release = $this->checker->check(true);

        if (! $release['available']) {
            return Api::error('already_up_to_date', [], 409);
        }

        if ($release['asset_url'] === null) {
            return Api::error('no_installable_asset', [], 409);
        }

        try {
            $result = $this->installer->install(
                $release['asset_url'],
                (string) $release['latest'],
                $release['tag'],
                $release['asset_digest'],
            );
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
     * A separate request on purpose: the process that copied the files is
     * still running the old classes, so migrating from there would run the
     * new release's migrations on the previous release's framework.
     */
    public function finalise(): JsonResponse
    {
        try {
            $result = $this->installer->finalise();
        } catch (Throwable $e) {
            return Api::error('finalise_failed', ['reason' => [$e->getMessage()]], 500);
        }

        return Api::success($result, ['finalised' => true]);
    }

    public function rollback(): JsonResponse
    {
        try {
            $rolledBack = $this->installer->rollBack();
        } catch (RuntimeException $e) {
            return Api::error('rollback_failed', ['reason' => [$e->getMessage()]], 409);
        }

        if (! $rolledBack) {
            return Api::error('nothing_to_roll_back', [], 409);
        }

        return Api::success(['version' => config('panel.version')], ['rolled_back' => true]);
    }
}
