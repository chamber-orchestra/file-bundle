<?php

declare(strict_types=1);

/*
 * This file is part of the ChamberOrchestra package.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

// Fake S3 server, child process of FakeS3Harness::startServer():
// php server.php <scenario> <journal> <t_test> <object body> [<location>].
// Prints its port, then journals every HTTP request as one JSON line (request and connection number,
// method, path, body length and sha256, time). Every response carries `Connection: close`, except in the
// keep_alive scenario. Guard (spec §9):
// each connection is closed 10·T_test after accept, and after the first such close the server stops
// accepting. Scenarios:
//   silent              — read the request, never answer;
//   drip                — answer 200 with a large Content-Length, then send 1 byte every T_test/4;
//   unavailable_then_ok — answer the first request 503 ServiceUnavailable, every later one 200 (a GET gets
//                         the object body);
//   no_such_key         — answer 404 NoSuchKey;
//   redirect_<status>   — answer <status> (e.g. 301, 302, 307) with an empty body, and with a `Location: <location>`
//                         header when a location is given;
//   keep_alive          — answer request 1 with 200, an empty body and `Connection: keep-alive`; after the complete
//                         body of request 2, wait 0.8·T_test and close the connection request 2 came on, unanswered;
//                         never answer requests 3+ (CI-8 AC-20, plan §2.5).

[, $scenario, $journal, $tTest, $objectBody] = $argv;
$location = $argv[5] ?? '';
$tTest = (float) $tTest;
$guard = 10 * $tTest;

function response(string $status, string $body, string $contentType = 'application/xml'): string
{
    return "HTTP/1.1 {$status}\r\nContent-Type: {$contentType}\r\nContent-Length: ".\strlen($body)."\r\nConnection: close\r\n\r\n".$body;
}

function error_response(string $status, string $code, string $message): string
{
    return \response($status, '<?xml version="1.0" encoding="UTF-8"?>'."\n<Error><Code>{$code}</Code><Message>{$message}</Message><RequestId>fake-s3</RequestId></Error>");
}

/**
 * Removes one complete request (head and Content-Length body) from the buffer.
 *
 * @return array{method: string, path: string, body: string}|null
 */
function take_request(string &$buffer): ?array
{
    $headEnd = \strpos($buffer, "\r\n\r\n");
    if (false === $headEnd) {
        return null;
    }
    $head = \substr($buffer, 0, $headEnd);
    $length = 1 === \preg_match('/^content-length:\s*(\d+)/mi', $head, $match) ? (int) $match[1] : 0;
    if (\strlen($buffer) < $headEnd + 4 + $length) {
        return null;
    }
    $body = \substr($buffer, $headEnd + 4, $length);
    $buffer = \substr($buffer, $headEnd + 4 + $length);
    [$method, $path] = \explode(' ', \strtok($head, "\r\n") ?: '') + ['', ''];

    return ['method' => $method, 'path' => $path, 'body' => $body];
}

$server = \stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
if (false === $server) {
    \fwrite(\STDERR, "listen failed: {$errstr}\n");
    exit(1);
}
\stream_set_blocking($server, false);
echo \substr((string) \strrchr((string) \stream_socket_get_name($server, false), ':'), 1), "\n";

/** @var array<int, array{socket: resource, buffer: string, accepted: float, drip: float|null, closeAt: float|null}> $connections */
$connections = [];
$connectionCount = 0;
$requestCount = 0;
$stopAt = \microtime(true) + 60; // never outlive a parent that died without killing us

while (\microtime(true) < $stopAt && (null !== $server || [] !== $connections)) {
    $read = \array_column($connections, 'socket');
    if (null !== $server) {
        $read[] = $server;
    }
    $write = $except = null;
    \stream_select($read, $write, $except, 0, 10_000);
    $now = \microtime(true);

    if (null !== $server && \in_array($server, $read, true) && false !== ($socket = @\stream_socket_accept($server, 0))) {
        \stream_set_blocking($socket, false);
        $connections[++$connectionCount] = ['socket' => $socket, 'buffer' => '', 'accepted' => $now, 'drip' => null, 'closeAt' => null];
    }

    foreach ($connections as $id => &$connection) {
        if (\in_array($connection['socket'], $read, true)) {
            $chunk = \fread($connection['socket'], 65536);
            if (false === $chunk || ('' === $chunk && \feof($connection['socket']))) {
                \fclose($connection['socket']);
                unset($connections[$id]);
                continue;
            }
            $connection['buffer'] .= $chunk;
        }

        while (null !== ($request = \take_request($connection['buffer']))) {
            \file_put_contents($journal, \json_encode([
                'request' => ++$requestCount,
                'connection' => $id,
                'method' => $request['method'],
                'path' => $request['path'],
                'length' => \strlen($request['body']),
                'sha256' => \hash('sha256', $request['body']),
                'time' => $now,
            ])."\n", \FILE_APPEND);

            if ('drip' === $scenario) {
                @\fwrite($connection['socket'], "HTTP/1.1 200 OK\r\nContent-Type: application/octet-stream\r\nContent-Length: 1000000\r\nConnection: close\r\n\r\n");
                $connection['drip'] = $now;
            }
            if ('keep_alive' === $scenario && 2 === $requestCount) {
                $connection['closeAt'] = $now + 0.8 * $tTest;
            }
            $answer = match (true) {
                'unavailable_then_ok' === $scenario && 1 === $requestCount => \error_response('503 Service Unavailable', 'ServiceUnavailable', 'Please reduce your request rate.'),
                'unavailable_then_ok' === $scenario => \response('200 OK', 'GET' === $request['method'] ? $objectBody : '', 'application/octet-stream'),
                'no_such_key' === $scenario => \error_response('404 Not Found', 'NoSuchKey', 'The specified key does not exist.'),
                'keep_alive' === $scenario && 1 === $requestCount => "HTTP/1.1 200 OK\r\nContent-Type: application/octet-stream\r\nContent-Length: 0\r\nConnection: keep-alive\r\n\r\n",
                \str_starts_with($scenario, 'redirect_') => 'HTTP/1.1 '.\substr($scenario, 9)." Redirect\r\n".('' !== $location ? "Location: {$location}\r\n" : '')."Content-Length: 0\r\nConnection: close\r\n\r\n",
                default => null,
            };
            if (null !== $answer) {
                @\fwrite($connection['socket'], $answer);
            }
        }

        if (null !== $connection['closeAt'] && $now >= $connection['closeAt']) {
            \fclose($connection['socket']);
            unset($connections[$id]);
            continue;
        }

        if ($now - $connection['accepted'] >= $guard) {
            \fclose($connection['socket']);
            unset($connections[$id]);
            if (null !== $server) {
                \fclose($server);
                $server = null;
            }
            continue;
        }

        if (null !== $connection['drip'] && $now >= $connection['drip']) {
            @\fwrite($connection['socket'], 'x');
            $connection['drip'] = $now + $tTest / 4;
        }
    }
    unset($connection);
}
