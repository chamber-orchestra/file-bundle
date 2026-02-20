<?php

declare(strict_types=1);

/*
 * This file is part of the ChamberOrchestra package.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace ChamberOrchestra\FileBundle\Exception\ORM;

use ChamberOrchestra\FileBundle\Mapping\Attribute\UploadableProperty;
use ChamberOrchestra\FileBundle\NamingStrategy\NamingStrategyInterface;

class MappingException extends \ChamberOrchestra\MetadataBundle\Exception\MappingException
{
    public static function namingStrategyIsNotValidInstance(string $className, string $namingStrategy): MappingException
    {
        return new self(\sprintf('Class "%s" has not valid namingStrategy field. NamingStrategy must implement "%s", but it implements "%s".',
            $className,
            NamingStrategyInterface::class,
            \implode(', ', \class_implements($namingStrategy) ?: []),
        ));
    }

    public static function noUploadedFields(string $className): MappingException
    {
        return new self(\sprintf('Class "%s" must have at least one field marked as "%s"',
            $className,
            UploadableProperty::class,
        ));
    }
}
