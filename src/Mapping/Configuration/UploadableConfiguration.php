<?php

declare(strict_types=1);

namespace Dev\FileBundle\Mapping\Configuration;

use Dev\FileBundle\Mapping\Annotation\Uploadable;
use Dev\FileBundle\Mapping\Helper\Behaviour;
use Dev\FileBundle\NamingStrategy\HashingNamingStrategy;
use Dev\MetadataBundle\Mapping\ORM\AbstractMetadataConfiguration;

class UploadableConfiguration extends AbstractMetadataConfiguration
{
    private string $prefix;
    private string $behaviour;
    private string $namingStrategy;
    private ?array $uploadableFieldsNames = null;
    private ?array $mappedByFieldsNames = null;

    public function __construct(?Uploadable $annotation)
    {
        $this->prefix = $annotation ? $annotation->prefix : '';
        $this->behaviour = $annotation ? $annotation->behaviour : Behaviour::REMOVE;
        $this->namingStrategy = $annotation ? $annotation->namingStrategy : HashingNamingStrategy::class;
    }

    public function getPrefix(): string
    {
        return $this->prefix;
    }

    public function getBehaviour(): string|null
    {
        return $this->behaviour;
    }

    public function getNamingStrategy(): string|null
    {
        return $this->namingStrategy;
    }

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

    public function getMappedByFieldNames(): array
    {
        if (null === $this->mappedByFieldsNames) {
            $this->mappedByFieldsNames = [];
            foreach ($this->getUploadableFieldNames() as $fieldName) {
                $mapping = $this->mappings[$fieldName];
                $this->mappedByFieldsNames[$mapping['mappedBy']] = $mapping['mappedBy'];
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
            'uploadableFieldsNames' => $this->uploadableFieldsNames,
        ]);
    }

    public function __unserialize(array $data): void
    {
        parent::__unserialize($data);
        [
            'prefix' => $this->prefix,
            'behaviour' => $this->behaviour,
            'namingStrategy' => $this->namingStrategy,
            'uploadableFieldsNames' => $this->uploadableFieldsNames,
        ] = $data;
    }
}
