<?php

namespace App\Modules\Rcon\App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Rcon\App\Services\RconService;
use App\Modules\Server\App\Models\AdminServer;
use App\Support\Api;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

/**
 * RCON endpoints (C11). Password management and every command require the
 * admin.rcon flag (the owner always passes via RequireFlag). Passwords are
 * stored encrypted in the panel database – never in plugin tables.
 *
 * Failures map to stable messages:
 *   - unknown server            -> 404 not_found
 *   - no password configured    -> 422 rcon_not_configured
 *   - unreachable/unauthorized  -> 502 rcon_unreachable
 */
class RconController extends Controller
{
    /**
     * RconService::kick/ban/slay build the console command by string
     * concatenation (`'sw_kick '.$target.' '.$reason`). The Source engine
     * console splits multiple commands on ";" and newlines, so an
     * unescaped target/reason is a command-injection vector: an admin
     * restricted to "kick" could smuggle e.g. "; sw_ban <other-admin> 0"
     * through the "target" field and get full console access. The raw
     * command() endpoint is exempt on purpose - it already grants full
     * console access by design.
     */
    private const NO_COMMAND_SEPARATORS = 'regex:/^[^;\r\n]+$/';

    public function __construct(private readonly RconService $rcon)
    {
    }

    public function saveSettings(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'server_id' => ['required', 'integer'],
            'password' => ['required', 'string', 'max:255'],
        ]);

        if (! AdminServer::query()->whereKey($validated['server_id'])->exists()) {
            return Api::notFound();
        }

        $this->rcon->save((int) $validated['server_id'], $validated['password'], $request->user());

        return Api::success(['server_id' => (int) $validated['server_id']]);
    }

    public function removeSettings(Request $request, string $serverId): JsonResponse
    {
        if (! ctype_digit($serverId)) {
            return Api::notFound();
        }

        $id = (int) $serverId;

        if (! AdminServer::query()->whereKey($id)->exists()) {
            return Api::notFound();
        }

        $this->rcon->remove($id, $request->user());

        return Api::success(['server_id' => $id]);
    }

    public function command(Request $request, string $serverId): JsonResponse
    {
        $validated = $request->validate([
            'command' => ['required', 'string', 'max:255'],
        ]);

        return $this->run(fn (): array => $this->rcon->command(
            (int) $serverId,
            $validated['command'],
            $request->user(),
        ), (int) $serverId);
    }

    public function kick(Request $request, string $serverId): JsonResponse
    {
        $validated = $request->validate([
            'target' => ['required', 'string', 'max:64', self::NO_COMMAND_SEPARATORS],
            'reason' => ['nullable', 'string', 'max:255', self::NO_COMMAND_SEPARATORS],
        ]);

        return $this->run(fn (): array => $this->rcon->kick(
            (int) $serverId,
            $validated['target'],
            $validated['reason'] ?? null,
            $request->user(),
        ), (int) $serverId);
    }

    public function ban(Request $request, string $serverId): JsonResponse
    {
        $validated = $request->validate([
            'target' => ['required', 'string', 'max:64', self::NO_COMMAND_SEPARATORS],
            'duration' => ['required', 'regex:/^\d+$/', 'max:16'],
            'reason' => ['nullable', 'string', 'max:255', self::NO_COMMAND_SEPARATORS],
        ]);

        return $this->run(fn (): array => $this->rcon->ban(
            (int) $serverId,
            $validated['target'],
            $validated['duration'],
            $validated['reason'] ?? null,
            $request->user(),
        ), (int) $serverId);
    }

    public function slay(Request $request, string $serverId): JsonResponse
    {
        $validated = $request->validate([
            'target' => ['required', 'string', 'max:64', self::NO_COMMAND_SEPARATORS],
        ]);

        return $this->run(fn (): array => $this->rcon->slay(
            (int) $serverId,
            $validated['target'],
            $request->user(),
        ), (int) $serverId);
    }

    /**
     * Map RconService results to a JSON response.
     *
     * @param  callable(): array{ok: bool, response: ?string}  $job
     */
    private function run(callable $job, int $serverId): JsonResponse
    {
        try {
            $result = $job();
        } catch (InvalidArgumentException $e) {
            if ($e->getMessage() === 'server_not_found') {
                return Api::notFound();
            }

            return Api::error(Api::MSG_INVALID_INPUT, ['rcon' => [$e->getMessage()]], 422);
        }

        if (! $result['ok']) {
            return Api::error('rcon_unreachable', [], 502);
        }

        return Api::success([
            'server_id' => $serverId,
            'response' => $result['response'],
        ]);
    }
}