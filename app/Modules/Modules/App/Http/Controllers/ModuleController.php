<?php

namespace App\Modules\Modules\App\Http\Controllers;

use App\Modules\Audit\App\Services\AuditService;
use App\Modules\Settings\App\Services\SettingService;
use App\Support\Api;
use App\Support\ModuleRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;
use InvalidArgumentException;

/**
 * Owner-facing module management (the "Modules" tab).
 *
 * GET  /api/modules               - state of every toggleable module
 * PUT  /api/modules/{key}         - flip one module on/off
 * PUT  /api/modules/admin-plugin  - switch which admin plugin's schema is active
 *
 * Labels/descriptions are not returned here - the frontend renders those
 * from i18n so they follow the panel's locale, same as every other page.
 */
class ModuleController
{
    private const ADMIN_PLUGINS = ['cs2_admin', 'swiftly_admins'];

    public function __construct(
        private readonly ModuleRegistry $modules,
        private readonly AuditService $audit,
        private readonly SettingService $settings,
    ) {
    }

    public function index(): JsonResponse
    {
        $all = $this->modules->all();

        // depends/dependents travel with each row so the tab can warn before
        // a switch is flipped rather than after - see ModuleRegistry.
        $data = array_map(
            fn (string $key): array => [
                'key' => $key,
                'enabled' => $this->modules->isEnabled($key),
                'depends' => array_values($all[$key]['depends'] ?? []),
                'dependents' => $this->modules->dependents($key),
            ],
            $this->modules->toggleable(),
        );

        return Api::success($data, [
            'admin_plugin' => $this->settings->get('admin_plugin', 'cs2_admin'),
        ]);
    }

    /**
     * PUT /api/modules/admin-plugin
     *
     * Not part of Settings' whitelist on purpose (see config/settings.php) -
     * this is the one deliberate, explicit way to change it post-install,
     * distinct from an owner accidentally flipping it through a generic
     * settings form. See App\Support\AdminPlugin\AdminManagerInterface.
     */
    public function updateAdminPlugin(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'plugin' => ['required', 'string', 'in:'.implode(',', self::ADMIN_PLUGINS)],
        ]);

        if ($validator->fails()) {
            return Api::error(Api::MSG_VALIDATION_FAILED, $validator->errors()->toArray(), 422);
        }

        $plugin = (string) $validator->validated()['plugin'];
        $previous = $this->settings->get('admin_plugin', 'cs2_admin');

        $this->settings->set('admin_plugin', $plugin, Auth::id());

        $this->audit->log('module.admin_plugin_changed', 'settings', 'admin_plugin', [
            'from' => $previous,
            'to' => $plugin,
        ]);

        return Api::success(['admin_plugin' => $plugin]);
    }

    public function update(Request $request, string $key): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'enabled' => 'required|boolean',
        ]);

        if ($validator->fails()) {
            return Api::error(Api::MSG_VALIDATION_FAILED, $validator->errors()->toArray(), 422);
        }

        $enabled = (bool) $validator->validated()['enabled'];

        try {
            $this->modules->setOverride($key, $enabled, Auth::id());
        } catch (InvalidArgumentException) {
            return Api::notFound();
        }

        $this->audit->log($enabled ? 'module.enabled' : 'module.disabled', 'module', $key);

        return Api::success(['key' => $key, 'enabled' => $enabled]);
    }
}
