<?php

// Router for `php -S 127.0.0.1:<port> router.php`, used by CurlTransportTest (loopback only, no secrets).

declare(strict_types=1);

$uri = $_SERVER['REQUEST_URI'] ?? '/';
$uri = is_string($uri) ? $uri : '/';
$path = (string) parse_url($uri, PHP_URL_PATH);
parse_str((string) parse_url($uri, PHP_URL_QUERY), $query);

// A "/slow" prefix makes any route take 3 s (a slow API behind a base URL).
if (str_starts_with($path, '/slow/')) {
    usleep(3_000_000);
    $path = substr($path, 5);
}

switch ($path) {
    case '/echo':
    case '/v1/mailbox/info':
        $headers = function_exists('getallheaders') ? getallheaders() : [];
        header('Content-Type: application/json');
        header('X-RateLimit-Limit: 120');
        header('X-RateLimit-Remaining: 99');
        header('X-RateLimit-Reset: 1760342460');
        header('X-Request-Id: rid-echo');
        echo json_encode([
            'method' => $_SERVER['REQUEST_METHOD'] ?? '',
            'query' => $query,
            'headers' => array_change_key_case($headers, CASE_LOWER),
            'body' => file_get_contents('php://input'),
        ]);
        break;
    case '/v1/health':
        header('Content-Type: application/json');
        echo json_encode(['status' => 'ok', 'time' => 1760342400, 'version' => 'v1']);
        break;
    case '/slow':
        usleep((int) (((float) ($query['s'] ?? 3)) * 1e6));
        header('Content-Type: application/json');
        echo '{"status":"ok","time":1,"version":"v1"}';
        break;
    case '/partial':
        // Promise 100 bytes, send 10, then end the connection: curl reports a partial body.
        header('Content-Type: application/json');
        header('Content-Length: 100');
        echo '{"status":';
        flush();
        exit;
    case '/redirect':
        header('Location: http://127.0.0.1:1/elsewhere', true, 302);
        break;
    case '/bad-gateway':
        http_response_code(502);
        header('Content-Type: text/html');
        echo '<html>bad gateway</html>';
        break;
    case '/v1/mailbox/delete':
        http_response_code(404);
        header('Content-Type: application/json');
        echo json_encode(['error' => ['code' => 'MailboxNotFound', 'message' => 'Mailbox not found', 'description' => '', 'request_id' => 'rid-404']]);
        break;
    default:
        http_response_code(404);
        echo 'not found';
}
