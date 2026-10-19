<?php

declare(strict_types=1);

/*
 * This file is part of the ChamberOrchestra package.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

// Fake S3 server, child process of FakeS3Harness::startServer(): php server.php <scenario> <journal> <t_test>.
// Prints its port, then journals every HTTP request as one JSON line (request and connection number,
// method, path, body length and sha256, time). Every response carries `Connection: close`. Guard (spec §9):
// each connection is closed 10·T_test after accept, and after the first such close the server stops
// accepting. Scenarios:
//   silent — read the request, never answer;
//   drip   — answer 200 with a large Content-Length, then send 1 byte every T_test/4.

[, $scenario, $journal, $tTest] = $argv;
$tTest = (float) $tTest;
$guard = 10 * $tTest;

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

/** @var array<int, array{socket: resource, buffer: string, accepted: float, drip: float|null}> $connections */
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
        $connections[++$connectionCount] = ['socket' => $socket, 'buffer' => '', 'accepted' => $now, 'drip' => null];
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
