<?php

declare(strict_types=1);

namespace Dev\FileBundle\EventSubscriber;

use Dev\DoctrineExtensionsBundle\Util\ClassUtils;
use Dev\FileBundle\Handler\HandlerInterface;
use Dev\FileBundle\Mapping\Configuration\UploadableConfiguration;
use Dev\FileBundle\Mapping\Helper\Behaviour;
use Dev\MetadataBundle\EventSubscriber\AbstractDoctrineListener;
use Dev\MetadataBundle\Helper\MetadataArgs;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Event\OnFlushEventArgs;
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
    private array $pendingRemove = [];

    public function __construct(private readonly HandlerInterface $handler)
    {
    }

    public function postLoad(PostLoadEventArgs $event): void
    {
        $entity = $event->getObject();
        $em = $event->getObjectManager();

        $metadata = $this->reader->getExtensionMetadata($em, ClassUtils::getClass($entity));
        /** @var UploadableConfiguration $config */
        if (null === $config = $metadata->getConfiguration(UploadableConfiguration::class)) {
            return;
        }

        foreach ($config->getUploadableFieldNames() as $fieldName) {
            $this->handler->inject($metadata, $entity, $fieldName);
        }
    }

    public function preFlush(PreFlushEventArgs $args): void
    {
        $em = $args->getObjectManager();

        $uow = $em->getUnitOfWork();
        foreach ($uow->getIdentityMap() as $entities) {
            foreach ($entities as $entity) {
                if ($entity instanceof Proxy && !$entity->__isInitialized()) {
                    //skip not initialized entities
                    continue;
                }

                $metadata = $this->reader->getExtensionMetadata($em, ClassUtils::getClass($entity));
                /** @var UploadableConfiguration $config */
                $config = $metadata->getConfiguration(UploadableConfiguration::class);
                if (null === $config) {
                    continue;
                }

                foreach ($config->getUploadableFieldNames() as $fieldName) {
                    $this->handler->notify($metadata, $entity, $fieldName);
                }
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

    /**
     * Only process Behaviour::REMOVE and entities which are already has valid metadata.
     */
    public function postFlush(): void
    {
        /** @var $entity object */
        /** @var $fields string[] */
        while ([$entity, $fields] = \array_shift($this->pendingRemove)) {
            foreach ($fields as $relativePath) {
                $this->handler->remove($entity, $relativePath);
            }
        }
    }

    private function doUpdate(MetadataArgs $args): void
    {
        $em = $args->entityManager;
        $entity = $args->entity;
        $config = $args->configuration;
        $metadata = $args->extensionMetadata;

        $uow = $em->getUnitOfWork();
        $changeSet = $uow->getEntityChangeSet($entity);
        // restrict to fields that changed
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
        $em = $args->entityManager;
        $entity = $args->entity;
        $config = $args->configuration;
        $metadata = $args->extensionMetadata;

        $uow = $em->getUnitOfWork();
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
        $config = $args->configuration;

        if (Behaviour::REMOVE !== $config->getBehaviour()) {
            return;
        }

        $uow = $em->getUnitOfWork();
        $changeSet = $uow->getEntityChangeSet($entity);
        $fields = \array_intersect($config->getFieldNames(), \array_keys($changeSet));

        if (!count($fields)) {
            return;
        }

        $remove = [];
        foreach ($fields as $field) {
            [$old,] = $changeSet[$field];
            if (null === $old) {
                continue;
            }
            $remove[$field] = $old;
        }

        $this->pendingRemove[\spl_object_hash($entity)] = [$entity, $remove];
    }

    private function doRemove(MetadataArgs $args): void
    {
        $entity = $args->entity;
        $config = $args->configuration;
        $metadata = $args->extensionMetadata;

        if (Behaviour::REMOVE !== $config->getBehaviour()) {
            return;
        }

        $remove = [];
        foreach ($config->getUploadableFieldNames() as $field) {
            $mapping = $config->getMapping($field);
            $relativePath = $metadata->getFieldValue($entity, $mapping['mappedBy']);
            if (null === $relativePath) {
                continue;
            }

            $remove[$field] = $relativePath;
        }

        $this->pendingRemove[\spl_object_hash($entity)] = [$entity, $remove];
    }
}
