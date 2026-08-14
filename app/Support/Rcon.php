<?php

namespace App\Support;

/**
 * Minimal Source RCON client (Valve's Source RCON protocol over TCP).
 *
 * Implements the protocol by hand with stream sockets (no external
 * dependency, no ext-sockets requirement) – the same approach as A2s.
 * Used by the Rcon module to run game-server console commands.
 *
 * Flow per call: connect -> AUTH -> EXECCOMMAND -> read response(s).
 * Returns null whenever the server is unreachable, authentication fails
 * or the response cannot be parsed (fail-closed – the caller reports the
 * server as unreachable rather than guessing).
 */
final class Rcon
{
    private const PACKET_MAX_BODY = 4096;

    private const TYPE_AUTH = 3;

    private const TYPE_EXEC_COMMAND = 2;

    private const TYPE_AUTH_RESPONSE = 2;

    private const TYPE_RESPONSE_VALUE = 0;

    private const AUTH_FAIL_ID = 0xFFFFFFFF;

    /**
     * Perform the authentication handshake only.
     *
     * Used by the Health module as a liveness probe for game servers:
     * true only when the server answers the AUTH request with a matching
     * id (wrong password / unreachable server both return false).
     */
    public static function authenticate(string $host, int $port, string $password, float $timeout = 2.0): bool
    {
        $socket = @stream_socket_client("tcp://{$host}:{$port}", $errno, $errstr, $timeout);

        if ($socket === false) {
            return false;
        }

        stream_set_timeout($socket, (int) ceil($timeout));

        $id = random_int(1, 0x7FFFFFFE);

        if (! self::write($socket, $id, self::TYPE_AUTH, $password)) {
            fclose($socket);

            return false;
        }

        while (true) {
            $packet = self::read($socket);

            if ($packet === null) {
                fclose($socket);

                return false;
            }

            if ($packet['type'] !== self::TYPE_AUTH_RESPONSE) {
                continue;
            }

            if ($packet['id'] === self::AUTH_FAIL_ID) {
                fclose($socket);

                return false;
            }

            if ($packet['id'] !== $id) {
                continue;
            }

            fclose($socket);

            return true;
        }
    }

    /**
     * Authenticate and run one server console command.
     *
     * @return string|null The concatenated response body, or null on
     *                     connection/auth/parse failure.
     */
    public static function execute(string $host, int $port, string $password, string $command, float $timeout = 2.0): ?string
    {
        $socket = @stream_socket_client("tcp://{$host}:{$port}", $errno, $errstr, $timeout);

        if ($socket === false) {
            return null;
        }

        stream_set_timeout($socket, (int) ceil($timeout));

        $id = random_int(1, 0x7FFFFFFE);

        if (! self::write($socket, $id, self::TYPE_AUTH, $password)) {
            fclose($socket);

            return null;
        }

        // Wait for the AUTH_RESPONSE (type 2). Some servers send an empty
        // RESPONSE_VALUE packet first – skip those until auth is answered.
        $authed = false;

        while (true) {
            $packet = self::read($socket);

            if ($packet === null) {
                fclose($socket);

                return null;
            }

            if ($packet['type'] !== self::TYPE_AUTH_RESPONSE) {
                continue;
            }

            if ($packet['id'] === self::AUTH_FAIL_ID) {
                fclose($socket);

                return null;
            }

            if ($packet['id'] !== $id) {
                continue;
            }

            $authed = true;
            break;
        }

        if (! $authed) {
            fclose($socket);

            return null;
        }

        $cmdId = $id + 1;

        if (! self::write($socket, $cmdId, self::TYPE_EXEC_COMMAND, $command)) {
            fclose($socket);

            return null;
        }

        // Long responses are split into 4096-byte chunks; the final chunk
        // is shorter, which is how the end of the response is detected.
        $response = '';

        while (true) {
            $packet = self::read($socket);

            if ($packet === null) {
                break;
            }

            if ($packet['id'] !== $cmdId || $packet['type'] !== self::TYPE_RESPONSE_VALUE) {
                continue;
            }

            $response .= $packet['body'];

            if (strlen($packet['body']) < self::PACKET_MAX_BODY) {
                break;
            }
        }

        fclose($socket);

        return $response;
    }

    /**
     * @param  resource  $socket
     */
    private static function write($socket, int $id, int $type, string $body): bool
    {
        $payload = pack('VV', $id, $type).$body."\x00\x00";
        $packet = pack('V', strlen($payload)).$payload;
        $total = strlen($packet);
        $written = 0;

        while ($written < $total) {
            $sent = @fwrite($socket, substr($packet, $written));

            if ($sent === false || $sent === 0) {
                return false;
            }

            $written += $sent;
        }

        return true;
    }

    /**
     * @param  resource  $socket
     *
     * @return array{id: int, type: int, body: string}|null
     */
    private static function read($socket): ?array
    {
        $sizeBytes = self::readBytes($socket, 4);

        if ($sizeBytes === null) {
            return null;
        }

        $size = unpack('V', $sizeBytes)[1];

        if ($size < 10) {
            return null;
        }

        $payload = self::readBytes($socket, $size);

        if ($payload === null) {
            return null;
        }

        return [
            'id' => unpack('V', substr($payload, 0, 4))[1],
            'type' => unpack('V', substr($payload, 4, 4))[1],
            'body' => substr($payload, 8, $size - 10),
        ];
    }

    /**
     * @param  resource  $socket
     */
    private static function readBytes($socket, int $count): ?string
    {
        $data = '';

        while (strlen($data) < $count) {
            $chunk = @fread($socket, $count - strlen($data));

            if ($chunk === false || $chunk === '') {
                return null;
            }

            $data .= $chunk;
        }

        return $data;
    }
}
