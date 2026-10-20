<?php

declare(strict_types=1);

/*
 * This file is part of the ChamberOrchestra package.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace ChamberOrchestra\FileBundle\Storage;

use Aws\CommandInterface;
use Aws\ResultInterface;
use Aws\S3\Exception\S3Exception;
use Aws\S3\S3Client;
use GuzzleHttp\Promise\PromiseInterface;
use Psr\Http\Message\RequestInterface;

/**
 * Turns a 3xx result into an `S3Exception` without an AWS error code (CI-8 R-B7, D-17).
 *
 * With `allow_redirects = false` Guzzle returns a 3xx response as a result; without this step a 3xx with no body
 * reads as success. The step sits in the `init` step, outside `retry` and `s3.permanent_redirect` (both in `sign`),
 * so the error is not retried and a 301 stays a `PermanentRedirectException`.
 */
final class S3RedirectGuard
{
    public const string NAME = 'chamber_orchestra_file.s3_redirect_guard';

    public static function register(S3Client $client): void
    {
        $client->getHandlerList()->appendInit(self::middleware(), self::NAME);
    }

    /**
     * @internal the middleware factory that register() appends; public for its unit test
     */
    public static function middleware(): \Closure
    {
        return static fn (callable $handler): \Closure => static function (CommandInterface $command, ?RequestInterface $request = null) use ($handler): PromiseInterface {
            $promise = $handler($command, $request);
            \assert($promise instanceof PromiseInterface);

            return $promise->then(static function (mixed $result) use ($command): mixed {
                $metadata = $result instanceof ResultInterface ? $result['@metadata'] : null;
                $status = \is_array($metadata) ? ($metadata['statusCode'] ?? null) : null;
                if (\is_int($status) && $status >= 300 && $status <= 399) {
                    // The body is not parsed, so getAwsErrorCode() is null and never reads as NoSuchKey.
                    throw new S3Exception(\sprintf('Received HTTP %d for "%s"; this S3 client does not follow redirects.', $status, $command->getName()), $command, ['connection_error' => false, 'result' => $result]);
                }

                return $result;
            });
        };
    }
}
