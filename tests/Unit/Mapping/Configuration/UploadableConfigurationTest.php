<?php

declare(strict_types=1);

/*
 * This file is part of the ChamberOrchestra package.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Tests\Unit\Mapping\Configuration;

use ChamberOrchestra\FileBundle\Mapping\Attribute\Uploadable;
use ChamberOrchestra\FileBundle\Mapping\Configuration\UploadableConfiguration;
use ChamberOrchestra\FileBundle\Mapping\Helper\Behaviour;
use ChamberOrchestra\FileBundle\NamingStrategy\HashingNamingStrategy;
use ChamberOrchestra\FileBundle\NamingStrategy\OriginNamingStrategy;
use PHPUnit\Framework\TestCase;

class UploadableConfigurationTest extends TestCase
{
    public function testConstructorWithAnnotation(): void
    {
        $annotation = new Uploadable(
            prefix: 'images',
            namingStrategy: OriginNamingStrategy::class,
            behaviour: Behaviour::Keep,
        );

        $config = new UploadableConfiguration($annotation);

        self::assertSame('images', $config->getPrefix());
        self::assertSame(Behaviour::Keep, $config->getBehaviour());
        self::assertSame(OriginNamingStrategy::class, $config->getNamingStrategy());
    }

    public function testConstructorWithNull(): void
    {
        $config = new UploadableConfiguration(null);

        self::assertSame('', $config->getPrefix());
        self::assertSame(Behaviour::Remove, $config->getBehaviour());
        self::assertSame(HashingNamingStrategy::class, $config->getNamingStrategy());
    }

    public function testGetUploadableFieldNames(): void
    {
        $config = new UploadableConfiguration(new Uploadable(prefix: 'test'));
        $config->mapField('file', ['upload' => true, 'mappedBy' => 'filePath']);
        $config->mapField('filePath', ['inversedBy' => 'file']);

        $uploadable = $config->getUploadableFieldNames();

        self::assertSame(['file' => 'file'], $uploadable);
    }

    public function testGetMappedByFieldNames(): void
    {
        $config = new UploadableConfiguration(new Uploadable(prefix: 'test'));
        $config->mapField('file', ['upload' => true, 'mappedBy' => 'filePath']);
        $config->mapField('filePath', ['inversedBy' => 'file']);

        $mappedBy = $config->getMappedByFieldNames();

        self::assertSame(['filePath' => 'filePath'], $mappedBy);
    }

    public function testSerializeUnserialize(): void
    {
        $annotation = new Uploadable(
            prefix: 'photos',
            namingStrategy: OriginNamingStrategy::class,
            behaviour: Behaviour::Keep,
        );

        $config = new UploadableConfiguration($annotation);
        $config->mapField('file', ['upload' => true, 'mappedBy' => 'filePath']);
        $config->mapField('filePath', ['inversedBy' => 'file']);

        $serialized = \serialize($config);
        /** @var UploadableConfiguration $restored */
        $restored = \unserialize($serialized);

        self::assertSame('photos', $restored->getPrefix());
        self::assertSame(Behaviour::Keep, $restored->getBehaviour());
        self::assertSame(OriginNamingStrategy::class, $restored->getNamingStrategy());
        self::assertSame(['file' => 'file'], $restored->getUploadableFieldNames());
        self::assertSame(['filePath' => 'filePath'], $restored->getMappedByFieldNames());
    }

    public function testSerializeUnserializePreservesMappedByFieldsNames(): void
    {
        $config = new UploadableConfiguration(new Uploadable(prefix: 'test'));
        $config->mapField('file', ['upload' => true, 'mappedBy' => 'filePath']);
        $config->mapField('filePath', ['inversedBy' => 'file']);

        // Force lazy computation of mappedByFieldsNames before serialization
        $config->getMappedByFieldNames();

        $restored = \unserialize(\serialize($config));

        self::assertSame(['filePath' => 'filePath'], $restored->getMappedByFieldNames());
    }
}
