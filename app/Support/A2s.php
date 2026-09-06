<?php

namespace App\Support;

/**
 * Minimal A2S (Source Query) client — A2S_INFO + A2S_PLAYER.
 *
 * Implements the Steam Query protocol over UDP by hand (no external
 * dependency). Used by the Server and Health modules to read live
 * server state. All methods return null when the server is unreachable
 * or the response is malformed.
 */
final class A2s
{
    private const HEADER = "\xFF\xFF\xFF\xFF";

    private const CMD_INFO = "\x54"; // "I" request → response type \x49

    private const CMD_PLAYER = "\x55"; // response type \x44

    private const TYPE_INFO = "\x49";

    private const TYPE_PLAYER = "\x44";

    private const TYPE_CHALLENGE = "\x41";

    /**
     * Query A2S_INFO.
     *
     * @return array{
     *     name: string, map: string, folder: string, game: string,
     *     app_id: int, players: int, max_players: int, bots: int,
     *     server_type: string, environment: string, visibility: int,
     *     vac: bool, version: string, port?: int, steam_id?: int
     * }|null
     */
    public static function info(string $host, int $port = 27015, float $timeout = 2.0): ?array
    {
        $payload = "Source Engine Query\x00";
        $response = self::request($host, $port, self::CMD_INFO.$payload, $timeout);

        if ($response === null) {
            return null;
        }

        if (self::responseType($response) === self::TYPE_CHALLENGE) {
            $challenge = substr($response, 5, 4);
            $response = self::request($host, $port, self::CMD_INFO.$payload.$challenge, $timeout);

            if ($response === null || self::responseType($response) !== self::TYPE_INFO) {
                return null;
            }
        }

        $offset = 5;
        $info = [];
        $info['protocol'] = self::byte($response, $offset);
        $info['name'] = self::string($response, $offset);
        $info['map'] = self::string($response, $offset);
        $info['folder'] = self::string($response, $offset);
        $info['game'] = self::string($response, $offset);
        $info['app_id'] = self::int16($response, $offset);
        $info['players'] = self::byte($response, $offset);
        $info['max_players'] = self::byte($response, $offset);
        $info['bots'] = self::byte($response, $offset);
        $info['server_type'] = self::byte($response, $offset) === 'd' ? 'dedicated' : 'listen';
        $info['environment'] = self::byte($response, $offset);
        $info['visibility'] = self::byte($response, $offset);
        $info['vac'] = self::byte($response, $offset) === 1;
        $info['version'] = self::string($response, $offset);

        if ($offset < strlen($response)) {
            $edf = self::byte($response, $offset);

            if ($edf & 0x80) {
                $info['port'] = self::int16($response, $offset);
            }
            if ($edf & 0x40) {
                $info['steam_id'] = self::int64($response, $offset);
            }
            if ($edf & 0x20) {
                $info['tv_port'] = self::int16($response, $offset);
                $info['tv_name'] = self::string($response, $offset);
            }
            if ($edf & 0x10) {
                $info['keywords'] = self::string($response, $offset);
            }
            if ($edf & 0x08) {
                $info['game_id'] = self::int64($response, $offset);
            }
        }

        return $info;
    }

    /**
     * Query A2S_INFO for many servers at once.
     *
     * One socket per target, all written before anything is read, then
     * stream_select() waits on the whole set together. Cost is therefore one
     * timeout for the batch instead of one per server: the sequential version
     * took up to 12 x 2s on this panel's server list, most of it spent on
     * entries whose stored IP is 0.0.0.0 and can never answer.
     *
     * Steam replies to an unchallenged A2S_INFO with S2C_CHALLENGE, so this
     * runs two rounds - the second only for the servers that asked for one.
     *
     * @param  array<string|int, array{host: string, port: int}>  $targets
     * @return array<string|int, array<string, mixed>|null> same keys as $targets
     */
    public static function infoMany(array $targets, float $timeout = 2.0): array
    {
        $payload = "Source Engine Query\x00";
        $results = array_fill_keys(array_keys($targets), null);

        $pending = [];
        foreach ($targets as $key => $target) {
            $host = trim((string) ($target['host'] ?? ''));
            $port = (int) ($target['port'] ?? 0);

            if ($host === '' || $host === '0.0.0.0' || $port < 1 || $port > 65535) {
                continue;
            }

            $socket = @stream_socket_client("udp://{$host}:{$port}", $errno, $errstr, $timeout);

            if ($socket === false) {
                continue;
            }

            stream_set_blocking($socket, false);

            if (@fwrite($socket, self::HEADER.self::CMD_INFO.$payload) === false) {
                fclose($socket);

                continue;
            }

            $pending[$key] = $socket;
        }

        // Round 1, then round 2 for whoever answered with a challenge.
        $raw = self::collect($pending, $timeout);
        $retry = [];

        foreach ($raw as $key => $response) {
            if ($response !== null && self::responseType($response) === self::TYPE_CHALLENGE) {
                $challenge = substr($response, 5, 4);

                if (@fwrite($pending[$key], self::HEADER.self::CMD_INFO.$payload.$challenge) !== false) {
                    $retry[$key] = $pending[$key];
                }
            }
        }

        if ($retry !== []) {
            foreach (self::collect($retry, $timeout) as $key => $response) {
                $raw[$key] = $response;
            }
        }

        foreach ($pending as $socket) {
            @fclose($socket);
        }

        foreach ($raw as $key => $response) {
            if ($response === null || self::responseType($response) !== self::TYPE_INFO) {
                continue;
            }

            $results[$key] = self::parseInfo($response);
        }

        return $results;
    }

    /**
     * Wait on a set of non-blocking sockets until each has answered once or
     * the shared deadline passes.
     *
     * @param  array<string|int, resource>  $sockets
     * @return array<string|int, string|null>
     */
    private static function collect(array $sockets, float $timeout): array
    {
        $out = array_fill_keys(array_keys($sockets), null);
        $waiting = $sockets;
        $deadline = microtime(true) + $timeout;

        while ($waiting !== []) {
            $remaining = $deadline - microtime(true);

            if ($remaining <= 0) {
                break;
            }

            $read = array_values($waiting);
            $write = null;
            $except = null;

            $ready = @stream_select(
                $read,
                $write,
                $except,
                (int) $remaining,
                (int) (fmod($remaining, 1) * 1_000_000),
            );

            if ($ready === false || $ready === 0) {
                break;
            }

            foreach ($read as $socket) {
                $key = array_search($socket, $waiting, true);

                if ($key === false) {
                    continue;
                }

                $response = @fread($socket, 4096);
                unset($waiting[$key]);

                if ($response !== false && strlen($response) >= 5) {
                    $out[$key] = $response;
                }
            }
        }

        return $out;
    }

    /**
     * @return array<string, mixed>
     */
    private static function parseInfo(string $response): array
    {
        $offset = 5;
        $info = [];
        $info['protocol'] = self::byte($response, $offset);
        $info['name'] = self::string($response, $offset);
        $info['map'] = self::string($response, $offset);
        $info['folder'] = self::string($response, $offset);
        $info['game'] = self::string($response, $offset);
        $info['app_id'] = self::int16($response, $offset);
        $info['players'] = self::byte($response, $offset);
        $info['max_players'] = self::byte($response, $offset);
        $info['bots'] = self::byte($response, $offset);
        $info['server_type'] = self::byte($response, $offset) === 'd' ? 'dedicated' : 'listen';
        $info['environment'] = self::byte($response, $offset);
        $info['visibility'] = self::byte($response, $offset);
        $info['vac'] = self::byte($response, $offset) === 1;
        $info['version'] = self::string($response, $offset);

        return $info;
    }

    /**
     * Query A2S_PLAYER.
     *
     * @return array<int, array{index: int, name: string, score: int, duration: float}>|null
     */
    public static function players(string $host, int $port = 27015, float $timeout = 2.0): ?array
    {
        $challenge = "\x00\x00\x00\x00";
        $response = self::request($host, $port, self::CMD_PLAYER.$challenge, $timeout);

        if ($response === null) {
            return null;
        }

        if (self::responseType($response) === self::TYPE_CHALLENGE) {
            $challenge = substr($response, 5, 4);
            $response = self::request($host, $port, self::CMD_PLAYER.$challenge, $timeout);

            if ($response === null || self::responseType($response) !== self::TYPE_PLAYER) {
                return null;
            }
        }

        $offset = 5;
        $count = self::byte($response, $offset);
        $players = [];

        for ($i = 0; $i < $count; $i++) {
            $index = self::byte($response, $offset);
            $name = self::string($response, $offset);
            $score = self::int32($response, $offset);
            $duration = self::float($response, $offset);

            if ($offset > strlen($response)) {
                break;
            }

            $players[] = [
                'index' => $index,
                'name' => $name,
                'score' => $score,
                'duration' => $duration,
            ];
        }

        return $players;
    }

    /**
     * Send one UDP request and return the raw response body.
     */
    private static function request(string $host, int $port, string $payload, float $timeout): ?string
    {
        $socket = @stream_socket_client("udp://{$host}:{$port}", $errno, $errstr, $timeout);

        if ($socket === false) {
            return null;
        }

        stream_set_timeout($socket, (int) ceil($timeout));
        $sent = @fwrite($socket, self::HEADER.$payload);

        if ($sent === false) {
            fclose($socket);

            return null;
        }

        $response = @fread($socket, 4096);
        fclose($socket);

        if ($response === false || strlen($response) < 5) {
            return null;
        }

        return $response;
    }

    private static function responseType(string $response): string
    {
        return substr($response, 4, 1);
    }

    private static function byte(string $data, int &$offset): int
    {
        $value = ord($data[$offset] ?? "\x00");
        $offset++;

        return $value;
    }

    private static function string(string $data, int &$offset): string
    {
        $end = strpos($data, "\x00", $offset);

        if ($end === false) {
            $value = substr($data, $offset);
            $offset = strlen($data);

            return $value;
        }

        $value = substr($data, $offset, $end - $offset);
        $offset = $end + 1;

        return $value;
    }

    private static function int16(string $data, int &$offset): int
    {
        $value = unpack('v', substr($data, $offset, 2))[1];
        $offset += 2;

        return $value;
    }

    private static function int32(string $data, int &$offset): int
    {
        $value = unpack('l', substr($data, $offset, 4))[1];
        $offset += 4;

        return $value;
    }

    private static function int64(string $data, int &$offset): int
    {
        $value = unpack('P', substr($data, $offset, 8))[1];
        $offset += 8;

        return $value;
    }

    private static function float(string $data, int &$offset): float
    {
        $value = unpack('g', substr($data, $offset, 4))[1];
        $offset += 4;

        return $value;
    }
}