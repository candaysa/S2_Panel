<?php

namespace App\Modules\Rcon\App\Services;

use App\Models\User;
use App\Modules\Audit\App\Services\AuditService;
use App\Modules\Rcon\App\Events\RconActionPerformed;
use App\Modules\Rcon\App\Models\RconSetting;
use App\Modules\Server\App\Models\AdminServer;
use App\Modules\Settings\App\Services\SettingService;
use App\Support\Rcon;
use InvalidArgumentException;

/**
 * RCON operations (C11).
 *
 * Credentials live in the panel database (rcon_settings, encrypted) and
 * are never mirrored into plugin tables. Every call opens a fresh TCP
 * connection to the game server (stateless client) and every execution is
 * audit-logged. A server that does not answer (or rejects the password)
 * is reported as unreachable – the panel never guesses.
 *
 * Console command names are plugin-specific (see the `admin_plugin`
 * setting) - CS2_Admin's are prefixed `sw_`, the official
 * swiftlys2-plugins/admins plugin's are not, and its kick/slay commands
 * (Admins.SuperCommands, unlike its ban command) reject anything not sent
 * by an actual in-game player, console/RCON included - confirmed by reading
 * that plugin's source, unverified against a live instance. Both are
 * refused here with a clear error instead of silently sending a command the
 * target server will just reject.
 */
class RconService
{
    public function __construct(
        private readonly AuditService $audit,
        private readonly SettingService $settings,
    ) {
    }

    private function usesSwiftlyAdmins(): bool
    {
        return $this->settings->get('admin_plugin', 'cs2_admin') === 'swiftly_admins';
    }

    /**
     * Store (or replace) the RCON password for a server.
     */
    public function save(int $serverId, string $password, User $actor): RconSetting
    {
        $setting = RconSetting::query()->updateOrCreate(
            ['server_id' => $serverId],
            ['password' => $password],
        );

        $this->audit->log('rcon.settings.saved', 'server', (string) $serverId, [
            'server_id' => $serverId,
        ]);

        return $setting;
    }

    /**
     * Forget the RCON password for a server.
     */
    public function remove(int $serverId, User $actor): void
    {
        RconSetting::query()->where('server_id', $serverId)->delete();

        $this->audit->log('rcon.settings.removed', 'server', (string) $serverId, [
            'server_id' => $serverId,
        ]);
    }

    public function get(int $serverId): ?RconSetting
    {
        return RconSetting::query()->where('server_id', $serverId)->first();
    }

    /**
     * Run one raw server console command.
     *
     * @return array{ok: bool, response: ?string} ok=false when the server
     *                                            is unreachable or the
     *                                            password is rejected.
     *
     * @throws InvalidArgumentException when the server is unknown or no
     *                                  password is configured.
     */
    public function command(int $serverId, string $command, User $actor): array
    {
        $server = AdminServer::query()->find($serverId);

        if ($server === null) {
            throw new InvalidArgumentException('server_not_found');
        }

        $setting = $this->get($serverId);

        if ($setting === null) {
            throw new InvalidArgumentException('rcon_not_configured');
        }

        $host = trim((string) $server->server_ip);
        $port = (int) $server->server_port;

        if ($host === '' || $port < 1 || $port > 65535) {
            throw new InvalidArgumentException('server_not_found');
        }

        $response = Rcon::execute(
            $host,
            $port,
            $setting->password,
            $command,
            (float) config('rcon.timeout', 2.0),
        );

        $this->audit->log('rcon.command.executed', 'server', (string) $serverId, [
            'server_id' => $serverId,
            'command' => $command,
            'ok' => $response !== null,
        ]);

        return ['ok' => $response !== null, 'response' => $response];
    }

    /**
     * Kick a player (sw_kick). Not available via RCON at all when the
     * official swiftlys2-plugins/admins plugin is configured - see class
     * docblock.
     */
    public function kick(int $serverId, string $target, ?string $reason, User $actor): array
    {
        if ($this->usesSwiftlyAdmins()) {
            throw new InvalidArgumentException('kick_unsupported_for_plugin');
        }

        $result = $this->command(
            $serverId,
            'sw_kick '.$target.($reason !== null && $reason !== '' ? ' '.$reason : ''),
            $actor,
        );

        RconActionPerformed::dispatch($serverId, 'kick', $target, $reason, $result['ok']);

        return $result;
    }

    /**
     * Ban a player (sw_ban, or `ban` for the official plugin - its command
     * takes the same <target> <duration> <reason> shape, unverified against
     * a live instance). Duration is passed through verbatim, e.g. "1440"
     * (minutes) or "0" (permanent) – the plugin parses it.
     */
    public function ban(int $serverId, string $target, string $duration, ?string $reason, User $actor): array
    {
        $prefix = $this->usesSwiftlyAdmins() ? 'ban ' : 'sw_ban ';

        $result = $this->command(
            $serverId,
            $prefix.$target.' '.$duration.($reason !== null && $reason !== '' ? ' '.$reason : ''),
            $actor,
        );

        RconActionPerformed::dispatch($serverId, 'ban', $target, $duration, $result['ok']);

        return $result;
    }

    /**
     * Slay a player (sw_slay). Not available via RCON at all when the
     * official swiftlys2-plugins/admins plugin is configured - see class
     * docblock.
     */
    public function slay(int $serverId, string $target, User $actor): array
    {
        if ($this->usesSwiftlyAdmins()) {
            throw new InvalidArgumentException('slay_unsupported_for_plugin');
        }

        $result = $this->command($serverId, 'sw_slay '.$target, $actor);

        RconActionPerformed::dispatch($serverId, 'slay', $target, null, $result['ok']);

        return $result;
    }
}