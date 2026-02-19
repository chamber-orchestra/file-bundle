<?php

declare(strict_types=1);

/*
 * This file is part of the ChamberOrchestra package.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Tests\Unit\Mapping\Driver;

use ChamberOrchestra\FileBundle\Exception\InvalidArgumentException;
use ChamberOrchestra\FileBundle\Mapping\Configuration\UploadableConfiguration;
use ChamberOrchestra\FileBundle\Mapping\Driver\UploadableDriver;
use ChamberOrchestra\MetadataBundle\Exception\MappingException as BaseMappingException;
use ChamberOrchestra\MetadataBundle\Mapping\ExtensionMetadataInterface;
use ChamberOrchestra\MetadataBundle\Reader\AttributeReader;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\Persistence\Mapping\RuntimeReflectionService;
use PHPUnit\Framework\TestCase;
use Tests\Fixtures\Entity\NonUploadableEntity;
use Tests\Fixtures\Entity\UploadableEntity;

class UploadableDriverTest extends TestCase
{
    private UploadableDriver $driver;

    protected function setUp(): void
    {
        $this->driver = new UploadableDriver(new AttributeReader());
    }

    private function createClassMetadata(string $class): ClassMetadata
    {
        $classMetadata = new ClassMetadata($class);
        $classMetadata->initializeReflection(new RuntimeReflectionService());

        return $classMetadata;
    }

    public function testLoadMetadataWithUploadableEntity(): void
    {
        $classMetadata = $this->createClassMetadata(UploadableEntity::class);
        $metadata = $this->createMock(ExtensionMetadataInterface::class);
        $metadata->method('getOriginMetadata')->willReturn($classMetadata);
        $metadata->method('getName')->willReturn(UploadableEntity::class);
        $metadata->method('getEmbeddedMetadataWithConfiguration')->willReturn([]);

        $metadata->expects(self::once())
            ->method('addConfiguration')
            ->with(self::callback(function (UploadableConfiguration $config): bool {
                $uploadFields = $config->getUploadableFieldNames();
                self::assertSame(['file' => 'file'], $uploadFields);

                $mappedFields = $config->getMappedByFieldNames();
                self::assertSame(['filePath' => 'filePath'], $mappedFields);

                $fileMapping = $config->getMapping('file');
                self::assertTrue($fileMapping['upload']);
                self::assertSame('filePath', $fileMapping['mappedBy']);

                $pathMapping = $config->getMapping('filePath');
                self::assertSame('file', $pathMapping['inversedBy']);

                return true;
            }));

        $this->driver->loadMetadataForClass($metadata);
    }

    public function testLoadMetadataWithNonUploadableEntity(): void
    {
        $classMetadata = $this->createClassMetadata(NonUploadableEntity::class);
        $metadata = $this->createMock(ExtensionMetadataInterface::class);
        $metadata->method('getOriginMetadata')->willReturn($classMetadata);
        $metadata->method('getName')->willReturn(NonUploadableEntity::class);
        $metadata->method('getEmbeddedMetadataWithConfiguration')->willReturn([]);

        $metadata->expects(self::once())
            ->method('addConfiguration')
            ->with(self::callback(function (UploadableConfiguration $config): bool {
                self::assertSame([], $config->getUploadableFieldNames());

                return true;
            }));

        $this->driver->loadMetadataForClass($metadata);
    }

    public function testThrowsForInvalidNamingStrategy(): void
    {
        if (!\class_exists(InvalidStrategyEntity::class, false)) {
            eval(<<<'PHP'
                namespace Tests\Unit\Mapping\Driver;

                use ChamberOrchestra\FileBundle\Mapping\Attribute as CO;
                use Doctrine\ORM\Mapping as ORM;
                use Symfony\Component\HttpFoundation\File\File;

                #[CO\Uploadable(prefix: 'test', namingStrategy: \stdClass::class)]
                class InvalidStrategyEntity
                {
                    #[ORM\Id]
                    #[ORM\Column(type: 'integer')]
                    private ?int $id = null;

                    #[CO\UploadableProperty(mappedBy: 'filePath')]
                    private File|null $file = null;

                    private string|null $filePath = null;
                }
                PHP);
        }

        $classMetadata = $this->createClassMetadata(InvalidStrategyEntity::class);
        $metadata = $this->createMock(ExtensionMetadataInterface::class);
        $metadata->method('getOriginMetadata')->willReturn($classMetadata);
        $metadata->method('getName')->willReturn(InvalidStrategyEntity::class);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('namingStrategy');

        $this->driver->loadMetadataForClass($metadata);
    }

    public function testThrowsForMissingMappedByProperty(): void
    {
        if (!\class_exists(MissingMappedByEntity::class, false)) {
            eval(<<<'PHP'
                namespace Tests\Unit\Mapping\Driver;

                use ChamberOrchestra\FileBundle\Mapping\Attribute as CO;
                use Doctrine\ORM\Mapping as ORM;
                use Symfony\Component\HttpFoundation\File\File;

                #[CO\Uploadable(prefix: 'test')]
                class MissingMappedByEntity
                {
                    #[ORM\Id]
                    #[ORM\Column(type: 'integer')]
                    private ?int $id = null;

                    #[CO\UploadableProperty(mappedBy: 'nonExistentField')]
                    private File|null $file = null;
                }
                PHP);
        }

        $classMetadata = $this->createClassMetadata(MissingMappedByEntity::class);
        $metadata = $this->createMock(ExtensionMetadataInterface::class);
        $metadata->method('getOriginMetadata')->willReturn($classMetadata);
        $metadata->method('getName')->willReturn(MissingMappedByEntity::class);

        $this->expectException(BaseMappingException::class);

        $this->driver->loadMetadataForClass($metadata);
    }
}
