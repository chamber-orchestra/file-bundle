<?php

declare(strict_types=1);

/*
 * This file is part of the ChamberOrchestra package.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace ChamberOrchestra\FileBundle\EventSubscriber;

use ChamberOrchestra\FileBundle\Handler\Handler;
use ChamberOrchestra\FileBundle\Mapping\Configuration\UploadableConfiguration;
use ChamberOrchestra\FileBundle\Mapping\Helper\Behaviour;
use ChamberOrchestra\MetadataBundle\EventSubscriber\AbstractDoctrineListener;
use ChamberOrchestra\MetadataBundle\Helper\MetadataArgs;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\Common\Util\ClassUtils;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Event\PostFlushEventArgs;
use Doctrine\ORM\Event\PostLoadEventArgs;
use Doctrine\ORM\Event\PreFlushEventArgs;
use Doctrine\ORM\Events;
use Doctrine\Persistence\Proxy;

#[AsDoctrineListener(Events::postLoad)]
#[AsDoctrineListener(Events::preFlush)]
#[AsDoctrineListener(Events::onFlush)]
#[AsDoctrineListener(Events::postFlush)]
class FileSubscriber extends AbstractDoctrineListener
{
    /** @var array<int, array{string, string, array<string, string>}> */
    private array $pendingRemove = [];
    /** @var array<int, array{string, string, array<string, string>}> */
    private array $pendingArchive = [];

    /**
     * Caches which entity classes are uploadable to avoid repeated metadata lookups.
     * This is the main performance optimization: postLoad and preFlush run for every
     * entity, but typically only a few classes are uploadable.
     *
     * @var array<class-string, bool>
     */
    private array $uploadableClassCache = [];

    public function __construct(private readonly Handler $handler)
    {
    }

    public function postLoad(PostLoadEventArgs $event): void
    {
        $entity = $event->getObject();
        $em = $event->getObjectManager();
        $className = ClassUtils::getClass($entity);

        if (false === ($this->uploadableClassCache[$className] ?? null)) {
            return;
        }

        $metadata = $this->requireReader()->getExtensionMetadata($em, $className);
        $config = $metadata->getConfiguration(UploadableConfiguration::class);
        if (!$config instanceof UploadableConfiguration) {
            $this->uploadableClassCache[$className] = false;

            return;
        }

        $this->uploadableClassCache[$className] = true;

        foreach ($config->getUploadableFieldNames() as $fieldName) {
            $this->handler->inject($metadata, $entity, $fieldName);
        }
    }

    public function preFlush(PreFlushEventArgs $args): void
    {
        $em = $args->getObjectManager();

        $uow = $em->getUnitOfWork();

        foreach ($this->iterateEntities($uow) as $entity) {
            $className = ClassUtils::getClass($entity);

            if (false === ($this->uploadableClassCache[$className] ?? null)) {
                continue;
            }

            $metadata = $this->requireReader()->getExtensionMetadata($em, $className);
            $config = $metadata->getConfiguration(UploadableConfiguration::class);
            if (!$config instanceof UploadableConfiguration) {
                $this->uploadableClassCache[$className] = false;

                continue;
            }

            $this->uploadableClassCache[$className] = true;

            foreach ($config->getUploadableFieldNames() as $fieldName) {
                $this->handler->notify($metadata, $entity, $fieldName);
            }
        }
    }

    /**
     * @return iterable<object>
     */
    private function iterateEntities(\Doctrine\ORM\UnitOfWork $uow): iterable
    {
        $seen = [];

        foreach ($uow->getScheduledEntityInsertions() as $entity) {
            $seen[\spl_object_id($entity)] = true;
            yield $entity;
        }

        foreach ($uow->getIdentityMap() as $entities) {
            foreach ($entities as $entity) {
                if (isset($seen[\spl_object_id($entity)])) {
                    continue;
                }

                if ($entity instanceof Proxy && !$entity->__isInitialized()) {
                    continue;
                }

                yield $entity;
            }
        }
    }

    public function onFlush(OnFlushEventArgs $args): void
    {
        $em = $args->getObjectManager();

        foreach ($this->getScheduledEntityInsertions($em, $class = UploadableConfiguration::class) as $args) {
            $this->doUpload($args);
            $this->doUpdate($args);
        }

        foreach ($this->getScheduledEntityUpdates($em, $class) as $args) {
            $this->doRemoveChanged($args);
            $this->doUpload($args);
            $this->doUpdate($args);
        }

        foreach ($this->getScheduledEntityDeletions($em, $class) as $args) {
            $this->doRemove($args);
        }
    }

    public function postFlush(PostFlushEventArgs $args): void
    {
        $pendingRemove = $this->pendingRemove;
        $this->pendingRemove = [];

        $pendingArchive = $this->pendingArchive;
        $this->pendingArchive = [];

        foreach ($pendingRemove as [$entityClass, $storageName, $fields]) {
            foreach ($fields as $relativePath) {
                try {
                    $this->handler->remove($entityClass, $storageName, $relativePath);
                } catch (\Throwable) {
                    // Individual file removal failures must not abort remaining removals.
                    // The database transaction already succeeded at this point.
                }
            }
        }

        foreach ($pendingArchive as [$entityClass, $storageName, $fields]) {
            foreach ($fields as $relativePath) {
                try {
                    $this->handler->archive($storageName, $relativePath);
                } catch (\Throwable) {
                    // Individual archive failures must not abort remaining operations.
                }
            }
        }
    }

    private function doUpdate(MetadataArgs $args): void
    {
        $em = $args->entityManager;
        $entity = $args->entity;
        $metadata = $args->extensionMetadata;

        /** @var UploadableConfiguration $config */
        $config = $args->configuration;

        $uow = $em->getUnitOfWork();
        $changeSet = $uow->getEntityChangeSet($entity);
        $fields = \array_intersect($config->getMappedByFieldNames(), \array_keys($changeSet));
        $class = $em->getClassMetadata(ClassUtils::getClass($entity));

        foreach ($fields as $fieldName) {
            $this->handler->update($metadata, $entity, $fieldName);
        }

        $uow = $em->getUnitOfWork();
        $uow->recomputeSingleEntityChangeSet($class, $entity);
    }

    private function doUpload(MetadataArgs $args): void
    {
        $entity = $args->entity;
        $metadata = $args->extensionMetadata;

        /** @var UploadableConfiguration $config */
        $config = $args->configuration;

        $uow = $args->entityManager->getUnitOfWork();
        $changeSet = $uow->getEntityChangeSet($entity);
        $fields = \array_intersect($config->getMappedByFieldNames(), \array_keys($changeSet));

        foreach ($fields as $fieldName) {
            $value = $metadata->getFieldValue($entity, $fieldName);

            if (null !== $value) {
                $this->handler->upload($metadata, $entity, $fieldName);
            }
        }
    }

    private function doRemoveChanged(MetadataArgs $args): void
    {
        $em = $args->entityManager;
        $entity = $args->entity;

        /** @var UploadableConfiguration $config */
        $config = $args->configuration;
        $behaviour = $config->getBehaviour();

        if (Behaviour::Keep === $behaviour) {
            return;
        }

        $uow = $em->getUnitOfWork();
        $changeSet = $uow->getEntityChangeSet($entity);
        $fields = \array_intersect($config->getMappedByFieldNames(), \array_keys($changeSet));

        if (!\count($fields)) {
            return;
        }

        $paths = [];
        foreach ($fields as $field) {
            /** @var array{mixed, mixed} $change */
            $change = $changeSet[$field];
            $old = $change[0];
            if (!\is_string($old)) {
                continue;
            }
            $paths[$field] = $old;
        }

        $entry = [ClassUtils::getClass($entity), $config->getStorage(), $paths];

        match ($behaviour) {
            Behaviour::Remove => $this->pendingRemove[\spl_object_id($entity)] = $entry,
            Behaviour::Archive => $this->pendingArchive[\spl_object_id($entity)] = $entry,
        };
    }

    private function doRemove(MetadataArgs $args): void
    {
        $entity = $args->entity;
        $metadata = $args->extensionMetadata;

        /** @var UploadableConfiguration $config */
        $config = $args->configuration;
        $behaviour = $config->getBehaviour();

        if (Behaviour::Keep === $behaviour) {
            return;
        }

        $paths = [];
        foreach ($config->getUploadableFieldNames() as $field) {
            $mapping = $config->getMapping($field);
            /** @var string $mappedBy */
            $mappedBy = $mapping['mappedBy'];
            $relativePath = $metadata->getFieldValue($entity, $mappedBy);
            if (!\is_string($relativePath)) {
                continue;
            }

            $paths[$field] = $relativePath;
        }

        $entry = [ClassUtils::getClass($entity), $config->getStorage(), $paths];

        match ($behaviour) {
            Behaviour::Remove => $this->pendingRemove[\spl_object_id($entity)] = $entry,
            Behaviour::Archive => $this->pendingArchive[\spl_object_id($entity)] = $entry,
        };
    }
}
