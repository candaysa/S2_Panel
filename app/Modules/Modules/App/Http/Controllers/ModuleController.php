<?php

namespace App\Modules\Modules\App\Http\Controllers;

use App\Modules\Audit\App\Services\AuditService;
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
 * GET /api/modules          - state of every toggleable module
 * PUT /api/modules/{key}    - flip one module on/off
 *
 * Labels/descriptions are not returned here - the frontend renders those
 * from i18n so they follow the panel's locale, same as every other page.
 */
class ModuleController
{
    public function __construct(
        private readonly ModuleRegistry $modules,
        private readonly AuditService $audit,
    ) {
    }

    public function index(): JsonResponse
    {
        $data = array_map(
            fn (string $key): array => ['key' => $key, 'enabled' => $this->modules->isEnabled($key)],
            $this->modules->toggleable(),
        );

        return Api::success($data);
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
