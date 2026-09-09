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
 * setting) and live in one place - the COMMANDS map below, which is the
 * only thing that needs touching when a plugin renames or gains a command.
 * An action a plugin genuinely cannot do from the console is null there and
 * fails with a clear error, rather than sending a command the server will
 * silently drop.
 */
class RconService
{
    public function __construct(
        private readonly AuditService $audit,
        private readonly SettingService $settings,
    ) {
    }

    /**
     * Console command per action, per admin plugin.
     *
     * CS2_Admin registers every command twice - "<name>" and "sw_<name>" -
     * and ignores a console/RCON invocation that does not use the sw_ alias
     * (its own command handler returns early when the call is not from a
     * player and the name lacks that prefix), which is why every entry
     * below is prefixed. Names and argument order are its documented set
     * (!ban <target> <time> [reason], !warn [target] [reason],
     * !unban <steamid/ip>, !unmute/!ungag/!unwarn <target>).
     *
     * The official swiftlys2-plugins/admins plugin uses unprefixed names
     * with the same <target> <duration> [reason] shape, and has no warn
     * concept at all - null here means "this plugin cannot do that", which
     * surfaces as a clear error instead of a command the server silently
     * drops.
     *
     * @var array<string, array<string, ?string>>
     */
    private const COMMANDS = [
        'cs2_admin' => [
            'ban' => 'sw_ban',
            'unban' => 'sw_unban',
            'kick' => 'sw_kick',
            'mute' => 'sw_mute',
            'unmute' => 'sw_unmute',
            'gag' => 'sw_gag',
            'ungag' => 'sw_ungag',
            'warn' => 'sw_warn',
            'unwarn' => 'sw_unwarn',
            'slay' => 'sw_slay',
        ],
        'swiftly_admins' => [
            'ban' => 'ban',
            'unban' => 'unban',
            // Admins.SuperCommands - refused unless an actual in-game
            // player sent them, console/RCON included.
            'kick' => null,
            'mute' => 'mute',
            'unmute' => 'unmute',
            'gag' => 'gag',
            'ungag' => 'ungag',
            // No warn command exists in this plugin.
            'warn' => null,
            'unwarn' => null,
            'slay' => null,
        ],
    ];

    private function usesSwiftlyAdmins(): bool
    {
        return $this->settings->get('admin_plugin', 'cs2_admin') === 'swiftly_admins';
    }

    /**
     * The console command this install's admin plugin uses for $action.
     *
     * @throws InvalidArgumentException when the configured plugin has no
     *                                  console-reachable command for it
     */
    private function commandName(string $action): string
    {
        $plugin = $this->usesSwiftlyAdmins() ? 'swiftly_admins' : 'cs2_admin';
        $name = self::COMMANDS[$plugin][$action] ?? null;

        if ($name === null) {
            throw new InvalidArgumentException($action.'_unsupported_for_plugin');
        }

        return $name;
    }

    /**
     * Which servers currently have an RCON password configured - never the
     * passwords themselves. Settings > Servers uses this to show a
     * configured/not-configured state per row without a second request per
     * server.
     *
     * @return array<int, int>
     */
    public function configuredServerIds(): array
    {
        return RconSetting::query()->pluck('server_id')->map(fn ($id): int => (int) $id)->values()->all();
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
     * Quote one console argument.
     *
     * Player names routinely contain spaces ("SKINS UzayLegit"), and an
     * unquoted `sw_mute SKINS UzayLegit 1440` makes the plugin read
     * "UzayLegit" as the duration - the punishment lands on the wrong
     * argument shape, or silently not at all. The predecessor panel quotes
     * every argument for exactly this reason
     * (CS2_Panel ServerController: sw_ban "name" 1440 "reason").
     *
     * Safe because RconController rejects a target/reason containing a
     * double quote alongside ; CR and LF (NO_COMMAND_SEPARATORS) - without
     * that rule quoting would just move the injection point inside the
     * quotes instead of closing it.
     */
    private function arg(string $value): string
    {
        return '"'.$value.'"';
    }

    /**
     * One punishment/lift, sent as the configured plugin's own console
     * command. Every argument is quoted (see arg()) and the resulting
     * command goes through command(), so it is audit-logged like any other.
     *
     * @param  array<int, string>  $args  target first, then the rest in the
     *                                    plugin's documented order
     */
    private function action(int $serverId, string $action, array $args, User $actor, ?string $detail = null): array
    {
        $parts = [$this->commandName($action)];

        foreach ($args as $arg) {
            $parts[] = $arg;
        }

        $result = $this->command($serverId, implode(' ', $parts), $actor);

        RconActionPerformed::dispatch($serverId, $action, $args[0] ?? '', $detail, $result['ok']);

        return $result;
    }

    /**
     * Kick a player. Not available via RCON at all under the official
     * swiftlys2-plugins/admins plugin - see COMMANDS.
     */
    public function kick(int $serverId, string $target, ?string $reason, User $actor): array
    {
        $args = [$this->arg($target)];

        if ($reason !== null && $reason !== '') {
            $args[] = $this->arg($reason);
        }

        return $this->action($serverId, 'kick', $args, $actor, $reason);
    }

    /**
     * Ban a player. Duration is passed through verbatim, e.g. "1440"
     * (minutes) or "-1" (permanent) - the plugin parses it.
     *
     * -1 rather than 0 for permanent because 0 was observed on this
     * install NOT to produce a permanent punishment. Reading CS2_Admin's
     * source, a duration that is not > 0 stores a null expiry, which makes
     * -1 permanent there whichever way the 0 case actually behaves - so -1
     * is the safe value under both readings, and that is why it is the one
     * the panel sends. What 0 means exactly has not been pinned to a
     * plugin revision; treat it as unspecified rather than as a second way
     * to say permanent.
     */
    public function ban(int $serverId, string $target, string $duration, ?string $reason, User $actor): array
    {
        return $this->action($serverId, 'ban', $this->targetDurationReason($target, $duration, $reason), $actor, $duration);
    }

    /**
     * Mute a player's voice chat.
     */
    public function mute(int $serverId, string $target, string $duration, ?string $reason, User $actor): array
    {
        return $this->action($serverId, 'mute', $this->targetDurationReason($target, $duration, $reason), $actor, $duration);
    }

    /**
     * Gag a player's text chat.
     */
    public function gag(int $serverId, string $target, string $duration, ?string $reason, User $actor): array
    {
        return $this->action($serverId, 'gag', $this->targetDurationReason($target, $duration, $reason), $actor, $duration);
    }

    /**
     * Warn a player. No duration - the plugin owns how long a warning
     * counts for. CS2_Admin only; the official plugin has no warn command.
     */
    public function warn(int $serverId, string $target, ?string $reason, User $actor): array
    {
        $args = [$this->arg($target)];

        if ($reason !== null && $reason !== '') {
            $args[] = $this->arg($reason);
        }

        return $this->action($serverId, 'warn', $args, $actor, $reason);
    }

    /**
     * Lift a ban. Takes a SteamID (or IP) rather than a name - the player
     * is by definition not on the server to be matched by one.
     */
    public function unban(int $serverId, string $target, User $actor): array
    {
        return $this->action($serverId, 'unban', [$this->arg($target)], $actor);
    }

    /**
     * Lift a voice mute.
     */
    public function unmute(int $serverId, string $target, User $actor): array
    {
        return $this->action($serverId, 'unmute', [$this->arg($target)], $actor);
    }

    /**
     * Lift a text-chat gag.
     */
    public function ungag(int $serverId, string $target, User $actor): array
    {
        return $this->action($serverId, 'ungag', [$this->arg($target)], $actor);
    }

    /**
     * Remove a warning. CS2_Admin only.
     */
    public function unwarn(int $serverId, string $target, User $actor): array
    {
        return $this->action($serverId, 'unwarn', [$this->arg($target)], $actor);
    }

    /**
     * Slay a player. Not available via RCON under the official plugin.
     */
    public function slay(int $serverId, string $target, User $actor): array
    {
        return $this->action($serverId, 'slay', [$this->arg($target)], $actor);
    }

    /**
     * The <target> <duration> [reason] argument shape every timed
     * punishment shares in both plugins.
     *
     * @return array<int, string>
     */
    private function targetDurationReason(string $target, string $duration, ?string $reason): array
    {
        $args = [$this->arg($target), $duration];

        if ($reason !== null && $reason !== '') {
            $args[] = $this->arg($reason);
        }

        return $args;
    }
}
