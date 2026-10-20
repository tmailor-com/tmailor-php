<?php

// Minimal TCP server for CurlTransportTest (loopback only): accepts connections, reads the request and closes
// the connection without sending anything (curl: "Empty reply from server").
// Usage: php raw_server.php <port>

declare(strict_types=1);

$port = (int) ($argv[1] ?? 0);
$server = stream_socket_server('tcp://127.0.0.1:' . $port, $errno, $errstr);
if ($server === false) {
    fwrite(STDERR, "listen failed: $errstr\n");
    exit(1);
}
for (;;) {
    $conn = @stream_socket_accept($server, 60);
    if ($conn === false) {
        continue;
    }
    stream_set_timeout($conn, 2);
    $buf = '';
    while (!str_contains($buf, "\r\n\r\n") && !feof($conn)) {
        $chunk = fread($conn, 8192);
        if ($chunk === false || $chunk === '') {
            break;
        }
        $buf .= $chunk;
    }
    fclose($conn);
}
