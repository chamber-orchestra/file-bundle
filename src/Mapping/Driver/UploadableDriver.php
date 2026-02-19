<?php

declare(strict_types=1);

namespace Dev\FileBundle\Mapping\Driver;

use Dev\FileBundle\Exception\ORM\MappingException;
use Dev\FileBundle\Mapping\Annotation\Uploadable;
use Dev\FileBundle\Mapping\Annotation\UploadableProperty;
use Dev\FileBundle\Mapping\Configuration\UploadableConfiguration;
use Dev\FileBundle\NamingStrategy\NamingStrategyInterface;
use Dev\MetadataBundle\Mapping\Driver\AbstractMappingDriver;
use Dev\MetadataBundle\Mapping\ExtensionMetadataInterface;
use Dev\MetadataBundle\Mapping\ORM\AbstractMetadataConfiguration;
use Dev\MetadataBundle\Mapping\ORM\ExtensionMetadata;

class UploadableDriver extends AbstractMappingDriver
{
    public function loadMetadataForClass(ExtensionMetadataInterface $extensionMetadata): void
    {
        /** @var ExtensionMetadata $extensionMetadata */
        $class = $extensionMetadata->getOriginMetadata()->getReflectionClass();
        $className = $extensionMetadata->getName();
        /** @var Uploadable $uploadableClass */
        $uploadableClass = $this->reader->getClassAttribute($class, Uploadable::class);

        if (null !== $uploadableClass
            && !\is_subclass_of($uploadableClass->namingStrategy, NamingStrategyInterface::class)) {
            throw MappingException::namingStrategyIsNotValidInstance($className, $uploadableClass->namingStrategy);
        }

        $config = new UploadableConfiguration($uploadableClass);

        foreach ($class->getProperties() as $property) {
            /** @var UploadableProperty $uploadableField */
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

            //should be mapped, to add correct access
            $config->mapField($uploadableField->mappedBy, [
                'inversedBy' => $name,
            ]);
        }

        $this->joinEmbeddedConfigurations($config, $extensionMetadata, ['mappedBy', 'inversedBy']);
        $extensionMetadata->addConfiguration($config);
    }

    private function joinEmbeddedConfigurations(AbstractMetadataConfiguration $config, ExtensionMetadataInterface $metadata, array $prefixKeys = [], string $parentFieldName = ''): void
    {
        $name = \get_class($config);
        foreach ($metadata->getEmbeddedMetadataWithConfiguration($name) as $fieldName => $embedded) {
            $embeddedConfig = $embedded->getConfiguration($name);

            $baseFieldName = $parentFieldName . $fieldName;
            foreach ($embeddedConfig->getMappings() as $embeddedFieldName => $mapping) {
                foreach ($prefixKeys as $key) {
                    if (isset($mapping[$key])) {
                        $mapping[$key] = $baseFieldName . '.' . $mapping[$key];
                    }
                }
                $config->mapEmbeddedField($embedded->getName(), $baseFieldName, $embeddedFieldName, $mapping);
            }

            $this->joinEmbeddedConfigurations($config, $embedded, $prefixKeys, $baseFieldName . '.');
        }
    }

    protected function getPropertyAnnotation(): string
    {
        return UploadableProperty::class;
    }

    protected function supportsEmbedded(): bool
    {
        return true;
    }
}
