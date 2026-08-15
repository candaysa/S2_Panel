<?php

namespace App\Modules\Admin\App\Http\Controllers;

use App\Modules\Admin\App\Services\AdminService;
use App\Support\Api;
use App\Support\SteamProfiles;
use App\Support\SteamId;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use InvalidArgumentException;

/**
 * Admin management endpoints (C3). Only admin.root flagged users (or the
 * owner) may manage admins; the flag middleware enforces that at the route
 * level.
 *
 * GET    /api/admin                  – list admins (search/status filters)
 * GET    /api/admin/groups           – list groups
 * POST   /api/admin                  – create admin
 * PUT    /api/admin/{id}             – update admin
 * DELETE /api/admin/{id}             – disable admin (row is never deleted)
 * POST   /api/admin/groups           – create group
 * PUT    /api/admin/groups/{name}    – update group
 * DELETE /api/admin/groups/{name}    – delete group
 */
class AdminController
{
    /**
     * Every flag Swiftly's CS2_Admin plugin understands. Not read from any
     * table - the plugin has no such catalogue - so this is a fixed list,
     * same set the previous panel hardcoded. New flags only exist if the
     * plugin adds them, so keeping this in sync is a manual step either way.
     */
    private const FLAGS = [
        'admin.root', 'admin.generic', 'admin.ban', 'admin.mute',
        'admin.kick', 'admin.cheats', 'admin.rcon', 'admin.vip',
    ];

    public function __construct(private readonly AdminService $admins)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $search = $request->query('search');
        $active = $request->has('active') ? filter_var($request->query('active'), FILTER_VALIDATE_BOOL) : null;
        $perPage = min((int) $request->query('per_page', 25), 100);
        $sort = (string) $request->query('sort', 'id');
        $dir = strtolower((string) $request->query('dir', 'desc')) === 'asc' ? 'asc' : 'desc';

        $admins = $this->admins->list($search !== null ? (string) $search : null, $active, $perPage, $sort, $dir);

        // One Steam call for the page, so the list can show faces instead of
        // a column of 17-digit numbers. Never fatal - see SteamProfiles.
        $profiles = SteamProfiles::many(collect($admins->items())->pluck('steamid')->all());
        $playtime = $this->admins->playtimeFor($admins->items());

        $items = collect($admins->items())->map(function ($admin) use ($profiles, $playtime): array {
            $row = is_array($admin) ? $admin : $admin->toArray();
            $row['avatar'] = $profiles[(string) ($row['steamid'] ?? '')]['avatar'] ?? null;
            $row['playtime_minutes'] = $playtime[(string) ($row['steamid'] ?? '')] ?? null;

            return $row;
        })->all();

        return Api::success($items, [
            'pagination' => [
                'current_page' => $admins->currentPage(),
                'per_page' => $admins->perPage(),
                'total' => $admins->total(),
                'last_page' => $admins->lastPage(),
            ],
            'flags' => self::FLAGS,
        ]);
    }

    public function groups(): JsonResponse
    {
        return Api::success($this->admins->groups(), ['flags' => self::FLAGS]);
    }

    public function store(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'steamid' => ['required', fn ($attribute, $value, $fail) => $this->validateSteamId($value, $fail)],
            'name' => 'required|string|max:64',
            'flags' => 'nullable|array',
            'flags.*' => ['string', Rule::in(self::FLAGS)],
            'groups' => 'nullable|array',
            'groups.*' => 'string|max:64',
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
            'flags' => 'nullable|array',
            'flags.*' => ['string', Rule::in(self::FLAGS)],
            'groups' => 'nullable|array',
            'groups.*' => 'string|max:64',
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

    public function storeGroup(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:64',
            'flags' => 'nullable|array',
            'flags.*' => ['string', Rule::in(self::FLAGS)],
            'immunity' => 'nullable|integer|min:0|max:100',
        ]);

        if ($validator->fails()) {
            return Api::error(Api::MSG_VALIDATION_FAILED, $validator->errors()->toArray(), 422);
        }

        try {
            $group = $this->admins->createGroup($validator->validated());
        } catch (InvalidArgumentException $e) {
            return Api::error($e->getMessage(), ['name' => [$e->getMessage()]], 422);
        }

        return Api::success($group, ['created' => true]);
    }

    public function updateGroup(Request $request, string $name): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'flags' => 'nullable|array',
            'flags.*' => ['string', Rule::in(self::FLAGS)],
            'immunity' => 'nullable|integer|min:0|max:100',
        ]);

        if ($validator->fails()) {
            return Api::error(Api::MSG_VALIDATION_FAILED, $validator->errors()->toArray(), 422);
        }

        try {
            $group = $this->admins->updateGroup($name, $validator->validated());
        } catch (InvalidArgumentException $e) {
            return $e->getMessage() === 'group_not_found' ? Api::notFound() : Api::error($e->getMessage(), [], 422);
        }

        return Api::success($group);
    }

    public function destroyGroup(string $name): JsonResponse
    {
        try {
            $this->admins->deleteGroup($name);
        } catch (InvalidArgumentException $e) {
            return $e->getMessage() === 'group_not_found' ? Api::notFound() : Api::error($e->getMessage(), [], 422);
        }

        return Api::success(['deleted' => true]);
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