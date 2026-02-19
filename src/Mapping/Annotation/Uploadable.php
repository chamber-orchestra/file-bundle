<?php

declare(strict_types=1);

namespace Dev\FileBundle\Mapping\Annotation;

use Attribute;
use Dev\FileBundle\Mapping\Helper\Behaviour;
use Dev\FileBundle\NamingStrategy\HashingNamingStrategy;
use Doctrine\ORM\Mapping\MappingAttribute;

#[Attribute(Attribute::TARGET_CLASS)]
final class Uploadable implements MappingAttribute
{
    public function __construct(
        public string $prefix,
        public string $namingStrategy = HashingNamingStrategy::class,
        public string $behaviour = Behaviour::REMOVE
    )
    {
    }
}
