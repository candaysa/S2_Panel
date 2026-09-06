<?php

namespace App\Modules\Plugins\App\Http\Controllers;

use App\Modules\Plugins\App\Models\PluginInstall;
use App\Modules\Plugins\App\Services\PluginManager;
use App\Support\Api;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;
use InvalidArgumentException;
use Throwable;

/**
 * Third-party plugin management (the "Plugins" tab). Owner-only - installing
 * a plugin is arbitrary PHP code execution by design (see PluginManager),
 * at least as sensitive as the Settings/Modules screens and more so.
 *
 * GET    /api/plugins          - list installed plugins
 * POST   /api/plugins          - upload + install a .zip
 * PUT    /api/plugins/{key}    - enable/disable
 * DELETE /api/plugins/{key}    - uninstall
 */
class PluginController
{
    public function __construct(private readonly PluginManager $plugins)
    {
    }

    public function index(): JsonResponse
    {
        $data = $this->plugins->all()->map(fn ($plugin): array => $this->present($plugin))->values()->all();

        return Api::success($data);
    }

    public function store(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'file' => 'required|file|mimes:zip|max:20480',
        ]);

        if ($validator->fails()) {
            return Api::error(Api::MSG_VALIDATION_FAILED, $validator->errors()->toArray(), 422);
        }

        try {
            $plugin = $this->plugins->install($request->file('file'), Auth::id());
        } catch (InvalidArgumentException $e) {
            return Api::error($e->getMessage(), [], 422);
        } catch (Throwable) {
            return Api::error('plugin_install_failed', [], 500);
        }

        return Api::success($this->present($plugin), ['installed' => true]);
    }

    public function update(Request $request, string $key): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'enabled' => 'required|boolean',
        ]);

        if ($validator->fails()) {
            return Api::error(Api::MSG_VALIDATION_FAILED, $validator->errors()->toArray(), 422);
        }

        try {
            $plugin = $this->plugins->setEnabled($key, (bool) $validator->validated()['enabled']);
        } catch (InvalidArgumentException) {
            return Api::notFound();
        }

        return Api::success($this->present($plugin));
    }

    public function destroy(string $key): JsonResponse
    {
        try {
            $this->plugins->uninstall($key);
        } catch (InvalidArgumentException) {
            return Api::notFound();
        }

        return Api::success(['key' => $key, 'uninstalled' => true]);
    }

    /**
     * @return array<string, mixed>
     */
    private function present(PluginInstall $plugin): array
    {
        return [
            'key' => $plugin->key,
            'name' => $plugin->name,
            'version' => $plugin->version,
            'author' => $plugin->author,
            'description' => $plugin->description,
            'enabled' => $plugin->enabled,
            'installed_at' => optional($plugin->created_at)->toISOString(),
        ];
    }
}
