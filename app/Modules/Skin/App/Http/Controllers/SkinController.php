<?php

namespace App\Modules\Skin\App\Http\Controllers;

use App\Modules\Skin\App\Services\CatalogService;
use App\Modules\Skin\App\Services\SkinService;
use App\Models\User;
use App\Support\Api;
use App\Support\Flags;
use App\Support\SteamId;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;
use InvalidArgumentException;

/**
 * Skin endpoints (C6). A player manages their own loadout; admin.root (or
 * the owner) may additionally manage anyone's, for support/moderation.
 *
 * GET    /api/skins/{steamid}                      – full loadout
 * PUT    /api/skins/{steamid}/{slot}                – upsert (weapon/knife/gloves/agent/music)
 * DELETE /api/skins/{steamid}/{slot}                – delete by team (weapon also defindex)
 * GET    /api/skins/catalog/weapons                 – skinnable guns
 * GET    /api/skins/catalog/weapons/{weapon}/paints  – paint choices for one gun
 * GET    /api/skins/catalog/knives                  – knife types
 * GET    /api/skins/catalog/gloves                  – glove types
 * GET    /api/skins/catalog/agents                  – agents (optional ?team=2|3)
 * GET    /api/skins/catalog/music                   – music kits
 */
class SkinController
{
    public function __construct(
        private readonly SkinService $skins,
        private readonly CatalogService $catalog,
    ) {
    }

    public function show(string $steamid): JsonResponse
    {
        if (! $this->authorized($steamid)) {
            return Api::forbidden();
        }

        try {
            $profile = $this->skins->profile($steamid);
        } catch (InvalidArgumentException) {
            return Api::error(Api::MSG_INVALID_INPUT, ['steamid' => ['invalid_steamid_format']], 422);
        }

        return Api::success($profile);
    }

    public function catalogWeapons(): JsonResponse
    {
        return Api::success($this->catalog->weapons());
    }

    public function catalogPaintkits(string $weapon): JsonResponse
    {
        return Api::success($this->catalog->paintkits($weapon));
    }

    public function catalogPaintNames(): JsonResponse
    {
        return Api::success($this->catalog->paintNames());
    }

    public function catalogKnives(): JsonResponse
    {
        return Api::success($this->catalog->knives());
    }

    public function catalogGloves(): JsonResponse
    {
        return Api::success($this->catalog->gloves());
    }

    public function catalogAgents(Request $request): JsonResponse
    {
        $team = $request->query('team');
        $team = in_array($team, ['2', '3'], true) ? (int) $team : null;

        return Api::success($this->catalog->agents($team));
    }

    public function catalogMusic(): JsonResponse
    {
        return Api::success($this->catalog->music());
    }

    public function store(Request $request, string $steamid, string $slot): JsonResponse
    {
        if (! $this->authorized($steamid)) {
            return Api::forbidden();
        }

        $validators = [
            'weapon' => [
                'team' => 'required|integer|in:2,3',
                'defindex' => 'required|integer|min:0|max:2147483647',
                'weapon_paint_id' => 'nullable|integer|min:0|max:2147483647',
                'weapon_wear' => 'nullable|numeric|min:0|max:1',
                'weapon_seed' => 'nullable|integer|min:0|max:99999',
                'weapon_nametag' => 'nullable|string|max:128',
                'weapon_stattrak' => 'nullable|boolean',
                'weapon_stattrak_count' => 'nullable|integer|min:0',
                'weapon_sticker_0' => 'nullable|string|max:128',
                'weapon_sticker_1' => 'nullable|string|max:128',
                'weapon_sticker_2' => 'nullable|string|max:128',
                'weapon_sticker_3' => 'nullable|string|max:128',
                'weapon_sticker_4' => 'nullable|string|max:128',
                'weapon_sticker_5' => 'nullable|string|max:128',
                'weapon_keychain' => 'nullable|string|max:128',
            ],
            'knife' => [
                'team' => 'required|integer|in:2,3',
                'knife' => 'required|string|max:64',
            ],
            'gloves' => [
                'team' => 'required|integer|in:2,3',
                'weapon_defindex' => 'required|integer|min:0|max:2147483647',
            ],
            'agent' => [
                'team' => 'required|integer|in:2,3',
                'agent_index' => 'required|integer|min:0|max:2147483647',
            ],
            'music' => [
                'team' => 'required|integer|in:2,3',
                'music_id' => 'required|integer|min:0|max:2147483647',
            ],
        ];

        $rules = $validators[$slot] ?? null;

        if ($rules === null) {
            return Api::error(Api::MSG_INVALID_INPUT, ['slot' => ['invalid_slot']], 422);
        }

        $validator = Validator::make($request->all(), $rules);

        if ($validator->fails()) {
            return Api::error(Api::MSG_VALIDATION_FAILED, $validator->errors()->toArray(), 422);
        }

        $data = $validator->validated();
        $team = (int) $data['team'];

        try {
            $result = match ($slot) {
                'weapon' => $this->skins->setWeapon($steamid, $team, (int) $data['defindex'], $data),
                'knife' => $this->skins->setKnife($steamid, $team, $data),
                'gloves' => $this->skins->setGloves($steamid, $team, $data),
                'agent' => $this->skins->setAgent($steamid, $team, $data),
                'music' => $this->skins->setMusic($steamid, $team, $data),
            };
        } catch (InvalidArgumentException $e) {
            return Api::error(Api::MSG_INVALID_INPUT, ['steamid' => [$e->getMessage()]], 422);
        }

        return Api::success($result, ['upserted' => true]);
    }

    public function destroy(Request $request, string $steamid, string $slot): JsonResponse
    {
        if (! $this->authorized($steamid)) {
            return Api::forbidden();
        }

        $rules = match ($slot) {
            'weapon' => ['team' => 'required|integer|in:2,3', 'defindex' => 'required|integer|min:0'],
            'knife', 'gloves', 'agent', 'music' => ['team' => 'required|integer|in:2,3'],
            default => null,
        };

        if ($rules === null) {
            return Api::error(Api::MSG_INVALID_INPUT, ['slot' => ['invalid_slot']], 422);
        }

        $validator = Validator::make($request->query(), $rules);

        if ($validator->fails()) {
            return Api::error(Api::MSG_VALIDATION_FAILED, $validator->errors()->toArray(), 422);
        }

        $team = (int) $validator->validated()['team'];

        try {
            $deleted = match ($slot) {
                'weapon' => $this->skins->removeWeapon($steamid, $team, (int) $validator->validated()['defindex']),
                'knife' => $this->skins->removeKnife($steamid, $team),
                'gloves' => $this->skins->removeGloves($steamid, $team),
                'agent' => $this->skins->removeAgent($steamid, $team),
                'music' => $this->skins->removeMusic($steamid, $team),
            };
        } catch (InvalidArgumentException $e) {
            return Api::error(Api::MSG_INVALID_INPUT, ['steamid' => [$e->getMessage()]], 422);
        }

        return Api::success(['deleted' => $deleted]);
    }

    /**
     * The player editing their own loadout, or admin.root/owner support
     * access to anyone's. Fail-closed on any malformed steamid.
     */
    private function authorized(string $steamid): bool
    {
        /** @var User $user */
        $user = Auth::user();

        if ($user->isOwner()) {
            return true;
        }

        try {
            if (SteamId::parse((string) $user->steam_id)->equals(SteamId::parse($steamid))) {
                return true;
            }
        } catch (InvalidArgumentException) {
            return false;
        }

        return Flags::hasFlag((int) $user->steam_id, 'admin.root');
    }
}
