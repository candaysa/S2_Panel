<?php

namespace App\Modules\CheatCheck\App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\CheatCheck\App\Models\CheatScan;
use App\Modules\CheatCheck\App\Services\CheatCheckService;
use App\Support\Api;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;
use InvalidArgumentException;

/**
 * Panel-facing cheat check endpoints (C18). Staff only – the routes are
 * gated behind flag:admin.generic.
 */
class CheatCheckController extends Controller
{
    public function __construct(private readonly CheatCheckService $scans)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $scans = $this->scans->paginate(
            $request->query('status'),
            $request->query('search'),
            min((int) $request->query('per_page', 25), 100),
        );

        return Api::success($scans->items(), [
            'pagination' => [
                'total' => $scans->total(),
                'per_page' => $scans->perPage(),
                'current_page' => $scans->currentPage(),
                'last_page' => $scans->lastPage(),
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'player_name' => 'required|string|max:128',
            'steam_link' => 'required|url|max:512',
            'discord_id' => 'nullable|string|max:128',
        ]);

        if ($validator->fails()) {
            return Api::error(Api::MSG_VALIDATION_FAILED, $validator->errors()->toArray(), 422);
        }

        try {
            $result = $this->scans->open(
                Auth::user(),
                $validator->validated(),
                $this->baseUrl($request),
                $request->ip(),
            );
        } catch (InvalidArgumentException $e) {
            return Api::error($e->getMessage(), [], 429);
        }

        return Api::success($result['scan'], [
            'created' => true,
            'run_url' => $result['run_url'],
            'command' => $result['command'],
        ]);
    }

    public function show(string $id): JsonResponse
    {
        $scan = $this->find($id);

        if ($scan === null) {
            return Api::notFound();
        }

        return Api::success([
            'scan' => $scan,
            'findings' => $scan->parsed_findings,
        ]);
    }

    public function destroy(string $id): JsonResponse
    {
        $scan = $this->find($id);

        if ($scan === null) {
            return Api::notFound();
        }

        $this->scans->destroy($scan);

        return Api::success(['deleted' => true]);
    }

    private function find(string $id): ?CheatScan
    {
        return ctype_digit($id) ? CheatScan::query()->find((int) $id) : null;
    }

    /**
     * The link is handed to a player outside the panel, so it must carry the
     * public scheme even when TLS terminates at a proxy and Laravel sees
     * plain http on the socket.
     */
    private function baseUrl(Request $request): string
    {
        $scheme = (string) config('cheat_check.force_scheme', 'https');

        return $scheme.'://'.$request->getHost();
    }
}
