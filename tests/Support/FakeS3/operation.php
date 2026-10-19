<?php

declare(strict_types=1);

/*
 * This file is part of the ChamberOrchestra package.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

// Child process of FakeS3Harness::runOperation(): php operation.php <json spec>.
// Builds the storage through the bundle extension, counts SDK attempts in the `attempt` step, runs one
// operation and prints one JSON result line.

use Aws\Exception\AwsException;
use Aws\S3\S3Client;
use ChamberOrchestra\FileBundle\DependencyInjection\ChamberOrchestraFileExtension;
use ChamberOrchestra\FileBundle\NamingStrategy\OriginNamingStrategy;
use ChamberOrchestra\FileBundle\Storage\S3Storage;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpFoundation\File\File;

require \dirname(__DIR__, 3).'/vendor/autoload.php';

/** @var array{storage: array<string, mixed>, operation: string, workdir: string, uploadSize: int} $spec */
$spec = \json_decode($argv[1], true, 512, \JSON_THROW_ON_ERROR);

$container = new ContainerBuilder();
$container->setParameter('kernel.project_dir', $spec['workdir']);
new ChamberOrchestraFileExtension()->load([['storages' => ['scores' => $spec['storage']]]], $container);
$container->getDefinition('chamber_orchestra_file.storage.scores')->setPublic(true);
$container->compile();

$storage = $container->get('chamber_orchestra_file.storage.scores');
\assert($storage instanceof S3Storage);
$client = new ReflectionProperty(S3Storage::class, 'client')->getValue($storage);
\assert($client instanceof S3Client);

$attempts = 0;
$client->getHandlerList()->appendAttempt(
    static function (callable $handler) use (&$attempts): callable {
        return static function (mixed ...$arguments) use ($handler, &$attempts): mixed {
            ++$attempts;

            return $handler(...$arguments);
        };
    },
    'fake_s3.attempt_counter',
);

$source = $spec['workdir'].'/violin_concerto.mp3';
$target = $spec['workdir'].'/moonlight_sonata.mp3';
if ('upload' === $spec['operation']) {
    \file_put_contents($source, \random_bytes($spec['uploadSize']));
}
$exception = null;
echo "ready\n"; // FakeS3Harness starts its 10·T_test guard here
$started = \hrtime(true);
try {
    if ('upload' === $spec['operation']) {
        $storage->upload(new File($source), new OriginNamingStrategy(), 'recordings');
    } else {
        $storage->download('recordings/violin_concerto.mp3', $target);
    }
} catch (Throwable $e) {
    $exception = $e;
}
$elapsed = (\hrtime(true) - $started) / 1e9;

$chain = [];
$awsException = null;
for ($e = $exception; null !== $e; $e = $e->getPrevious()) {
    $chain[] = $e::class;
    $awsException ??= $e instanceof AwsException ? $e : null;
}

echo \json_encode([
    'elapsed' => $elapsed,
    'attempts' => $attempts,
    'chain' => $chain,
    'message' => $exception?->getMessage(),
    'awsErrorCode' => $awsException?->getAwsErrorCode(),
    'connectionError' => $awsException?->isConnectionError(),
    'sourceSha256' => \is_file($source) ? \hash_file('sha256', $source) : null,
    'targetSize' => \is_file($target) ? \filesize($target) : null,
    'targetSha256' => \is_file($target) ? \hash_file('sha256', $target) : null,
], \JSON_THROW_ON_ERROR), "\n";
