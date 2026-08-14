<?php

namespace App\Modules\Stats\App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Stats\App\Services\StatsService;
use App\Support\Api;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

/**
 * Stats endpoints (C12): dashboard totals, player profile (rank_base) and
 * per-server A2S history. Any authenticated session may read them.
 */
class StatsController extends Controller
{
    public function __construct(private readonly StatsService $stats)
    {
    }

    public function dashboard(): JsonResponse
    {
        return Api::success($this->stats->dashboard());
    }

    public function player(string $steamid): JsonResponse
    {
        try {
            $profile = $this->stats->playerProfile($steamid);
        } catch (InvalidArgumentException $e) {
            return Api::error(Api::MSG_INVALID_INPUT, ['steamid' => [$e->getMessage()]], 422);
        }

        if ($profile === null) {
            return Api::notFound();
        }

        return Api::success($profile);
    }

    public function history(Request $request, string $id): JsonResponse
    {
        if (! ctype_digit($id)) {
            return Api::notFound();
        }

        $hours = min(max((int) $request->query('hours', 24), 1), 24 * 30);

        return Api::success([
            'server_id' => (int) $id,
            'samples' => $this->stats->serverHistory((int) $id, $hours),
        ]);
    }
}