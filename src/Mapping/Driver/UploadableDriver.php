<?php

declare(strict_types=1);

/*
 * This file is part of the ChamberOrchestra package.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace ChamberOrchestra\FileBundle\Mapping\Driver;

use ChamberOrchestra\FileBundle\Exception\ORM\MappingException;
use ChamberOrchestra\FileBundle\Mapping\Attribute\Uploadable;
use ChamberOrchestra\FileBundle\Mapping\Attribute\UploadableProperty;
use ChamberOrchestra\FileBundle\Mapping\Configuration\UploadableConfiguration;
use ChamberOrchestra\MetadataBundle\Mapping\Driver\AbstractMappingDriver;
use ChamberOrchestra\MetadataBundle\Mapping\ExtensionMetadataInterface;
use ChamberOrchestra\MetadataBundle\Mapping\ORM\AbstractMetadataConfiguration;
use ChamberOrchestra\MetadataBundle\Mapping\ORM\ExtensionMetadata;

class UploadableDriver extends AbstractMappingDriver
{
    public function loadMetadataForClass(ExtensionMetadataInterface $extensionMetadata): void
    {
        /** @var ExtensionMetadata $extensionMetadata */
        $class = $extensionMetadata->getOriginMetadata()->getReflectionClass();
        $className = $extensionMetadata->getName();
        /** @var Uploadable|null $uploadableClass */
        $uploadableClass = $this->reader->getClassAttribute($class, Uploadable::class);

        $config = new UploadableConfiguration($uploadableClass);

        foreach ($class->getProperties() as $property) {
            /** @var UploadableProperty|null $uploadableField */
            $uploadableField = $this->reader->getPropertyAttribute($property, UploadableProperty::class);
            if (null === $uploadableField) {
                continue;
            }

            if (!$class->hasProperty($uploadableField->mappedBy)) {
                throw MappingException::missingProperty($className, $uploadableField->mappedBy, $property->getName());
            }

            $config->mapField($name = $property->getName(), [
                'upload' => true,
                'mappedBy' => $uploadableField->mappedBy,
            ]);

            // should be mapped, to add correct access
            $config->mapField($uploadableField->mappedBy, [
                'inversedBy' => $name,
            ]);
        }

        $this->joinEmbeddedConfigurations($config, $extensionMetadata, ['mappedBy', 'inversedBy']);
        $extensionMetadata->addConfiguration($config);
    }

    /**
     * @param list<string> $prefixKeys
     */
    private function joinEmbeddedConfigurations(AbstractMetadataConfiguration $config, ExtensionMetadataInterface $metadata, array $prefixKeys = [], string $parentFieldName = ''): void
    {
        $name = \get_class($config);
        foreach ($metadata->getEmbeddedMetadataWithConfiguration($name) as $fieldName => $embedded) {
            $embeddedConfig = $embedded->getConfiguration($name);

            if (null === $embeddedConfig) {
                continue;
            }

            $baseFieldName = $parentFieldName.$fieldName;
            foreach ($embeddedConfig->getMappings() as $embeddedFieldName => $mapping) {
                foreach ($prefixKeys as $key) {
                    if (isset($mapping[$key]) && \is_string($mapping[$key])) {
                        $mapping[$key] = $baseFieldName.'.'.$mapping[$key];
                    }
                }
                $config->mapEmbeddedField($embedded->getName(), $baseFieldName, $embeddedFieldName, $mapping);
            }

            $this->joinEmbeddedConfigurations($config, $embedded, $prefixKeys, $baseFieldName.'.');
        }
    }

    protected function getPropertyAttribute(): string
    {
        return UploadableProperty::class;
    }

    protected function supportsEmbedded(): bool
    {
        return true;
    }
}
