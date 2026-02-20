<?php

declare(strict_types=1);

/*
 * This file is part of the ChamberOrchestra package.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace ChamberOrchestra\FileBundle\Handler;

use ChamberOrchestra\FileBundle\Events\PostRemoveEvent;
use ChamberOrchestra\FileBundle\Events\PostUploadEvent;
use ChamberOrchestra\FileBundle\Events\PreRemoveEvent;
use ChamberOrchestra\FileBundle\Events\PreUploadEvent;
use ChamberOrchestra\FileBundle\Exception\RuntimeException;
use ChamberOrchestra\FileBundle\Mapping\Configuration\UploadableConfiguration;
use ChamberOrchestra\FileBundle\Model\File;
use ChamberOrchestra\FileBundle\NamingStrategy\NamingStrategyFactory;
use ChamberOrchestra\FileBundle\Storage\StorageInterface;
use ChamberOrchestra\FileBundle\Storage\StorageResolver;
use ChamberOrchestra\MetadataBundle\Mapping\ExtensionMetadataInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

class Handler
{
    public function __construct(
        private readonly StorageResolver $storageResolver,
        private readonly EventDispatcherInterface $dispatcher,
        private readonly string $archivePath,
    ) {
    }

    public function notify(ExtensionMetadataInterface $metadata, object $entity, string $fieldName): void
    {
        $file = $metadata->getFieldValue($entity, $fieldName);
        if (null !== $file && !$file instanceof \Symfony\Component\HttpFoundation\File\File) {
            return;
        }

        // File was injected by us — the mappedBy field is already correct
        if ($file instanceof File) {
            return;
        }

        $config = $this->getConfig($metadata);
        $storage = $this->resolveStorage($config);
        $mapping = $config->getMapping($fieldName);

        // if the path will be the same, uow changeSet will be empty
        /** @var string $mappedBy */
        $mappedBy = $mapping['mappedBy'];
        $path = (null !== $file && $file->isFile()) ? $storage->resolveRelativePath($file->getRealPath(), $config->getPrefix()) : null;
        $metadata->setFieldValue($entity, $mappedBy, $path);
    }

    public function update(ExtensionMetadataInterface $metadata, object $object, string $fieldName): void
    {
        $config = $this->getConfig($metadata);
        $storage = $this->resolveStorage($config);
        $mapping = $config->getMapping($fieldName);
        /** @var string $inversedBy */
        $inversedBy = $mapping['inversedBy'];
        $file = $metadata->getFieldValue($object, $inversedBy);

        if (!$file instanceof \Symfony\Component\HttpFoundation\File\File) {
            $metadata->setFieldValue($object, $fieldName, null);

            return;
        }

        $relativePath = $storage->resolveRelativePath($file->getPathname(), $config->getPrefix());
        $metadata->setFieldValue($object, $fieldName, $relativePath);
    }

    public function upload(ExtensionMetadataInterface $metadata, object $object, string $fieldName): void
    {
        $config = $this->getConfig($metadata);
        $storage = $this->resolveStorage($config);
        $mapping = $config->getMapping($fieldName);
        /** @var string $inversedBy */
        $inversedBy = $mapping['inversedBy'];
        $file = $metadata->getFieldValue($object, $inversedBy);

        if (!$file instanceof \Symfony\Component\HttpFoundation\File\File) {
            throw new RuntimeException(\sprintf("The uploaded file is not an instance of '%s'.", \Symfony\Component\HttpFoundation\File\File::class));
        }

        $this->dispatcher->dispatch(new PreUploadEvent($object, $file, $fieldName));

        $namingStrategy = NamingStrategyFactory::create($config->getNamingStrategy());
        $relativePath = $storage->upload($file, $namingStrategy, $config->getPrefix());

        $resolvedPath = $storage->resolvePath($relativePath);
        $uri = $storage->resolveUri($relativePath);

        $file = new File($resolvedPath, $uri);
        $metadata->setFieldValue($object, $inversedBy, $file);

        $this->dispatcher->dispatch(new PostUploadEvent($object, $file, $fieldName));
    }

    public function remove(object $entity, string $storageName, ?string $relativePath): void
    {
        if (null === $relativePath) {
            return;
        }

        $storage = $this->storageResolver->get($storageName);

        $resolvedPath = $storage->resolvePath($relativePath);
        $resolvedUri = $storage->resolveUri($relativePath);

        $this->dispatcher->dispatch(new PreRemoveEvent($entity, $relativePath, $resolvedPath, $resolvedUri));
        $storage->remove($resolvedPath);
        $this->dispatcher->dispatch(new PostRemoveEvent($entity, $relativePath, $resolvedPath, $resolvedUri));
    }

    public function archive(string $storageName, ?string $relativePath): void
    {
        if (null === $relativePath) {
            return;
        }

        if (\str_contains($relativePath, '..')) {
            throw new RuntimeException(\sprintf('Path traversal detected: "%s" contains "..".', $relativePath));
        }

        $storage = $this->storageResolver->get($storageName);

        $archiveTarget = \rtrim($this->archivePath, '/').'/'.\ltrim($relativePath, '/');
        $archiveDir = \dirname($archiveTarget);

        if (!\is_dir($archiveDir)) {
            \mkdir($archiveDir, 0755, true);
        }

        $storage->download($relativePath, $archiveTarget);

        $resolvedPath = $storage->resolvePath($relativePath);
        $storage->remove($resolvedPath);
    }

    public function inject(ExtensionMetadataInterface $metadata, object $object, string $fieldName): void
    {
        $config = $this->getConfig($metadata);
        $storage = $this->resolveStorage($config);
        $mapping = $config->getMapping($fieldName);

        /** @var string $mappedBy */
        $mappedBy = $mapping['mappedBy'];
        /** @var string|null $relativePath */
        $relativePath = $metadata->getFieldValue($object, $mappedBy);

        if (null === $relativePath) {
            $metadata->setFieldValue($object, $fieldName, null);

            return;
        }

        $path = $storage->resolvePath($relativePath);
        $uri = $storage->resolveUri($relativePath);

        $file = new File($path, $uri);
        $metadata->setFieldValue($object, $fieldName, $file);
    }

    private function getConfig(ExtensionMetadataInterface $metadata): UploadableConfiguration
    {
        $config = $metadata->getConfiguration(UploadableConfiguration::class);

        if (!$config instanceof UploadableConfiguration) {
            throw new RuntimeException(\sprintf("Expected '%s' configuration, got '%s'.", UploadableConfiguration::class, \get_debug_type($config)));
        }

        return $config;
    }

    private function resolveStorage(UploadableConfiguration $config): StorageInterface
    {
        return $this->storageResolver->get($config->getStorage());
    }
}
