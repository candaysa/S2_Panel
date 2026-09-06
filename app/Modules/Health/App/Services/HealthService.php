<?php

namespace App\Modules\Health\App\Services;

use App\Models\User;
use App\Modules\Health\App\Events\HealthAlert;
use App\Modules\Health\App\Models\HealthCheck;
use App\Modules\Health\App\Models\PanelNotification;
use App\Modules\Rcon\App\Models\RconSetting;
use App\Modules\Server\App\Models\AdminServer;
use App\Support\Connection;
use App\Support\Rcon;

/**
 * Health monitoring (C16).
 *
 * Probes every configured database connection with "SELECT 1" and attempts
 * an RCON authentication against every server that has stored credentials.
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

        foreach (RconSetting::query()->orderBy('server_id')->get() as $setting) {
            $server = AdminServer::query()->find($setting->server_id);

            if ($server === null) {
                continue;
            }

            $host = trim((string) $server->server_ip);
            $port = (int) $server->server_port;

            if ($host === '' || $port < 1 || $port > 65535) {
                continue;
            }

            $ok = Rcon::authenticate(
                $host,
                $port,
                $setting->password,
                (float) config('health.rcon.timeout', 2.0),
            );

            $results[] = $this->record(
                'rcon:'.$setting->server_id,
                $ok,
                $ok ? null : 'rcon authentication failed',
            );
        }

        return $results;
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