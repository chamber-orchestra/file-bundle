<?php

declare(strict_types=1);

namespace Dev\FileBundle\Handler;

use Dev\FileBundle\Events\PostRemoveEvent;
use Dev\FileBundle\Events\PreRemoveEvent;
use Dev\FileBundle\Exception\RuntimeException;
use Dev\FileBundle\Mapping\Configuration\UploadableConfiguration;
use Dev\FileBundle\Model\File;
use Dev\FileBundle\NamingStrategy\NamingStrategyFactory;
use Dev\MetadataBundle\Mapping\ExtensionMetadataInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;

class Handler extends AbstractHandler
{
    public function notify(ExtensionMetadataInterface $metadata, object $entity, string $fieldName): void
    {
        $file = $metadata->getFieldValue($entity, $fieldName);
        if (null !== $file && !$file instanceof \Symfony\Component\HttpFoundation\File\File) {
            return;
        }

        $config = $this->getConfig($metadata);
        $mapping = $config->getMapping($fieldName);

        // if the path will be the same, uow changeSet will be empty
        $path = (null !== $file && $file->isFile()) ? $this->storage->resolveRelativePath($file->getRealPath(), $config->getPrefix()) : null;
        $metadata->setFieldValue($entity, $mapping['mappedBy'], $path);
    }

    public function update(ExtensionMetadataInterface $metadata, object $object, string $fieldName): void
    {
        $config = $this->getConfig($metadata);
        $mapping = $config->getMapping($fieldName);
        /** @var \Symfony\Component\HttpFoundation\File\File $file */
        $file = $metadata->getFieldValue($object, $mapping['inversedBy']);

        if (null === $file || !$file->isFile()) {
            $metadata->setFieldValue($object, $fieldName, null);

            return;
        }

        if (!$file instanceof \Symfony\Component\HttpFoundation\File\File) {
            throw new RuntimeException(sprintf("The file is not instance of '%s'. Not internal usage", File::class));
        }

        $relativePath = $this->storage->resolveRelativePath($file->getRealPath(), $config->getPrefix());
        $metadata->setFieldValue($object, $fieldName, $relativePath);
    }

    public function upload(ExtensionMetadataInterface $metadata, object $object, string $fieldName): void
    {
        $config = $this->getConfig($metadata);
        $mapping = $config->getMapping($fieldName);
        /** @var \Symfony\Component\HttpFoundation\File\File $file */
        $file = $metadata->getFieldValue($object, $mapping['inversedBy']);

        if (!$file instanceof \Symfony\Component\HttpFoundation\File\File) {
            throw new RuntimeException(sprintf("The uploaded file is not instance of '%s'. Not internal usage", UploadedFile::class));
        }

        $namingStrategy = NamingStrategyFactory::create($config->getNamingStrategy());
        $relativePath = $this->storage->upload($file, $namingStrategy, $config->getPrefix());

        $file = $this->storage->resolvePath($relativePath);
        $uri = $this->storage->resolveUri($relativePath);

        $file = new File($file, $uri);
        $metadata->setFieldValue($object, $mapping['inversedBy'], $file);
    }

    public function remove(object $object, string|null $relativePath): void
    {
        if (null === $relativePath) {
            return;
        }

        $resolvedPath = $this->storage->resolvePath($relativePath);
        $resolvedUri = $this->storage->resolveUri($relativePath);

        $this->dispatcher->dispatch(new PreRemoveEvent($relativePath, $resolvedPath, $resolvedUri));
        $this->storage->remove($resolvedPath);
        $this->dispatcher->dispatch(new PostRemoveEvent($relativePath, $resolvedPath, $resolvedUri));
    }

    public function inject(ExtensionMetadataInterface $metadata, object $object, string $fieldName): void
    {
        $config = $this->getConfig($metadata);
        $mapping = $config->getMapping($fieldName);

        /** @var string $relativePath */
        $relativePath = $metadata->getFieldValue($object, $mapping['mappedBy']);

        if (null === $relativePath) {
            $metadata->setFieldValue($object, $fieldName, null);

            return;
        }

        $path = $this->storage->resolvePath($relativePath);
        $uri = $this->storage->resolveUri($relativePath);

        $file = new File($path, $uri);
        $metadata->setFieldValue($object, $fieldName, $file);
    }

    private function getConfig(ExtensionMetadataInterface $metadata): ?UploadableConfiguration
    {
        /** @var UploadableConfiguration $config */
        $config = $metadata->getConfiguration(UploadableConfiguration::class);

        return $config;
    }
}
