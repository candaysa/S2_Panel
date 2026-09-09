<?php

namespace App\Modules\Health\App\Services;

use App\Models\User;
use App\Modules\Health\App\Events\HealthAlert;
use App\Modules\Health\App\Models\HealthCheck;
use App\Modules\Health\App\Models\PanelNotification;
use App\Modules\Rcon\App\Models\RconSetting;
use App\Modules\Server\App\Models\AdminServer;
use App\Modules\Server\App\Services\ServerService;
use App\Support\Connection;
use App\Support\Rcon;

/**
 * Health monitoring (C16).
 *
 * Probes every configured database connection with "SELECT 1", attempts an
 * RCON authentication against every server that has stored credentials, and
 * reports an online server that has none at all - the panel moderates
 * entirely through console commands, so "no password saved" and "the saved
 * password is wrong" are the same outage from an admin's point of view.
 * State changes are recorded into health_checks; a component flipping to
 * "down" creates a notification for the panel owner and dispatches the
 * HealthAlert event (listened to by the C17 webhook module).
 */
class HealthService
{
    /**
     * Probe all components and record state changes.
     *
     * @return list<array{component: string, status: string, message: ?string, changed: bool}>
     */
    public function check(): array
    {
        $results = [];

        foreach (config('health.databases', []) as $connection) {
            $healthy = Connection::isHealthy($connection);

            $results[] = $this->record(
                'db:'.$connection,
                $healthy,
                $healthy ? null : 'database connection failed',
            );
        }

        if (config('health.rcon.enabled', false)) {
            $results = array_merge($results, $this->checkRcon());
        }

        return $results;
    }

    /**
     * @return list<array{component: string, status: string, message: ?string, changed: bool}>
     */
    private function checkRcon(): array
    {
        $results = [];
        $servers = AdminServer::visible()->orderBy('id')->get();
        $live = app(ServerService::class)->liveFor($servers);

        // Every visible server, not just the ones with a password: a server
        // nobody ever configured is exactly the case that silently cannot
        // be moderated from the panel, and it used to be invisible here
        // because this loop only walked rcon_settings rows. A hidden server
        // is excluded outright - the owner marked it as not in use, so an
        // unconfigured RCON password on it is not an outage.
        foreach ($servers as $server) {
            $result = $this->verifyRcon($server, (int) $server->getKey(), $live);

            if ($result !== null) {
                $results[] = $result;
            }
        }

        return $results;
    }

    /**
     * Probe (and record) one server's RCON reachability - shared by the
     * scheduled sweep above and RconVerificationService's on-demand
     * recheck (a fix just saved to rcon_settings should not have to wait
     * for the next 5-minute tick to unblock the Bans/RCON/Admins/Groups
     * gate it feeds).
     *
     * @param  array<int, array<string, mixed>|null>  $live  ServerService::liveFor() result, reused rather than
     *                                                        re-probed A2S per server when the caller already has it
     * @return array{component: string, status: string, message: ?string, changed: bool}|null null for an offline
     *                                                                                          server - it cannot answer
     *                                                                                          an auth probe, and "your
     *                                                                                          game server is down" is
     *                                                                                          not what this check is for
     */
    public function verifyRcon(AdminServer $server, int $serverId, array $live): ?array
    {
        $host = trim((string) $server->server_ip);
        $port = (int) $server->server_port;

        if ($host === '' || $port < 1 || $port > 65535) {
            return null;
        }

        $setting = RconSetting::query()->where('server_id', $serverId)->first();

        if ($setting === null) {
            // "Online" is whatever ServerService already says it is - the
            // panel's single source of truth for liveness (A2S, cached),
            // rather than a second probe here that could disagree with
            // what the Servers page shows.
            if (($live[$serverId] ?? null) === null) {
                return null;
            }

            return $this->record('rcon:'.$serverId, false, 'no rcon password configured');
        }

        $ok = Rcon::authenticate(
            $host,
            $port,
            $setting->password,
            (float) config('health.rcon.timeout', 2.0),
        );

        return $this->record('rcon:'.$serverId, $ok, $ok ? null : 'rcon authentication failed');
    }

    /**
     * Record a state change (or the first observation). Owners are alerted
     * only when the component goes from "ok" to "down" (or is first
     * observed down) – steady-state outages never spam notifications.
     *
     * @return array{component: string, status: string, message: ?string, changed: bool}
     */
    private function record(string $component, bool $ok, ?string $message): array
    {
        $status = $ok ? 'ok' : 'down';

        $last = HealthCheck::query()
            ->where('component', $component)
            ->latest('checked_at')
            ->first();

        $changed = $last === null || $last->status !== $status;

        if ($changed) {
            HealthCheck::query()->create([
                'component' => $component,
                'status' => $status,
                'message' => $message,
                'checked_at' => now(),
            ]);
        } else {
            // Same status as last time: no new row (one row per state
            // change keeps this table bounded and the owner un-spammed),
            // but checked_at still has to move. It is read as "when was
            // this last verified" - by the Health page, and load-bearingly
            // by RconVerificationService, which refuses to trust an ok
            // older than its freshness window. Left untouched, the column
            // meant "when this state began", so a component that went ok
            // once and was never probed again looked freshly checked
            // forever, including after the scheduler stopped running.
            $last->forceFill(['message' => $message, 'checked_at' => now()])->save();
        }

        if ($changed && ! $ok) {
            $this->alertOwner($component, $message);
        }

        return [
            'component' => $component,
            'status' => $status,
            'message' => $message,
            'changed' => $changed,
        ];
    }

    private function alertOwner(string $component, ?string $message): void
    {
        $body = $component.' is down'.($message !== null ? " ({$message})" : '');

        foreach (User::query()->where('is_owner', true)->get() as $owner) {
            PanelNotification::query()->create([
                'user_id' => $owner->id,
                'type' => 'health.alert',
                'title' => 'Health alert',
                'body' => $body,
            ]);
        }

        HealthAlert::dispatch($component, 'down', $message);
    }
}