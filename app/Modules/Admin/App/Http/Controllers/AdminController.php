<?php

namespace App\Modules\Admin\App\Http\Controllers;

use App\Modules\Admin\App\Services\AdminService;
use App\Support\Api;
use App\Support\SteamProfiles;
use App\Support\SteamId;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use InvalidArgumentException;

/**
 * Admin management endpoints (C3). Only admin.root flagged users (or the
 * owner) may manage admins; the flag middleware enforces that at the route
 * level.
 *
 * GET    /api/admin            – list admins (search/status filters)
 * GET    /api/admin/groups     – available admin groups
 * POST   /api/admin            – create admin
 * PUT    /api/admin/{id}       – update admin
 * DELETE /api/admin/{id}       – disable admin (row is never deleted)
 */
class AdminController
{
    public function __construct(private readonly AdminService $admins)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $search = $request->query('search');
        $active = $request->has('active') ? filter_var($request->query('active'), FILTER_VALIDATE_BOOL) : null;
        $perPage = min((int) $request->query('per_page', 25), 100);

        $admins = $this->admins->list($search !== null ? (string) $search : null, $active, $perPage);

        // One Steam call for the page, so the list can show faces instead of
        // a column of 17-digit numbers. Never fatal - see SteamProfiles.
        $profiles = SteamProfiles::many(collect($admins->items())->pluck('steamid')->all());

        $items = collect($admins->items())->map(function ($admin) use ($profiles): array {
            $row = is_array($admin) ? $admin : $admin->toArray();
            $row['avatar'] = $profiles[(string) ($row['steamid'] ?? '')]['avatar'] ?? null;

            return $row;
        })->all();

        return Api::success($items, [
            'pagination' => [
                'current_page' => $admins->currentPage(),
                'per_page' => $admins->perPage(),
                'total' => $admins->total(),
                'last_page' => $admins->lastPage(),
            ],
        ]);
    }

    public function groups(): JsonResponse
    {
        return Api::success($this->admins->groups());
    }

    public function store(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'steamid' => ['required', fn ($attribute, $value, $fail) => $this->validateSteamId($value, $fail)],
            'name' => 'required|string|max:64',
            'flags' => 'nullable|string|max:255',
            'groups' => 'nullable|string|max:255',
            'immunity' => 'nullable|integer|min:0|max:100',
            'expires_at' => 'nullable|date',
        ]);

        if ($validator->fails()) {
            return Api::error(Api::MSG_VALIDATION_FAILED, $validator->errors()->toArray(), 422);
        }

        try {
            $admin = $this->admins->create($validator->validated());
        } catch (InvalidArgumentException $e) {
            return Api::error('already_exists', ['steamid' => [$e->getMessage()]], 422);
        }

        return Api::success($admin, ['created' => true]);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'steamid' => ['nullable', fn ($attribute, $value, $fail) => $this->validateSteamId($value, $fail)],
            'name' => 'nullable|string|max:64',
            'flags' => 'nullable|string|max:255',
            'groups' => 'nullable|string|max:255',
            'immunity' => 'nullable|integer|min:0|max:100',
            'expires_at' => 'nullable|date',
        ]);

        if ($validator->fails()) {
            return Api::error(Api::MSG_VALIDATION_FAILED, $validator->errors()->toArray(), 422);
        }

        try {
            $admin = $this->admins->update($id, $validator->validated());
        } catch (InvalidArgumentException $e) {
            if ($e->getMessage() === 'admin_not_found') {
                return Api::notFound();
            }

            return Api::error('already_exists', ['steamid' => [$e->getMessage()]], 422);
        }

        return Api::success($admin);
    }

    public function destroy(int $id): JsonResponse
    {
        try {
            $admin = $this->admins->disable($id);
        } catch (InvalidArgumentException) {
            return Api::notFound();
        }

        return Api::success($admin, ['disabled' => true]);
    }

    private function validateSteamId(mixed $value, callable $fail): void
    {
        if (! is_string($value) && ! is_int($value)) {
            $fail('Invalid SteamID format.');

            return;
        }

        if (! SteamId::isValid($value)) {
            $fail('Invalid SteamID format.');
        }
    }
}