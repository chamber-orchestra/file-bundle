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
use PHPUnit\Framework\TestCase;
use Tests\Support\FakeS3\FakeS3Harness;

/**
 * Keep-alive regression test for an S3 storage with `http` (CI-8 AC-20: R-B8, I-1, I-2).
 *
 * Without CURLOPT_FORBID_REUSE the upload reuses the warm-up's connection; when the server closes it after the
 * body without an answer, Guzzle re-sends the PUT outside the SDK's attempt count (spec L-7, E-28).
 */
final class S3StorageKeepAliveTest extends TestCase
{
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

    public function testDeadConnectionAfterBodyIsOneTransferPerAttempt(): void
    {
        $endpoint = $this->harness->startServer('keep_alive');

        $result = $this->harness->runOperation(FakeS3Harness::storage($endpoint), 'upload', self::STREAMED_UPLOAD_SIZE, warmUp: true);

        $requests = $this->harness->requests();
        $context = \json_encode(['result' => $result, 'requests' => $requests], \JSON_THROW_ON_ERROR);
        $connections = \array_column($requests, 'connection');
        $puts = \array_filter(\array_column($requests, 'method'), static fn (string $method): bool => 'PUT' === $method);
        self::assertSame('GET', $requests[0]['method'] ?? null, $context);
        self::assertSame(\array_values(\array_unique($connections)), $connections, 'every HTTP request on its own connection: '.$context);
        self::assertCount($result['attempts'], $puts, 'PUT requests = SDK attempts: '.$context);
        self::assertLessThanOrEqual(FakeS3Harness::ATTEMPTS, $result['attempts'], $context);
        self::assertLessThanOrEqual(FakeS3Harness::UPPER_BOUND, $result['elapsed'], $context);
        self::assertSame(RuntimeException::class, $result['chain'][0] ?? null, $context);
        self::assertSame(S3Exception::class, $result['chain'][1] ?? null, $context);
        self::assertTrue($result['connectionError'], $context);
        self::assertNotSame('NoSuchKey', $result['awsErrorCode'], $context);
    }
}
