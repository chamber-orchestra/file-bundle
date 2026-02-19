<?php

declare(strict_types=1);

namespace Dev\FileBundle\Mapping\Annotation;

use Attribute;
use Doctrine\ORM\Mapping\MappingAttribute;

#[Attribute(Attribute::TARGET_PROPERTY)]
final class UploadableProperty implements MappingAttribute
{
    public function __construct(
        public string $mappedBy,
    )
    {
    }
}
