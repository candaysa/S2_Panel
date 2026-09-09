<?php

namespace App\Modules\Rcon\App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Audit\App\Models\PanelLog;
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
 * Password management is only ever reached from Settings > Servers, which
 * is owner-only on top of this gate - see routes/web.php. This controller
 * itself still allows any admin.rcon flag holder through, matching the
 * command endpoints below.
 *
 * Failures map to stable messages:
 *   - unknown server            -> 404 not_found
 *   - no password configured    -> 422 rcon_not_configured
 *   - unreachable/unauthorized  -> 502 rcon_unreachable
 */
class RconController extends Controller
{
    /**
     * RconService builds each console command by string concatenation
     * (`'sw_kick '.$target.' '.$reason`). The Source engine console splits
     * multiple commands on ";" and newlines, so an unescaped target/reason
     * is a command-injection vector: an admin restricted to "kick" could
     * smuggle e.g. "; sw_ban <other-admin> 0" through the "target" field
     * and get full console access. The raw command() endpoint is exempt on
     * purpose - it already grants full console access by design.
     *
     * The double quote is blocked for the same reason: those arguments are
     * now quoted (RconService::arg(), so names with spaces reach the plugin
     * as one argument), and a quote in the value would close that quoting
     * and put everything after it back on the console's own argument list.
     */
    private const NO_COMMAND_SEPARATORS = 'regex:/^[^;\r\n"]+$/';

    public function __construct(private readonly RconService $rcon)
    {
    }

    /**
     * GET /api/rcon/settings - which servers have an RCON password
     * configured. Never the passwords themselves - Settings > Servers uses
     * this to show a configured/not-configured state per row.
     */
    public function listSettings(): JsonResponse
    {
        return Api::success(['server_ids' => $this->rcon->configuredServerIds()]);
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

    /**
     * GET /api/rcon/{serverId}/history - the console's own audit trail
     * (RconService::command() already logs every one via
     * 'rcon.command.executed'), so this is a read of existing data, not a
     * new log. Response text is not stored (only the panel_logs 'ok'
     * boolean is - the actual RCON reply was ephemeral, returned once to
     * whoever ran it) so a historical row can only show whether it
     * succeeded, not what came back.
     */
    public function history(string $serverId): JsonResponse
    {
        if (! ctype_digit($serverId)) {
            return Api::notFound();
        }

        if (! AdminServer::query()->whereKey((int) $serverId)->exists()) {
            return Api::notFound();
        }

        $entries = PanelLog::query()
            ->where('action', 'rcon.command.executed')
            ->where('target_type', 'server')
            ->where('target_id', $serverId)
            ->latest('id')
            ->limit(20)
            ->get(['details', 'actor_name', 'created_at'])
            ->map(fn (PanelLog $log): array => [
                'command' => $log->details['command'] ?? '',
                'ok' => (bool) ($log->details['ok'] ?? false),
                'actor_name' => $log->actor_name,
                'created_at' => $log->created_at,
            ])
            ->reverse()
            ->values();

        return Api::success($entries);
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
            // -1 is the plugins' own "permanent" convention (0 is a real,
            // near-instant duration, not permanent - see RconService).
            'duration' => ['required', 'regex:/^-1$|^\d+$/', 'max:16'],
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

    public function mute(Request $request, string $serverId): JsonResponse
    {
        $validated = $request->validate([
            'target' => ['required', 'string', 'max:64', self::NO_COMMAND_SEPARATORS],
            // -1 is the plugins' own "permanent" convention (0 is a real,
            // near-instant duration, not permanent - see RconService).
            'duration' => ['required', 'regex:/^-1$|^\d+$/', 'max:16'],
            'reason' => ['nullable', 'string', 'max:255', self::NO_COMMAND_SEPARATORS],
        ]);

        return $this->run(fn (): array => $this->rcon->mute(
            (int) $serverId,
            $validated['target'],
            $validated['duration'],
            $validated['reason'] ?? null,
            $request->user(),
        ), (int) $serverId);
    }

    public function gag(Request $request, string $serverId): JsonResponse
    {
        $validated = $request->validate([
            'target' => ['required', 'string', 'max:64', self::NO_COMMAND_SEPARATORS],
            // -1 is the plugins' own "permanent" convention (0 is a real,
            // near-instant duration, not permanent - see RconService).
            'duration' => ['required', 'regex:/^-1$|^\d+$/', 'max:16'],
            'reason' => ['nullable', 'string', 'max:255', self::NO_COMMAND_SEPARATORS],
        ]);

        return $this->run(fn (): array => $this->rcon->gag(
            (int) $serverId,
            $validated['target'],
            $validated['duration'],
            $validated['reason'] ?? null,
            $request->user(),
        ), (int) $serverId);
    }

    public function warn(Request $request, string $serverId): JsonResponse
    {
        $validated = $request->validate([
            'target' => ['required', 'string', 'max:64', self::NO_COMMAND_SEPARATORS],
            'reason' => ['nullable', 'string', 'max:255', self::NO_COMMAND_SEPARATORS],
        ]);

        return $this->run(fn (): array => $this->rcon->warn(
            (int) $serverId,
            $validated['target'],
            $validated['reason'] ?? null,
            $request->user(),
        ), (int) $serverId);
    }

    /**
     * The lift actions (unban/unmute/ungag/unwarn). All take just a target
     * - a SteamID for unban, since the player is by definition not on the
     * server to be matched by name.
     */
    public function lift(Request $request, string $serverId, string $action): JsonResponse
    {
        if (! in_array($action, ['unban', 'unmute', 'ungag', 'unwarn'], true)) {
            return Api::notFound();
        }

        $validated = $request->validate([
            'target' => ['required', 'string', 'max:64', self::NO_COMMAND_SEPARATORS],
        ]);

        return $this->run(fn (): array => $this->rcon->{$action}(
            (int) $serverId,
            $validated['target'],
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