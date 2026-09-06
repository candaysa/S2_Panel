<?php

/**
 * Fake Source RCON server for tests (C11).
 *
 * Runs as a separate process via proc_open. Listens on 127.0.0.1, prints
 * the chosen port to stdout as "PORT <n>", then serves ONE client:
 * performs the AUTH handshake (accepts only the known password), logs
 * every received EXECCOMMAND body to <logfile> and answers with "OK".
 *
 * Usage: php fake_rcon_server.php <password> <logfile>
 */

$password = $argv[1] ?? 'secret';
$logfile = $argv[2] ?? sys_get_temp_dir().'/fake_rcon.log';

$server = @stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);

if ($server === false) {
    fwrite(STDERR, "bind failed: {$errstr}\n");
    exit(1);
}

$name = stream_socket_get_name($server, false);
$port = (int) substr($name, strrpos($name, ':') + 1);
fwrite(STDOUT, "PORT {$port}\n");
fflush(STDOUT);

$conn = @stream_socket_accept($server, 10);

if ($conn === false) {
    fclose($server);
    exit(2);
}

stream_set_timeout($conn, 5);

$readPacket = function ($conn): ?array {
    $sizeBytes = fread($conn, 4);

    if ($sizeBytes === false || strlen($sizeBytes) !== 4) {
        return null;
    }

    $size = unpack('V', $sizeBytes)[1];

    if ($size < 10) {
        return null;
    }

    $payload = '';

    while (strlen($payload) < $size) {
        $chunk = fread($conn, $size - strlen($payload));

        if ($chunk === false || $chunk === '') {
            return null;
        }

        $payload .= $chunk;
    }

    return [
        'id' => unpack('V', substr($payload, 0, 4))[1],
        'type' => unpack('V', substr($payload, 4, 4))[1],
        'body' => substr($payload, 8, $size - 10),
    ];
};

$writePacket = function ($conn, int $id, int $type, string $body): void {
    $payload = pack('VV', $id, $type).$body."\x00\x00";
    fwrite($conn, pack('V', strlen($payload)).$payload);
};

// AUTH handshake (type 3 = SERVERDATA_AUTH)
$auth = $readPacket($conn);

if ($auth === null || $auth['type'] !== 3) {
    fclose($conn);
    fclose($server);
    exit(3);
}

if ($auth['body'] !== $password) {
    // id = -1 signals auth failure
    $writePacket($conn, 0xFFFFFFFF, 2, '');
    fclose($conn);
    fclose($server);
    exit(4);
}

$writePacket($conn, $auth['id'], 2, '');

// Command loop (type 2 = SERVERDATA_EXECCOMMAND)
while (true) {
    $packet = $readPacket($conn);

    if ($packet === null) {
        break;
    }

    if ($packet['type'] !== 2) {
        continue;
    }

    file_put_contents($logfile, $packet['body']."\n", FILE_APPEND);
    $writePacket($conn, $packet['id'], 0, 'OK');
}

fclose($conn);
fclose($server);
exit(0);
