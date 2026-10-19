<?php

declare(strict_types=1);

/*
 * This file is part of the ChamberOrchestra package.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Tests\Unit\DependencyInjection;

use ChamberOrchestra\FileBundle\DependencyInjection\Configuration;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\Config\Definition\Processor;

class ConfigurationTest extends TestCase
{
    private const string SCORES_PATH = 'chamber_orchestra_file.storages.scores';
    private const array HTTP = ['connect_timeout' => 5, 'timeout' => 10];
    private const array RETRIES = ['mode' => 'standard', 'max_attempts' => 2];

    public function testRootNodeName(): void
    {
        $configuration = new Configuration();
        $tree = $configuration->getConfigTreeBuilder();

        self::assertSame('chamber_orchestra_file', $tree->buildTree()->getName());
    }

    public function testDefaultStorageValues(): void
    {
        $processor = new Processor();
        $config = $processor->processConfiguration(new Configuration(), [
            ['storages' => ['default' => []]],
        ]);

        self::assertSame('file_system', $config['storages']['default']['driver']);
        self::assertSame('%kernel.project_dir%/public/uploads', $config['storages']['default']['path']);
        self::assertNull($config['storages']['default']['uri_prefix']);
    }

    public function testInvalidStorageDriverThrows(): void
    {
        $this->expectException(InvalidConfigurationException::class);

        $processor = new Processor();
        $processor->processConfiguration(new Configuration(), [
            ['storages' => ['default' => ['driver' => 'ftp']]],
        ]);
    }

    public function testS3StorageDriverIsValid(): void
    {
        $processor = new Processor();
        $config = $processor->processConfiguration(new Configuration(), [
            ['storages' => ['media' => ['driver' => 's3', 'bucket' => 'my-bucket', 'region' => 'us-east-1']]],
        ]);

        self::assertSame('s3', $config['storages']['media']['driver']);
        self::assertSame('my-bucket', $config['storages']['media']['bucket']);
        self::assertSame('us-east-1', $config['storages']['media']['region']);
    }

    public function testS3WithoutBucketThrows(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('"bucket" option is required');

        $processor = new Processor();
        $processor->processConfiguration(new Configuration(), [
            ['storages' => ['cdn' => ['driver' => 's3', 'region' => 'us-east-1']]],
        ]);
    }

    public function testS3WithoutRegionThrows(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('"region" option is required');

        $processor = new Processor();
        $processor->processConfiguration(new Configuration(), [
            ['storages' => ['cdn' => ['driver' => 's3', 'bucket' => 'my-bucket']]],
        ]);
    }

    public function testS3EndpointDefaultsToNull(): void
    {
        $processor = new Processor();
        $config = $processor->processConfiguration(new Configuration(), [
            ['storages' => ['cdn' => ['driver' => 's3', 'bucket' => 'my-bucket', 'region' => 'us-east-1']]],
        ]);

        self::assertNull($config['storages']['cdn']['endpoint']);
    }

    public function testDriverNormalizesToLowercase(): void
    {
        $processor = new Processor();
        $config = $processor->processConfiguration(new Configuration(), [
            ['storages' => ['default' => ['driver' => 'FILE_SYSTEM']]],
        ]);

        self::assertSame('file_system', $config['storages']['default']['driver']);
    }

    public function testMultipleStorages(): void
    {
        $processor = new Processor();
        $config = $processor->processConfiguration(new Configuration(), [
            ['storages' => [
                'public' => ['driver' => 'file_system', 'path' => '/public/uploads', 'uri_prefix' => '/uploads'],
                'private' => ['driver' => 'file_system', 'path' => '/var/share'],
            ]],
        ]);

        self::assertCount(2, $config['storages']);
        self::assertSame('/uploads', $config['storages']['public']['uri_prefix']);
        self::assertNull($config['storages']['private']['uri_prefix']);
    }

    public function testDefaultStorageCanBeSpecified(): void
    {
        $processor = new Processor();
        $config = $processor->processConfiguration(new Configuration(), [
            [
                'default_storage' => 'private',
                'storages' => [
                    'public' => ['driver' => 'file_system'],
                    'private' => ['driver' => 'file_system', 'path' => '/var/share'],
                ],
            ],
        ]);

        self::assertSame('private', $config['default_storage']);
    }

    public function testStorageCanBeDisabled(): void
    {
        $processor = new Processor();
        $config = $processor->processConfiguration(new Configuration(), [
            ['storages' => [
                'public' => ['driver' => 'file_system'],
                'staging' => ['enabled' => false],
            ]],
        ]);

        self::assertTrue($config['storages']['public']['enabled']);
        self::assertFalse($config['storages']['staging']['enabled']);
    }

    /**
     * @return iterable<string, array{array<string, int|float>, array<string, float>, array<string, int|string>}>
     */
    public static function acceptedHttpAndRetries(): iterable
    {
        yield 'integer literals (D-13), standard' => [self::HTTP, ['connect_timeout' => 5.0, 'timeout' => 10.0], self::RETRIES];
        yield 'float literals, legacy' => [['connect_timeout' => 0.25, 'timeout' => 0.5], ['connect_timeout' => 0.25, 'timeout' => 0.5], ['mode' => 'legacy', 'max_attempts' => 1]];
        yield 'adaptive' => [['connect_timeout' => 1.5, 'timeout' => 30], ['connect_timeout' => 1.5, 'timeout' => 30.0], ['mode' => 'adaptive', 'max_attempts' => 3]];
    }

    /**
     * @param array<string, int|float>  $http
     * @param array<string, float>      $expectedHttp
     * @param array<string, int|string> $retries
     */
    #[DataProvider('acceptedHttpAndRetries')]
    public function testS3HttpAndRetriesAccepted(array $http, array $expectedHttp, array $retries): void
    {
        $storage = self::processScores(self::s3(['http' => $http, 'retries' => $retries]));

        self::assertSame($expectedHttp, $storage['http']);
        self::assertSame($retries, $storage['retries']);
    }

    /**
     * @return iterable<string, array{array<string, int>|null}>
     */
    public static function incompleteHttpBlocks(): iterable
    {
        yield 'no connect_timeout' => [['timeout' => 10]];
        yield 'no timeout' => [['connect_timeout' => 5]];
        yield 'null block' => [null];
        yield 'empty block' => [[]];
    }

    /**
     * @param array<string, int>|null $http
     */
    #[DataProvider('incompleteHttpBlocks')]
    public function testHttpBlockRequiresEverySubkey(?array $http): void
    {
        $this->expectRejectionAt(self::SCORES_PATH.'.http');

        self::processScores(self::s3(['http' => $http]));
    }

    /**
     * @return iterable<string, array{array<string, int|string>}>
     */
    public static function incompleteRetriesBlocks(): iterable
    {
        yield 'no mode' => [['max_attempts' => 2]];
        yield 'no max_attempts' => [['mode' => 'standard']];
        yield 'empty block' => [[]];
    }

    /**
     * @param array<string, int|string> $retries
     */
    #[DataProvider('incompleteRetriesBlocks')]
    public function testRetriesBlockRequiresEverySubkey(array $retries): void
    {
        $this->expectRejectionAt(self::SCORES_PATH.'.retries');

        self::processScores(self::s3(['retries' => $retries]));
    }

    /**
     * @return iterable<string, array{string, mixed}>
     */
    public static function invalidHttpValues(): iterable
    {
        foreach (['connect_timeout', 'timeout'] as $key) {
            foreach (['zero' => 0, 'negative' => -1, 'non-numeric string' => 'abc', 'boolean' => true] as $label => $value) {
                yield $key.': '.$label => [$key, $value];
            }
        }
    }

    #[DataProvider('invalidHttpValues')]
    public function testHttpRejectsInvalidValues(string $key, mixed $value): void
    {
        $this->expectRejectionAt(self::SCORES_PATH.'.http.'.$key);

        self::processScores(self::s3(['http' => [$key => $value] + self::HTTP]));
    }

    /**
     * @return iterable<string, array{string, mixed}>
     */
    public static function invalidRetriesValues(): iterable
    {
        yield 'mode outside the list' => ['mode', 'fast'];
        yield 'max_attempts zero' => ['max_attempts', 0];
        yield 'max_attempts fractional' => ['max_attempts', 1.5];
        yield 'max_attempts numeric string' => ['max_attempts', '2'];
    }

    #[DataProvider('invalidRetriesValues')]
    public function testRetriesRejectsInvalidValues(string $key, mixed $value): void
    {
        $this->expectRejectionAt(self::SCORES_PATH.'.retries.'.$key);

        self::processScores(self::s3(['retries' => [$key => $value] + self::RETRIES]));
    }

    /**
     * @return iterable<string, array{string, array<string, mixed>}>
     */
    public static function unknownSubkeys(): iterable
    {
        yield 'http.read_timeout' => ['http', self::HTTP + ['read_timeout' => 30]];
        yield 'http.curl' => ['http', self::HTTP + ['curl' => ['forbid_reuse' => true]]];
        yield 'retries.delay' => ['retries', self::RETRIES + ['delay' => 100]];
    }

    /**
     * @param array<string, mixed> $block
     */
    #[DataProvider('unknownSubkeys')]
    public function testUnknownSubkeyRejected(string $blockName, array $block): void
    {
        $this->expectRejectionAt(self::SCORES_PATH.'.'.$blockName);

        self::processScores(self::s3([$blockName => $block]));
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function rejectedKeyPaths(): iterable
    {
        yield 'http' => [['http' => ['timeout' => -1] + self::HTTP], 'chamber_orchestra_file.storages.scores.http.timeout'];
        yield 'retries' => [['retries' => ['max_attempts' => 0] + self::RETRIES], 'chamber_orchestra_file.storages.scores.retries.max_attempts'];
    }

    /**
     * @param array<string, mixed> $blocks
     */
    #[DataProvider('rejectedKeyPaths')]
    public function testErrorMessageNamesKeyPath(array $blocks, string $keyPath): void
    {
        try {
            self::processScores(self::s3($blocks));
            self::fail('Expected an InvalidConfigurationException.');
        } catch (InvalidConfigurationException $e) {
            self::assertStringContainsString('"'.$keyPath.'"', $e->getMessage());
        }
    }

    public function testFileSystemStorageAcceptsHttpAndRetries(): void
    {
        $storage = self::processScores(['driver' => 'file_system', 'http' => self::HTTP, 'retries' => self::RETRIES]);

        self::assertSame('file_system', $storage['driver']);
        self::assertSame(['connect_timeout' => 5.0, 'timeout' => 10.0], $storage['http']);
        self::assertSame(self::RETRIES, $storage['retries']);
    }

    public function testAbsentBlocksStayAbsent(): void
    {
        foreach ([self::s3(), ['driver' => 'file_system']] as $storage) {
            $processed = self::processScores($storage);

            self::assertArrayNotHasKey('http', $processed);
            self::assertArrayNotHasKey('retries', $processed);
        }
    }

    /**
     * @param array<string, mixed> $extra
     *
     * @return array<string, mixed>
     */
    private static function s3(array $extra = []): array
    {
        return ['driver' => 's3', 'bucket' => 'orchestra-scores', 'region' => 'eu-west-2'] + $extra;
    }

    /**
     * @param array<string, mixed> $storage
     *
     * @return array<string, mixed>
     */
    private static function processScores(array $storage): array
    {
        $processor = new Processor();
        /** @var array{storages: array{scores: array<string, mixed>}} $config */
        $config = $processor->processConfiguration(new Configuration(), [
            ['storages' => ['scores' => $storage]],
        ]);

        return $config['storages']['scores'];
    }

    private function expectRejectionAt(string $keyPath): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('"'.$keyPath);
    }
}
