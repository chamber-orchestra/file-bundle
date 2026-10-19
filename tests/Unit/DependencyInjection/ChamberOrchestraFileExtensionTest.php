<?php

declare(strict_types=1);

/*
 * This file is part of the ChamberOrchestra package.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Tests\Unit\DependencyInjection;

use Aws\S3\S3Client;
use ChamberOrchestra\FileBundle\DependencyInjection\ChamberOrchestraFileExtension;
use ChamberOrchestra\FileBundle\Handler\Handler;
use ChamberOrchestra\FileBundle\Storage\FileSystemStorage;
use ChamberOrchestra\FileBundle\Storage\S3RedirectGuard;
use ChamberOrchestra\FileBundle\Storage\S3Storage;
use ChamberOrchestra\FileBundle\Storage\StorageResolver;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;

class ChamberOrchestraFileExtensionTest extends TestCase
{
    private const string SCORES_CLIENT = 'chamber_orchestra_file.storage.scores.client';

    private const array SCORES_S3 = [
        'driver' => 's3',
        'bucket' => 'orchestra-scores',
        'region' => 'eu-west-2',
        'endpoint' => 'https://storage.example.test',
        'path_style' => true,
        'credentials' => ['key' => 'conductor-key', 'secret' => 'conductor-secret'],
    ];

    /** The S3Client arguments file-bundle 8.1.4 builds for SCORES_S3 (R-B3, I-5). */
    private const array CLIENT_ARGUMENTS_8_1_4 = [
        'region' => 'eu-west-2',
        'version' => 'latest',
        'endpoint' => 'https://storage.example.test',
        'use_path_style_endpoint' => true,
        'credentials' => ['key' => 'conductor-key', 'secret' => 'conductor-secret'],
    ];

    private const array HTTP = ['connect_timeout' => 5, 'timeout' => 10];
    /** The `http` client argument for HTTP (plan §3, AC-3: R-B7's `allow_redirects`, R-B8's `curl`). */
    private const array HTTP_ARGUMENT = [
        'connect_timeout' => 5.0,
        'timeout' => 10.0,
        'allow_redirects' => false,
        'curl' => [\CURLOPT_FORBID_REUSE => true],
    ];
    private const array RETRIES = ['mode' => 'standard', 'max_attempts' => 2];
    private ContainerBuilder $container;

    protected function setUp(): void
    {
        $this->container = new ContainerBuilder();
        $this->container->setParameter('kernel.project_dir', '/project');

        $extension = new ChamberOrchestraFileExtension();
        $extension->load([
            'chamber_orchestra_file' => [
                'storages' => [
                    'default' => [
                        'driver' => 'file_system',
                        'path' => '/var/uploads',
                        'uri_prefix' => '/uploads',
                    ],
                ],
            ],
        ], $this->container);
    }

    public function testLoadRegistersStorageResolver(): void
    {
        self::assertTrue($this->container->hasDefinition(StorageResolver::class));
    }

    public function testLoadRegistersNamedStorage(): void
    {
        self::assertTrue($this->container->hasDefinition('chamber_orchestra_file.storage.default'));
    }

    public function testLoadRegistersHandler(): void
    {
        self::assertTrue($this->container->hasDefinition(Handler::class));
    }

    public function testS3ClientArgumentsWithoutKeysEqual814(): void
    {
        $client = self::scoresClient(self::load(['storages' => ['scores' => self::SCORES_S3]]));

        self::assertSame(S3Client::class, $client->getClass());
        self::assertSame([self::CLIENT_ARGUMENTS_8_1_4], $client->getArguments());
        self::assertNull($client->getConfigurator());
        self::assertSame([], $client->getMethodCalls());
    }

    public function testS3ClientArgumentsWithHttpAndRetries(): void
    {
        $client = self::scoresClient(self::load(['storages' => ['scores' => self::SCORES_S3 + ['http' => self::HTTP, 'retries' => self::RETRIES]]]));

        self::assertSame([self::CLIENT_ARGUMENTS_8_1_4 + [
            'http' => self::HTTP_ARGUMENT,
            'retries' => ['mode' => 'standard', 'max_attempts' => 2],
        ]], $client->getArguments());
        self::assertSame([S3RedirectGuard::class, 'register'], $client->getConfigurator());
    }

    public function testOnlyRetriesBlockPassesOnlyRetries(): void
    {
        $client = self::scoresClient(self::load(['storages' => ['scores' => self::SCORES_S3 + ['retries' => self::RETRIES]]]));

        self::assertSame([self::CLIENT_ARGUMENTS_8_1_4 + [
            'retries' => ['mode' => 'standard', 'max_attempts' => 2],
        ]], $client->getArguments());
    }

    public function testOnlyHttpBlockPassesOnlyHttp(): void
    {
        $client = self::scoresClient(self::load(['storages' => ['scores' => self::SCORES_S3 + ['http' => self::HTTP]]]));

        self::assertSame([self::CLIENT_ARGUMENTS_8_1_4 + [
            'http' => self::HTTP_ARGUMENT,
        ]], $client->getArguments());
    }

    public function testHttpAddsAllowRedirectsFalseAndRedirectGuard(): void
    {
        $client = self::scoresClient(self::load(['storages' => ['scores' => self::SCORES_S3 + ['http' => self::HTTP]]]));
        $arguments = $client->getArgument(0);
        \assert(\is_array($arguments));

        self::assertSame(self::HTTP_ARGUMENT, $arguments['http']);
        self::assertSame([S3RedirectGuard::class, 'register'], $client->getConfigurator());
        self::assertSame([], $client->getMethodCalls());
    }

    public function testRetriesOnlyHasNoRedirectHandling(): void
    {
        $client = self::scoresClient(self::load(['storages' => ['scores' => self::SCORES_S3 + ['retries' => self::RETRIES]]]));

        self::assertSame([self::CLIENT_ARGUMENTS_8_1_4 + ['retries' => ['mode' => 'standard', 'max_attempts' => 2]]], $client->getArguments());
        self::assertNull($client->getConfigurator());
    }

    /**
     * On a built client the guard is one `init` step, outside `retry` and `s3.permanent_redirect` in `sign` (spec §3.3);
     * a storage without `http` has no guard.
     */
    public function testRedirectGuardIsOneInitStepOutsideRetry(): void
    {
        $container = self::load(['storages' => [
            'scores' => self::SCORES_S3 + ['http' => self::HTTP, 'retries' => self::RETRIES],
            'etudes' => self::SCORES_S3,
        ]]);
        foreach (['scores', 'etudes'] as $name) {
            $container->getDefinition('chamber_orchestra_file.storage.'.$name)->setPublic(true);
        }
        $container->compile();

        $guarded = self::handlerSteps($container, 'scores');
        self::assertSame(['init'], $guarded[S3RedirectGuard::NAME] ?? null);
        self::assertSame(['sign'], $guarded['retry'] ?? null);
        self::assertSame(['sign'], $guarded['s3.permanent_redirect'] ?? null);
        self::assertArrayNotHasKey(S3RedirectGuard::NAME, self::handlerSteps($container, 'etudes'));
    }

    public function testFileSystemStorageWithKeysRegistersNoClient(): void
    {
        $container = self::load(['storages' => ['scores' => [
            'driver' => 'file_system',
            'path' => '/var/scores',
            'http' => self::HTTP,
            'retries' => self::RETRIES,
        ]]]);

        self::assertFileSystemStorageWithoutClient($container);
    }

    public function testMergedS3BaseWithFileSystemOverride(): void
    {
        $container = self::load(
            ['storages' => ['scores' => self::SCORES_S3 + ['http' => self::HTTP, 'retries' => self::RETRIES]]],
            ['storages' => ['scores' => ['driver' => 'file_system', 'path' => '/var/scores']]],
        );

        self::assertFileSystemStorageWithoutClient($container);
    }

    /**
     * @param array<string, mixed> ...$configs
     */
    private static function load(array ...$configs): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $container->setParameter('kernel.project_dir', '/project');

        new ChamberOrchestraFileExtension()->load($configs, $container);

        return $container;
    }

    /**
     * The handler-list steps of a built storage's client, by middleware name.
     *
     * @return array<string, list<string>>
     */
    private static function handlerSteps(ContainerBuilder $container, string $storage): array
    {
        $client = new \ReflectionProperty(S3Storage::class, 'client')->getValue($container->get('chamber_orchestra_file.storage.'.$storage));
        \assert($client instanceof S3Client);
        \preg_match_all('/Step: (\w+), Name: ([^,]+),/', (string) $client->getHandlerList(), $matches, \PREG_SET_ORDER);
        $steps = [];
        foreach ($matches as [, $step, $name]) {
            $steps[$name][] = $step;
        }

        return $steps;
    }

    private static function scoresClient(ContainerBuilder $container): Definition
    {
        return $container->getDefinition(self::SCORES_CLIENT);
    }

    private static function assertFileSystemStorageWithoutClient(ContainerBuilder $container): void
    {
        $storage = $container->getDefinition('chamber_orchestra_file.storage.scores');

        self::assertSame(FileSystemStorage::class, $storage->getClass());
        self::assertSame(['/var/scores', null], $storage->getArguments());
        self::assertFalse($container->hasDefinition(self::SCORES_CLIENT));
    }
}
