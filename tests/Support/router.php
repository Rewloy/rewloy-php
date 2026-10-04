<?php

/**
 * The local server's routes (tests/Support/LocalServer.php), for the curl
 * transport's tests.
 */

declare(strict_types=1);

$uri = is_string($_SERVER['REQUEST_URI'] ?? null) ? $_SERVER['REQUEST_URI'] : '/';
$path = (string) parse_url($uri, PHP_URL_PATH);
parse_str((string) parse_url($uri, PHP_URL_QUERY), $query);
$int = static fn (string $name, int $default): int => is_numeric($query[$name] ?? null) ? (int) $query[$name] : $default;

/** Writes and pushes it out at once. */
$send = static function (string $text): void {
    echo $text;
    flush();
};

if ($path === '/echo' || str_starts_with($path, '/v1/passes/')) {
    header('Content-Type: application/json; charset=utf-8');
    header('X-Request-Id: req-echo');
    header('X-Multi: a');
    header('X-Multi: b', false);
    $seen = [
        'method' => $_SERVER['REQUEST_METHOD'] ?? '',
        'uri' => $uri,
        'headers' => array_change_key_case(getallheaders()),
        'body' => (string) file_get_contents('php://input'),
    ];
    echo json_encode(str_starts_with($path, '/v1/') ? ['data' => $seen] : $seen, JSON_THROW_ON_ERROR);
    return true;
}

if ($path === '/status') {
    http_response_code($int('code', 500));
    header('Content-Type: text/html');
    echo '<html><body>' . $int('code', 500) . '</body></html>';
    return true;
}

if ($path === '/slow') {
    usleep($int('ms', 1000) * 1000);
    echo 'late';
    return true;
}

if ($path === '/gzip') {
    header('Content-Type: application/json');
    header('Content-Encoding: gzip');
    echo gzencode('{"data":{"zipped":true}}');
    return true;
}

if ($path === '/sse' || $path === '/v1/live') {
    // The API's stream: retry first, then events, heartbeats while idle.
    ignore_user_abort(true);
    header('Content-Type: text/event-stream; charset=utf-8');
    header('X-Request-Id: req-sse');
    while (ob_get_level() > 0) {
        ob_end_flush();
    }
    $send("retry: 5000\n\n");
    $events = $int('events', 3);
    for ($i = 1; $i <= $events; $i++) {
        usleep($int('pause', 20) * 1000);
        $send("event: tick\ndata: {\"n\":{$i},\"name\":\"Ayşe\"}\n\n");
    }
    usleep($int('hang', 0) * 1000);
    $agent = is_string($_SERVER['HTTP_USER_AGENT'] ?? null) ? $_SERVER['HTTP_USER_AGENT'] : '';
    $marker = preg_match('#marker/([a-z0-9-]+)#', $agent, $m) === 1 ? $m[1] : null;
    if ($marker !== null) {
        // Heartbeats until the client goes away, then say so in the marker file.
        $until = microtime(true) + 5;
        while (microtime(true) < $until) {
            $send(": hb\n\n");
            if (connection_aborted() === 1) {
                file_put_contents(sys_get_temp_dir() . '/' . basename($marker), 'closed');
                break;
            }
            usleep(50000);
        }
    }
    return true;
}

http_response_code(404);
echo 'no route';
return true;
