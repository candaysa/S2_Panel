<?php

namespace App\Modules\Skin\App\Http\Controllers;

use App\Modules\Skin\App\Services\CatalogService;
use App\Modules\Skin\App\Services\SkinService;
use App\Support\Api;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use InvalidArgumentException;

/**
 * Skin endpoints (C6). Reads are visible to any authenticated session;
 * mutations require admin.root (owner bypasses via RequireFlag).
 *
 * GET    /api/skins/{steamid}              – full loadout
 * GET    /api/skins/catalog/{type}         – static JSON catalog slice
 * PUT    /api/skins/{steamid}/{slot}       – upsert (weapon/knife/gloves/agent/music)
 * DELETE /api/skins/{steamid}/{slot}       – delete by team (weapon also defindex)
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
        try {
            $profile = $this->skins->profile($steamid);
        } catch (InvalidArgumentException) {
            return Api::error(Api::MSG_INVALID_INPUT, ['steamid' => ['invalid_steamid_format']], 422);
        }

        return Api::success($profile);
    }

    public function catalog(string $type): JsonResponse
    {
        if (! in_array($type, $this->catalog->types(), true)) {
            return Api::error(Api::MSG_INVALID_INPUT, ['type' => ['invalid_catalog_type']], 422);
        }

        return Api::success($this->catalog->get($type), ['type' => $type]);
    }

    public function store(Request $request, string $steamid, string $slot): JsonResponse
    {
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
}