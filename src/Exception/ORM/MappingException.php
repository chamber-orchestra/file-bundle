<?php

declare(strict_types=1);

namespace Dev\FileBundle\Exception\ORM;

use Dev\FileBundle\Mapping\Annotation\UploadableProperty;
use Dev\FileBundle\NamingStrategy\NamingStrategyInterface;

class MappingException extends \Dev\MetadataBundle\Exception\MappingException
{
    public static function namingStrategyIsNotValidInstance(string $className, string $namingStrategy): MappingException
    {
        return new self(sprintf('Class "%s" has not valid namingStrategy field. NamingStrategy must implement "%s", but it implements "%s".',
            $className,
            NamingStrategyInterface::class,
            implode(', ', class_implements($namingStrategy))
        ));
    }

    public static function noUploadedFields(string $className): MappingException
    {
        return new self(sprintf('Class "%s" must have at least one field marked as "%s"',
            $className,
            UploadableProperty::class
        ));
    }
}
