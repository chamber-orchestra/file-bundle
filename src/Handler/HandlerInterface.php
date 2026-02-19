<?php

declare(strict_types=1);

namespace Dev\FileBundle\Handler;

use Dev\MetadataBundle\Mapping\ExtensionMetadataInterface;

interface HandlerInterface
{
    /**
     * 
     */
    public function notify(ExtensionMetadataInterface $metadata, object $entity, string $fieldName): void;

    /**
     * Set path property to correct value.
     */
    public function update(ExtensionMetadataInterface $metadata, object $entity, string $fieldName): void;

    /**
     * Handle uploads of the entity.
     */
    public function upload(ExtensionMetadataInterface $metadata, object $object, string $fieldName): void;

    /**
     * Remove file, after entity deletion or update.
     */
    public function remove(object $entity, string|null $relativePath): void;

    /**
     * Injects File Object in entity
     * Only after load from doctrine.
     */
    public function inject(ExtensionMetadataInterface $metadata, object $object, string $fieldName): void;
}
