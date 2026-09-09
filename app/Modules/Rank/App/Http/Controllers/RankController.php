<?php

namespace App\Modules\Rank\App\Http\Controllers;

use App\Modules\Rank\App\Services\PlayerActivityService;
use App\Modules\Rank\App\Services\PlayerNoteService;
use App\Modules\Rank\App\Services\RankService;
use App\Support\Api;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use InvalidArgumentException;

/**
 * Rank endpoints (C5).
 *
 * GET    /api/ranks                  – leaderboard (search + pagination)
 * GET    /api/ranks/{steamid}        – player profile (lvl_base + lvl_base_hits + lvl_base_weapons)
 * GET    /api/ranks/{steamid}/activity – moderation/community summary (staff-only, see activity())
 * POST   /api/ranks/{steamid}/notes  – add a staff note (staff-only)
 * DELETE /api/ranks/notes/{id}       – remove a staff note (staff-only)
 * PATCH  /api/ranks/{steamid}/points – edit points (requires admin.root)
 */
class RankController
{
    public function __construct(
        private readonly RankService $ranks,
        private readonly PlayerActivityService $activity,
        private readonly PlayerNoteService $notes,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $search = $request->query('search');
        $perPage = Api::perPage($request->query('per_page'));
        $sort = (string) $request->query('sort', 'value');
        $dir = (string) $request->query('dir', 'desc');

        $players = $this->ranks->leaderboard($search !== null ? (string) $search : null, $perPage, $sort, $dir);

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

    /**
     * GET /api/ranks/{steamid}/activity
     *
     * Punishment counts, VIP standing/history and a reports+appeals
     * timeline for one player. Unlike show() above, this is deliberately
     * NOT public: report reasons name who filed them, and reporter
     * identity is exactly what a moderation system should not hand to
     * every logged-in visitor who opens someone's profile - see the
     * admin.generic gate on this route (routes/api.php), the same tier
     * Report/Appeal already require to see everything.
     */
    public function activity(string $steamid): JsonResponse
    {
        try {
            return Api::success($this->activity->forSteamId($steamid));
        } catch (InvalidArgumentException) {
            return Api::error(Api::MSG_INVALID_INPUT, ['steamid' => ['invalid_steamid_format']], 422);
        }
    }

    public function storeNote(Request $request, string $steamid): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'note' => 'required|string|max:2000',
        ]);

        if ($validator->fails()) {
            return Api::error(Api::MSG_VALIDATION_FAILED, $validator->errors()->toArray(), 422);
        }

        try {
            $note = $this->notes->create($steamid, (string) $validator->validated()['note'], $request->user());
        } catch (InvalidArgumentException) {
            return Api::error(Api::MSG_INVALID_INPUT, ['steamid' => ['invalid_steamid_format']], 422);
        }

        return Api::success($note);
    }

    public function destroyNote(int $id): JsonResponse
    {
        try {
            $this->notes->delete($id);
        } catch (InvalidArgumentException $e) {
            if ($e->getMessage() === 'note_not_found') {
                return Api::notFound();
            }

            return Api::error(Api::MSG_INVALID_INPUT, [], 422);
        }

        return Api::success(null);
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