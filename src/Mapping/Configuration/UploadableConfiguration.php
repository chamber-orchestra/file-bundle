<?php

declare(strict_types=1);

/*
 * This file is part of the ChamberOrchestra package.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace ChamberOrchestra\FileBundle\Mapping\Configuration;

use ChamberOrchestra\FileBundle\Mapping\Attribute\Uploadable;
use ChamberOrchestra\FileBundle\Mapping\Helper\Behaviour;
use ChamberOrchestra\FileBundle\NamingStrategy\HashingNamingStrategy;
use ChamberOrchestra\MetadataBundle\Mapping\ORM\AbstractMetadataConfiguration;

class UploadableConfiguration extends AbstractMetadataConfiguration
{
    private string $prefix = '';
    private Behaviour $behaviour = Behaviour::Remove;
    private string $namingStrategy = HashingNamingStrategy::class;
    private string $storage = 'default';
    /** @var array<string, string>|null */
    private ?array $uploadableFieldsNames = null;
    /** @var array<string, string>|null */
    private ?array $mappedByFieldsNames = null;

    public function __construct(?Uploadable $annotation = null)
    {
        if (null !== $annotation) {
            $this->prefix = $annotation->prefix;
            $this->behaviour = $annotation->behaviour;
            $this->namingStrategy = $annotation->namingStrategy;
            $this->storage = $annotation->storage;
        }
    }

    public function getPrefix(): string
    {
        return $this->prefix;
    }

    public function getBehaviour(): Behaviour
    {
        return $this->behaviour;
    }

    public function getNamingStrategy(): string
    {
        return $this->namingStrategy;
    }

    public function getStorage(): string
    {
        return $this->storage;
    }

    /**
     * @return array<string, string>
     */
    public function getUploadableFieldNames(): array
    {
        if (null === $this->uploadableFieldsNames) {
            $this->uploadableFieldsNames = [];

            foreach ($this->mappings as $fieldName => $mapping) {
                if (isset($mapping['upload'])) {
                    $this->uploadableFieldsNames[$fieldName] = $fieldName;
                }
            }
        }

        return $this->uploadableFieldsNames;
    }

    /**
     * @return array<string, string>
     */
    public function getMappedByFieldNames(): array
    {
        if (null === $this->mappedByFieldsNames) {
            $this->mappedByFieldsNames = [];
            foreach ($this->getUploadableFieldNames() as $fieldName) {
                $mapping = $this->mappings[$fieldName];
                /** @var string $mappedBy */
                $mappedBy = $mapping['mappedBy'];
                $this->mappedByFieldsNames[$mappedBy] = $mappedBy;
            }
        }

        return $this->mappedByFieldsNames;
    }

    public function __serialize(): array
    {
        return \array_merge(parent::__serialize(), [
            'prefix' => $this->prefix,
            'behaviour' => $this->behaviour,
            'namingStrategy' => $this->namingStrategy,
            'storage' => $this->storage,
            'uploadableFieldsNames' => $this->uploadableFieldsNames,
            'mappedByFieldsNames' => $this->mappedByFieldsNames,
        ]);
    }

    public function __unserialize(array $data): void
    {
        parent::__unserialize($data);

        /** @var array{mappings: array<string, array<string, mixed>>, prefix: string, behaviour: Behaviour, namingStrategy: string, storage: string, uploadableFieldsNames: array<string, string>|null, mappedByFieldsNames: array<string, string>|null} $data */
        $this->prefix = $data['prefix'];
        $this->behaviour = $data['behaviour'];
        $this->namingStrategy = $data['namingStrategy'];
        $this->storage = $data['storage'];
        $this->uploadableFieldsNames = $data['uploadableFieldsNames'];
        $this->mappedByFieldsNames = $data['mappedByFieldsNames'];
    }
}
