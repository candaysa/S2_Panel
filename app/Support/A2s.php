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