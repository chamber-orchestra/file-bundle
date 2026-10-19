<?php

declare(strict_types=1);

/*
 * This file is part of the ChamberOrchestra package.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Tests\Integrational\Storage;

use Aws\S3\Exception\S3Exception;
use ChamberOrchestra\FileBundle\Exception\RuntimeException;
use GuzzleHttp\Exception\ConnectTimeoutException;
use GuzzleHttp\Exception\NetworkTimeoutException;
use GuzzleHttp\Exception\ResponseTimeoutException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Support\FakeS3\FakeS3Harness;

/**
 * Fault tests for an S3 storage with `http` / `retries` (CI-8 AC-5: I-1, I-2, I-3, I-4, I-7).
 *
 * Every operation runs in a child process that the harness kills after 10·T_test, so a client
 * without timeouts fails here instead of hanging.
 */
final class S3StorageFaultTest extends TestCase
{
    private const int ATTEMPTS = FakeS3Harness::ATTEMPTS;
    /** At least 1 000 000 B, so Guzzle streams the body (plan G-6); below 1 048 576 B, so no `Expect: 100-Continue` (G-10). */
    private const int STREAMED_UPLOAD_SIZE = 1_040_000;

    private FakeS3Harness $harness;

    protected function setUp(): void
    {
        $this->harness = new FakeS3Harness();
    }

    protected function tearDown(): void
    {
        $this->harness->close();
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function operations(): iterable
    {
        yield 'upload (PUT 1 KiB)' => ['upload', 'PUT'];
        yield 'download (GET)' => ['download', 'GET'];
    }

    #[DataProvider('operations')]
    public function testConnectTimeout(string $operation, string $method): void
    {
        $result = $this->harness->runOperation(FakeS3Harness::storage($this->harness->connectTimeoutEndpoint()), $operation);

        $this->assertTransferFailure($result, ConnectTimeoutException::class);
        self::assertSame(self::ATTEMPTS, $result['attempts']);
        self::assertGreaterThanOrEqual(self::ATTEMPTS * FakeS3Harness::C_TEST, $result['elapsed']);
        self::assertLessThanOrEqual(FakeS3Harness::UPPER_BOUND, $result['elapsed']);
    }

    #[DataProvider('operations')]
    public function testAcceptedAndSilent(string $operation, string $method): void
    {
        $result = $this->harness->runOperation(FakeS3Harness::storage($this->harness->startServer('silent')), $operation);

        $this->assertTransferFailure($result, NetworkTimeoutException::class);
        self::assertSame(\array_fill(0, self::ATTEMPTS, $method), $this->harness->requestMethods());
        self::assertSame(self::ATTEMPTS, $result['attempts']);
        self::assertGreaterThanOrEqual(self::ATTEMPTS * FakeS3Harness::T_TEST, $result['elapsed']);
        self::assertLessThanOrEqual(FakeS3Harness::UPPER_BOUND, $result['elapsed']);
    }

    /**
     * A 200 that keeps sending a byte every T_test/4 never idles, so only a whole-transfer timeout ends it (I-7, E-5).
     */
    #[DataProvider('operations')]
    public function testDripFeed(string $operation, string $method): void
    {
        $result = $this->harness->runOperation(FakeS3Harness::storage($this->harness->startServer('drip')), $operation);

        $this->assertTransferFailure($result, ResponseTimeoutException::class);
        self::assertSame(\array_fill(0, self::ATTEMPTS, $method), $this->harness->requestMethods());
        self::assertSame(self::ATTEMPTS, $result['attempts']);
        self::assertGreaterThanOrEqual(self::ATTEMPTS * FakeS3Harness::T_TEST, $result['elapsed']);
        self::assertLessThanOrEqual(FakeS3Harness::UPPER_BOUND, $result['elapsed']);
    }

    #[DataProvider('operations')]
    public function testSingleAttemptDoesNotRetry(string $operation, string $method): void
    {
        $result = $this->harness->runOperation(FakeS3Harness::storage($this->harness->startServer('silent'), 1), $operation);

        $this->assertTransferFailure($result, NetworkTimeoutException::class);
        self::assertSame([$method], $this->harness->requestMethods());
        self::assertSame(1, $result['attempts']);
        self::assertGreaterThanOrEqual(FakeS3Harness::T_TEST, $result['elapsed']);
        self::assertLessThanOrEqual(FakeS3Harness::T_TEST + 1.0, $result['elapsed']);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function recoveries(): iterable
    {
        yield 'upload (PUT 1 040 000 B, streamed)' => ['upload', 'PUT'];
        yield 'download (GET)' => ['download', 'GET'];
    }

    /**
     * The SDK retries a 503 and the second attempt succeeds. The retried PUT carries the whole body: at
     * 1 040 000 B Guzzle streams it (CURLOPT_READFUNCTION) and must rewind it (I-4, E-28). The retried GET
     * leaves only the 200 body in the target, which is shorter than the 503 body written there first (E-12).
     */
    #[DataProvider('recoveries')]
    public function testServiceUnavailableThenSuccess(string $operation, string $method): void
    {
        $result = $this->harness->runOperation(FakeS3Harness::storage($this->harness->startServer('unavailable_then_ok')), $operation, self::STREAMED_UPLOAD_SIZE);
        $context = (string) \json_encode($result);

        self::assertSame([], $result['chain'], $context);
        self::assertSame(self::ATTEMPTS, $result['attempts'], $context);
        $requests = $this->harness->requests();
        self::assertSame([$method, $method], \array_column($requests, 'method'));
        if ('upload' === $operation) {
            self::assertSame(self::STREAMED_UPLOAD_SIZE, $requests[1]['length']);
            self::assertSame($result['sourceSha256'], $requests[1]['sha256']);
        } else {
            self::assertSame(\strlen(FakeS3Harness::OBJECT_BODY), $result['targetSize']);
            self::assertSame(\hash('sha256', FakeS3Harness::OBJECT_BODY), $result['targetSha256']);
        }
    }

    /**
     * A 404 NoSuchKey is not retried and keeps its code, unlike a timeout (I-3, D-4).
     */
    public function testNoSuchKeyIsSingleAttemptWithCode(): void
    {
        $result = $this->harness->runOperation(FakeS3Harness::storage($this->harness->startServer('no_such_key')), 'download');
        $context = (string) \json_encode($result);

        self::assertSame(RuntimeException::class, $result['chain'][0] ?? null, $context);
        self::assertSame(S3Exception::class, $result['chain'][1] ?? null, $context);
        self::assertSame('NoSuchKey', $result['awsErrorCode'], $context);
        self::assertFalse($result['connectionError'], $context);
        self::assertSame(1, $result['attempts'], $context);
        self::assertSame(['GET'], $this->harness->requestMethods());
    }

    /**
     * Bundle RuntimeException → S3Exception (connection error, not NoSuchKey) → the Guzzle timeout (I-3).
     *
     * @param array{chain: list<string>, awsErrorCode: string|null, connectionError: bool|null, message: string|null} $result
     */
    private function assertTransferFailure(array $result, string $timeoutClass): void
    {
        $context = (string) \json_encode($result);
        self::assertSame(RuntimeException::class, $result['chain'][0] ?? null, $context);
        self::assertSame(S3Exception::class, $result['chain'][1] ?? null, $context);
        self::assertTrue($result['connectionError'], $context);
        self::assertNotSame('NoSuchKey', $result['awsErrorCode'], $context);
        self::assertContains($timeoutClass, $result['chain'], $context);
    }
}
