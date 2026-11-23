<?php declare(strict_types=1);

// A stand-in for rr-proxy that answers every frame with ST_OK and an empty body (SMT_ROOT: the
// root given, 32 bytes of 0xab by default), and writes each opcode it gets to a log, one hex byte
// per line. Serves any number of connections at once. Usage:
// php fake_rrproxy.php <socket> <log> [root hex]

[, $sock, $log] = $argv;
$root = hex2bin($argv[3] ?? str_repeat('ab', 32));
@unlink($sock);
$server = stream_socket_server('unix://' . $sock, $errNo, $errStr);
if ($server === false) {
    fwrite(STDERR, "listen: $errStr\n");
    exit(1);
}
$clients = [];
while (true) {
    $read = [$server, ...$clients];
    $write = $except = null;
    if (stream_select($read, $write, $except, 3600) === false) {
        break;
    }
    foreach ($read as $conn) {
        if ($conn === $server) {
            if (($new = @stream_socket_accept($server, 0)) !== false) {
                $clients[(int)$new] = $new;
            }
            continue;
        }
        $header = fread($conn, 5);
        if ($header === false || strlen($header) !== 5) {
            fclose($conn);
            unset($clients[(int)$conn]);
            continue;
        }
        $left = unpack('N', substr($header, 1, 4))[1];
        while ($left > 0 && ($chunk = fread($conn, $left)) !== false && $chunk !== '') {
            $left -= strlen($chunk);
        }
        file_put_contents($log, sprintf("%02x\n", ord($header[0])), FILE_APPEND);
        $body = ord($header[0]) === 0x40 ? $root : '';
        fwrite($conn, "\x00" . pack('N', strlen($body)) . $body);
    }
}
