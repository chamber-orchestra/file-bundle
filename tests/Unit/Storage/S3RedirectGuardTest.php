<?php

declare(strict_types=1);

/*
 * This file is part of the ChamberOrchestra package.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Tests\Unit\Storage;

use Aws\Command;
use Aws\CommandInterface;
use Aws\MockHandler;
use Aws\Result;
use Aws\S3\Exception\PermanentRedirectException;
use Aws\S3\Exception\S3Exception;
use Aws\S3\S3Client;
use ChamberOrchestra\FileBundle\Storage\S3RedirectGuard;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Promise\PromiseInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class S3RedirectGuardTest extends TestCase
{
    /** @return iterable<string, array{int|null}> */
    public static function passingStatuses(): iterable
    {
        yield '200' => [200];
        yield '204' => [204];
        yield '299' => [299];
        yield '400' => [400];
        yield 'no status' => [null];
    }

    #[DataProvider('passingStatuses')]
    public function testNonRedirectResultPassesUnchanged(?int $status): void
    {
        $result = self::s3Result($status);

        self::assertSame($result, self::guard(Create::promiseFor($result))->wait());
    }

    /** @return iterable<string, array{int}> */
    public static function redirectStatuses(): iterable
    {
        yield '300' => [300];
        yield '302' => [302];
        yield '307' => [307];
        yield '399' => [399];
    }

    #[DataProvider('redirectStatuses')]
    public function testRedirectResultIsRejectedAsS3ExceptionWithoutCode(int $status): void
    {
        $result = self::s3Result($status);
        $command = new Command('GetObject');

        $exception = self::rejection(self::guard(Create::promiseFor($result), $command));

        self::assertSame(S3Exception::class, $exception::class);
        self::assertStringContainsString((string) $status, $exception->getMessage());
        self::assertNull($exception->getAwsErrorCode());
        self::assertFalse($exception->isConnectionError());
        self::assertSame($result, $exception->getResult());
        self::assertSame($command, $exception->getCommand());
    }

    /** @return iterable<string, array{\Closure(CommandInterface): S3Exception}> */
    public static function rejections(): iterable
    {
        yield 'permanent redirect' => [static fn (CommandInterface $command): S3Exception => new PermanentRedirectException('Moved to the opera house', $command, ['result' => self::s3Result(301)])];
        yield 'NoSuchKey' => [static fn (CommandInterface $command): S3Exception => new S3Exception('No such recording', $command, ['code' => 'NoSuchKey'])];
    }

    /** @param \Closure(CommandInterface): S3Exception $make */
    #[DataProvider('rejections')]
    public function testRejectionPassesTheSameObject(\Closure $make): void
    {
        $command = new Command('GetObject');
        $rejected = $make($command);

        self::assertSame($rejected, self::rejection(self::guard(Create::rejectionFor($rejected), $command)));
    }

    public function testRegisterAppendsExactlyOneNamedInitStep(): void
    {
        $client = self::client(new MockHandler());
        $before = (string) $client->getHandlerList();

        S3RedirectGuard::register($client);
        $after = (string) $client->getHandlerList();

        self::assertTrue($client->getHandlerList()->hasMiddleware(S3RedirectGuard::NAME));
        self::assertSame(\substr_count($before, "\n") + 1, \substr_count($after, "\n"));
        self::assertSame(\substr_count($before, 'Step: init,') + 1, \substr_count($after, 'Step: init,'));
        self::assertSame(1, \substr_count($after, 'Step: init, Name: '.S3RedirectGuard::NAME.','));
    }

    public function testRegisteredStepRejectsARedirectReturnedThroughTheClient(): void
    {
        $handler = new MockHandler([self::s3Result(307)]);
        $client = self::client($handler);
        S3RedirectGuard::register($client);

        try {
            $client->headObject(['Bucket' => 'scores', 'Key' => 'recordings/violin_concerto.mp3']);
            self::fail('A 307 result must reject the operation.');
        } catch (S3Exception $exception) {
            self::assertSame(S3Exception::class, $exception::class);
            self::assertNull($exception->getAwsErrorCode());
        }
        self::assertCount(0, $handler, 'exactly one attempt');
    }

    private static function s3Result(?int $status): Result
    {
        return new Result(['@metadata' => null === $status ? [] : ['statusCode' => $status, 'headers' => ['location' => 'https://opera.example/scores']]]);
    }

    private static function guard(PromiseInterface $promise, ?CommandInterface $command = null): PromiseInterface
    {
        $handler = static fn (): PromiseInterface => $promise;

        return (S3RedirectGuard::middleware()($handler))($command ?? new Command('GetObject'));
    }

    private static function rejection(PromiseInterface $promise): S3Exception
    {
        try {
            $promise->wait();
        } catch (S3Exception $exception) {
            return $exception;
        }
        self::fail('The promise must be rejected with an S3Exception.');
    }

    private static function client(MockHandler $handler): S3Client
    {
        return new S3Client([
            'region' => 'eu-west-2',
            'version' => 'latest',
            'credentials' => ['key' => 'conductor', 'secret' => 'baton'],
            'retries' => ['mode' => 'standard', 'max_attempts' => 2],
            'handler' => $handler,
        ]);
    }
}
