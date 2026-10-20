<?php

declare(strict_types=1);

/*
 * This file is part of the ChamberOrchestra package.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Tests\Integrational\Storage;

use Aws\S3\Exception\PermanentRedirectException;
use Aws\S3\Exception\S3Exception;
use ChamberOrchestra\FileBundle\Exception\RuntimeException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Support\FakeS3\FakeS3Harness;

/**
 * Redirect tests for an S3 storage with `http` (CI-8 R-B7, I-8, AC-17–AC-19).
 *
 * A 3xx ends the operation after one request to the redirecting server: nothing follows its `Location` (the
 * silent target gets no request) and nothing retries it. A 301 is the SDK's PermanentRedirectException; any other
 * 3xx is an S3Exception without an AWS error code (S3RedirectGuard), never a silent success (spec E-25).
 */
final class S3StorageRedirectTest extends TestCase
{
    private FakeS3Harness $harness;
    /** Serves the `Location` target with a journal of its own. */
    private FakeS3Harness $target;

    protected function setUp(): void
    {
        $this->harness = new FakeS3Harness();
        $this->target = new FakeS3Harness();
    }

    protected function tearDown(): void
    {
        $this->harness->close();
        $this->target->close();
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function operations(): iterable
    {
        yield 'upload (PUT 1 KiB)' => ['upload', 'PUT'];
        yield 'download (GET)' => ['download', 'GET'];
    }

    /**
     * @return iterable<string, array{int, string, string}>
     */
    public static function redirectsWithoutLocation(): iterable
    {
        foreach ([307, 302] as $status) {
            yield "{$status}, upload (PUT 1 KiB)" => [$status, 'upload', 'PUT'];
            yield "{$status}, download (GET)" => [$status, 'download', 'GET'];
        }
    }

    #[DataProvider('operations')]
    public function testPermanentRedirectWithLocation(string $operation, string $method): void
    {
        $result = $this->redirect(301, $operation, true);

        $this->assertSingleRequestFailure($result, $method, PermanentRedirectException::class);
    }

    #[DataProvider('operations')]
    public function testTemporaryRedirectWithLocation(string $operation, string $method): void
    {
        $result = $this->redirect(307, $operation, true);

        $this->assertSingleRequestFailure($result, $method, S3Exception::class);
    }

    #[DataProvider('redirectsWithoutLocation')]
    public function testRedirectWithoutLocation(int $status, string $operation, string $method): void
    {
        $result = $this->redirect($status, $operation, false);

        $this->assertSingleRequestFailure($result, $method, S3Exception::class);
    }

    /**
     * @return array{elapsed: float, attempts: int, chain: list<string>, message: string|null, awsErrorCode: string|null, connectionError: bool|null}
     */
    private function redirect(int $status, string $operation, bool $withLocation): array
    {
        $location = $withLocation ? $this->target->startServer('silent').'/orchestra/recordings/requiem.mp3' : null;

        return $this->harness->runOperation(FakeS3Harness::storage($this->harness->startServer('redirect_'.$status, $location)), $operation);
    }

    /**
     * Bundle RuntimeException → exactly $exceptionClass (not a subclass), without an AWS error code, after one
     * request to the redirecting server and none to the `Location` target (I-8).
     *
     * @param array{elapsed: float, attempts: int, chain: list<string>, awsErrorCode: string|null, connectionError: bool|null} $result
     */
    private function assertSingleRequestFailure(array $result, string $method, string $exceptionClass): void
    {
        $context = (string) \json_encode($result);
        self::assertSame(RuntimeException::class, $result['chain'][0] ?? null, $context);
        self::assertSame($exceptionClass, $result['chain'][1] ?? null, $context);
        self::assertNull($result['awsErrorCode'], $context);
        self::assertFalse($result['connectionError'], $context);
        self::assertSame([$method], $this->harness->requestMethods(), $context);
        self::assertSame([], $this->target->requestMethods(), $context);
        self::assertSame(1, $result['attempts'], $context);
        self::assertLessThanOrEqual(FakeS3Harness::UPPER_BOUND, $result['elapsed'], $context);
    }
}
