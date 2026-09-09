<?php

namespace App\Modules\Health\App\Services;

use App\Modules\Health\App\Models\HealthCheck;
use App\Modules\Server\App\Models\AdminServer;
use App\Modules\Server\App\Services\ServerService;

/**
 * The RCON-verified gate (RequireRconVerified middleware): whether every
 * currently online server's RCON credentials are known-good.
 *
 * The panel moderates entirely through console commands - Bans, RCON,
 * Admins and Groups all end up sending one - so a server whose password is
 * missing or wrong is not a degraded feature, it is a moderation action
 * that silently does nothing (or worse, is attempted against the wrong
 * server). This is deliberately fail-closed: an offline server is excluded
 * (it cannot answer a probe, and "your game server is down" is a different
 * problem), and so is a server the owner has hidden (see AdminServer::
 * visible() - hidden means "not in use on this panel", and a server that
 * is not in use cannot be the thing a moderation action was aimed at). Any
 * other online server with no verified RCON blocks all four sections for
 * everyone until it is fixed - not just for whoever happens to be acting
 * on it.
 *
 * Reads the periodic health_checks record (health:check, every 5 minutes)
 * for the common case - a live probe on every request to four separate
 * page/API groups would turn one flaky game server's RCON port into
 * multi-second latency on the whole panel. The one exception is a server
 * that record currently calls down (or has never been checked at all):
 * that server alone gets a fresh, short-timeout recheck right here, so an
 * owner who just corrected a password sees the gate lift immediately
 * rather than waiting for the next scheduled tick.
 */
class RconVerificationService
{
    public function __construct(private readonly HealthService $health)
    {
    }

    /**
     * @return array{ok: bool, problems: array<int, array{server_id: int, label: string, reason: string}>}
     */
    public function status(): array
    {
        $servers = AdminServer::visible()->orderBy('id')->get();
        $live = app(ServerService::class)->liveFor($servers);
        $problems = [];

        foreach ($servers as $server) {
            $serverId = (int) $server->getKey();

            if (($live[$serverId] ?? null) === null) {
                continue;
            }

            $component = 'rcon:'.$serverId;
            $cached = HealthCheck::query()->where('component', $component)->latest('checked_at')->first();

            if ($cached !== null && $cached->status === 'ok' && $this->isFresh($cached)) {
                // Trust a *recent* ok - no need to re-probe on the happy path.
                continue;
            }

            // No row yet, or the last known state was down: give this one
            // server a fresh answer right now rather than reporting a
            // possibly-stale problem (or none at all for a server added
            // since the last scheduled tick).
            $result = $this->health->verifyRcon($server, $serverId, $live);
            $status = $result['status'] ?? null;
            $message = $result['message'] ?? $cached?->message;

            if ($status !== 'ok') {
                $problems[] = [
                    'server_id' => $serverId,
                    'label' => trim((string) $server->server_ip).':'.(string) $server->server_port,
                    'reason' => $message ?? 'rcon not yet verified',
                ];
            }
        }

        return ['ok' => $problems === [], 'problems' => $problems];
    }

    /**
     * Whether a recorded ok is recent enough to stand in for a live probe.
     *
     * Without this the gate was only fail-closed while health:check kept
     * running: one recorded ok was trusted forever, so a panel whose
     * scheduler had stopped (or whose server had since had its RCON
     * password changed) kept letting every request straight through on the
     * strength of a months-old row. Past the window the server falls back
     * to the on-demand probe below, which is the same path an
     * as-yet-unchecked server takes - a dead scheduler now degrades to
     * "verify on demand", never to "assume fine".
     *
     * Default is three times the five-minute schedule, so an ordinary
     * missed tick does not cost a probe.
     */
    private function isFresh(HealthCheck $check): bool
    {
        $checkedAt = $check->checked_at;

        if ($checkedAt === null) {
            return false;
        }

        $maxAge = (int) config('health.rcon.trust_minutes', 15);

        return $checkedAt->greaterThan(now()->subMinutes($maxAge));
    }
}
