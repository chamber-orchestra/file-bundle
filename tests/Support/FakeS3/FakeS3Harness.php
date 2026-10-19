<?php

declare(strict_types=1);

/*
 * This file is part of the ChamberOrchestra package.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Tests\Support\FakeS3;

use PHPUnit\Framework\Assert;

/**
 * Runs the fake S3 server (server.php) and one storage operation (operation.php) in child processes.
 *
 * The operation is killed after GUARD seconds, so a client without timeouts fails the test instead of
 * hanging it (CI-8 spec §9).
 */
final class FakeS3Harness
{
    public const float T_TEST = 0.5;
    public const float C_TEST = 0.25;
    public const float GUARD = 10 * self::T_TEST;
    /** SDK attempts (`retries.max_attempts`) of the storage that storage() builds. */
    public const int ATTEMPTS = 2;
    /**
     * The one upper bound of an operation's time (plan §3): A·T_test + 2 s + 1 s = 4 s. In `standard` mode the SDK's
     * single retry delay at A = 2 is at most 2 s (RetryMiddlewareV2::exponentialDelayWithJitter).
     */
    public const float UPPER_BOUND = self::ATTEMPTS * self::T_TEST + 2.0 + 1.0;
    /** The body of a successful GET; shorter than the server's error bodies, so a target left untruncated shows. */
    public const string OBJECT_BODY = 'Clair de lune';

    private readonly string $workdir;
    /** @var list<resource> */
    private array $processes = [];
    /** @var list<resource> */
    private array $streams = [];

    public function __construct()
    {
        $this->workdir = \sys_get_temp_dir().'/fake_s3_'.\bin2hex(\random_bytes(6));
        \mkdir($this->workdir);
    }

    /**
     * The `storages.<name>` config of the tests: timeouts C_test / T_test, `standard` retries, static credentials.
     *
     * @return array<string, mixed>
     */
    public static function storage(string $endpoint, int $maxAttempts = self::ATTEMPTS): array
    {
        return [
            'driver' => 's3',
            'bucket' => 'orchestra',
            'region' => 'eu-west-2',
            'endpoint' => $endpoint,
            'path_style' => true,
            'credentials' => ['key' => 'conductor', 'secret' => 'baton'],
            'http' => ['connect_timeout' => self::C_TEST, 'timeout' => self::T_TEST],
            'retries' => ['mode' => 'standard', 'max_attempts' => $maxAttempts],
        ];
    }

    /**
     * Starts server.php with a scenario and returns its endpoint; $location is the `Location` of a redirect scenario.
     */
    public function startServer(string $scenario, ?string $location = null): string
    {
        $process = \proc_open(
            [\PHP_BINARY, __DIR__.'/server.php', $scenario, $this->workdir.'/journal.jsonl', (string) self::T_TEST, self::OBJECT_BODY, ...(null !== $location ? [$location] : [])],
            [1 => ['pipe', 'w'], 2 => ['file', $this->workdir.'/server.err', 'w']],
            $pipes,
        );
        \assert(\is_resource($process));
        $this->processes[] = $process;
        $this->streams[] = $pipes[1];
        \stream_set_blocking($pipes[1], false);

        $output = '';
        $deadline = \microtime(true) + self::GUARD;
        while (1 !== \preg_match('/^(\d+)$/m', $output, $port)) {
            if (\microtime(true) > $deadline) {
                Assert::fail('The fake S3 server did not report its port: '.$output.\file_get_contents($this->workdir.'/server.err'));
            }
            \usleep(10_000);
            $output .= (string) \stream_get_contents($pipes[1]);
        }

        return 'http://127.0.0.1:'.$port[1];
    }

    /**
     * HTTP methods of the requests the server received, in order.
     *
     * @return list<string>
     */
    public function requestMethods(): array
    {
        return \array_column($this->requests(), 'method');
    }

    /**
     * The requests the server received, in order (one journal line per complete request).
     *
     * @return list<array{request: int, connection: int, method: string, path: string, length: int, sha256: string, time: float}>
     */
    public function requests(): array
    {
        $lines = @\file($this->workdir.'/journal.jsonl', \FILE_IGNORE_NEW_LINES | \FILE_SKIP_EMPTY_LINES) ?: [];

        return \array_map(static function (string $line): array {
            /** @var array{request: int, connection: int, method: string, path: string, length: int, sha256: string, time: float} $request */
            $request = \json_decode($line, true, 512, \JSON_THROW_ON_ERROR);

            return $request;
        }, $lines);
    }

    /**
     * An endpoint whose TCP connect phase times out instead of being refused (plan §3 "Таймаут соединения").
     */
    public function connectTimeoutEndpoint(): string
    {
        // Guzzle classifies errno 28 as ConnectTimeoutException only by libcurl's message (CurlFactory::CURL_CONNECT_TIMEOUT_ERRORS).
        $libcurl = \curl_version();
        if (false === $libcurl || $libcurl['version_number'] < 0x080B00) {
            Assert::fail(\sprintf(
                'This environment cannot run the connect-timeout test: libcurl %s words a connect timeout as "Failed to connect to … after N ms: Timeout was reached", which Guzzle maps to NetworkTimeoutException instead of ConnectTimeoutException. The test (CI-8 spec §9) needs libcurl >= 8.11, whose wording "Connection timed out after N milliseconds" Guzzle recognises.',
                false === $libcurl ? '(unknown version)' : $libcurl['version'],
            ));
        }

        if ('Linux' === \PHP_OS_FAMILY) {
            // Linux drops a SYN while the accept queue is full, so the connect phase times out.
            $listener = \stream_socket_server(
                'tcp://127.0.0.1:0',
                $errno,
                $errstr,
                \STREAM_SERVER_BIND | \STREAM_SERVER_LISTEN,
                \stream_context_create(['socket' => ['backlog' => 1]]),
            );
            \assert(false !== $listener, $errstr);
            $this->streams[] = $listener;
            $address = (string) \stream_socket_get_name($listener, false);
            for ($i = 0; $i < 4; ++$i) {
                $filler = @\stream_socket_client('tcp://'.$address, $errno, $errstr, 1, \STREAM_CLIENT_CONNECT | \STREAM_CLIENT_ASYNC_CONNECT);
                if (false !== $filler) {
                    $this->streams[] = $filler;
                }
            }
        } else {
            // macOS answers a full accept queue with RST, so use a TEST-NET-1 address (RFC 5737) that nothing answers.
            $address = '192.0.2.1:81';
        }

        $started = \microtime(true);
        $probe = @\stream_socket_client('tcp://'.$address, $errno, $errstr, 2 * self::C_TEST);
        $elapsed = \microtime(true) - $started;
        if (false !== $probe || $elapsed < 2 * self::C_TEST * 0.9) {
            Assert::fail(\sprintf(
                'This environment (%s) cannot simulate a connect timeout: a raw connection to %s must time out after %.2f s, got %s after %.3f s.',
                \PHP_OS_FAMILY,
                $address,
                2 * self::C_TEST,
                false === $probe ? \sprintf('error %d "%s"', $errno, $errstr) : 'a connection',
                $elapsed,
            ));
        }

        return 'http://'.$address;
    }

    /**
     * Runs one S3Storage operation ("upload" of a random file of $uploadSize bytes, or "download") in a child process.
     *
     * @param array<string, mixed> $storage the `storages.<name>` config
     *
     * @return array{elapsed: float, attempts: int, chain: list<string>, message: string|null, awsErrorCode: string|null, connectionError: bool|null, sourceSha256: string|null, targetSize: int|null, targetSha256: string|null}
     */
    public function runOperation(array $storage, string $operation, int $uploadSize = 1024): array
    {
        $spec = \json_encode(['storage' => $storage, 'operation' => $operation, 'workdir' => $this->workdir, 'uploadSize' => $uploadSize], \JSON_THROW_ON_ERROR);
        $output = $this->workdir.'/operation.out';
        $process = \proc_open(
            [\PHP_BINARY, __DIR__.'/operation.php', $spec],
            [1 => ['file', $output, 'w'], 2 => ['file', $this->workdir.'/operation.err', 'w']],
            $pipes,
        );
        \assert(\is_resource($process));
        $this->processes[] = $process;

        // The guard clock starts at operation.php's "ready" line, so a slow child start-up (autoload, container
        // compile) does not count against 10·T_test; start-up has its own deadline of the same length.
        $spawned = \microtime(true);
        $started = null;
        while (($status = \proc_get_status($process))['running']) {
            $now = \microtime(true);
            // Anywhere in stdout: PHP may print a start-up warning before the "ready" line.
            if (null === $started && 1 === \preg_match('/^ready$/m', (string) \file_get_contents($output))) {
                $started = $now;
            }
            if (null === $started && $now - $spawned >= self::GUARD) {
                \proc_terminate($process, 9);
                Assert::fail(\sprintf('"%s" did not start within %.1f s: %s%s', $operation, self::GUARD, \file_get_contents($output), \file_get_contents($this->workdir.'/operation.err')));
            }
            if (null !== $started && $now - $started >= self::GUARD) {
                \proc_terminate($process, 9);
                Assert::fail(\sprintf('operation exceeded 10·T_test (%.1f s): "%s" killed after %.3f s.', self::GUARD, $operation, $now - $started));
            }
            \usleep(10_000);
        }

        $lines = \file($output, \FILE_IGNORE_NEW_LINES | \FILE_SKIP_EMPTY_LINES) ?: [];
        $result = \json_decode((string) \end($lines), true);
        if (0 !== $status['exitcode'] || !\is_array($result)) {
            Assert::fail(\sprintf(
                'The operation exited with code %d and printed no JSON result: %s%s',
                $status['exitcode'],
                \file_get_contents($output),
                \file_get_contents($this->workdir.'/operation.err'),
            ));
        }

        return $result;
    }

    public function close(): void
    {
        foreach ($this->processes as $process) {
            \proc_terminate($process, 9);
        }
        foreach ($this->streams as $stream) {
            \fclose($stream);
        }
        foreach ($this->processes as $process) {
            \proc_close($process);
        }
        $this->processes = $this->streams = [];
        \array_map(\unlink(...), \glob($this->workdir.'/*') ?: []);
        @\rmdir($this->workdir);
    }
}
