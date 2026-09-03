<?php

namespace App\Modules\ServerDetails\App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Server\App\Models\AdminServer;
use App\Modules\ServerDetails\App\Services\ServerDetailsService;
use App\Support\Api;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Per-server history + live player list (C21). Public reads, same as the
 * Server module's own list/show - none of this is more sensitive than what
 * the dashboard already shows every visitor.
 */
class ServerDetailsController extends Controller
{
    private const RANGES = ['1h', '12h', '1w', '12m'];

    public function __construct(private readonly ServerDetailsService $details)
    {
    }

    public function stats(Request $request, string $id): JsonResponse
    {
        if (! ctype_digit($id)) {
            return Api::notFound();
        }

        if (AdminServer::query()->whereKey((int) $id)->doesntExist()) {
            return Api::notFound();
        }

        $range = (string) $request->query('range', '1h');

        if (! in_array($range, self::RANGES, true)) {
            $range = '1h';
        }

        return Api::success($this->details->statsFor((int) $id, $range), ['range' => $range]);
    }

    public function players(string $id): JsonResponse
    {
        if (! ctype_digit($id)) {
            return Api::notFound();
        }

        $server = AdminServer::query()->find((int) $id);

        if ($server === null) {
            return Api::notFound();
        }

        $players = $this->details->livePlayers($server);

        return Api::success($players ?? [], ['online' => $players !== null]);
    }
}
