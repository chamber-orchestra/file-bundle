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
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\Config\Definition\Processor;

class ConfigurationTest extends TestCase
{
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
}
