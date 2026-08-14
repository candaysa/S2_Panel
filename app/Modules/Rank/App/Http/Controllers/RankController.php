<?php

namespace App\Modules\Rank\App\Http\Controllers;

use App\Modules\Rank\App\Services\RankService;
use App\Support\Api;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use InvalidArgumentException;

/**
 * Rank endpoints (C5).
 *
 * GET    /api/ranks                – leaderboard (search + pagination)
 * GET    /api/ranks/{steamid}      – player profile (rank_base + rank_hits)
 * PATCH  /api/ranks/{steamid}/points – edit points (requires admin.root)
 */
class RankController
{
    public function __construct(private readonly RankService $ranks)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $search = $request->query('search');
        $perPage = min((int) $request->query('per_page', 25), 100);

        $players = $this->ranks->leaderboard($search !== null ? (string) $search : null, $perPage);

        return Api::success($players->items(), [
            'pagination' => [
                'current_page' => $players->currentPage(),
                'per_page' => $players->perPage(),
                'total' => $players->total(),
                'last_page' => $players->lastPage(),
            ],
        ]);
    }

    public function show(string $steamid): JsonResponse
    {
        try {
            $profile = $this->ranks->profile($steamid);
        } catch (InvalidArgumentException) {
            return Api::error(Api::MSG_INVALID_INPUT, ['steamid' => ['invalid_steamid_format']], 422);
        }

        if ($profile === null) {
            return Api::notFound();
        }

        return Api::success($profile);
    }

    public function updatePoints(Request $request, string $steamid): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'value' => 'required|integer|min:0|max:2147483647',
        ]);

        if ($validator->fails()) {
            return Api::error(Api::MSG_VALIDATION_FAILED, $validator->errors()->toArray(), 422);
        }

        try {
            $player = $this->ranks->updatePoints($steamid, (int) $validator->validated()['value']);
        } catch (InvalidArgumentException $e) {
            if ($e->getMessage() === 'player_not_found') {
                return Api::notFound();
            }

            return Api::error(Api::MSG_INVALID_INPUT, ['steamid' => [$e->getMessage()]], 422);
        }

        return Api::success($player);
    }
}