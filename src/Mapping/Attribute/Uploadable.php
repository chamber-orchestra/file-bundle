<?php

declare(strict_types=1);

/*
 * This file is part of the ChamberOrchestra package.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace ChamberOrchestra\FileBundle\Mapping\Attribute;

use ChamberOrchestra\FileBundle\Exception\InvalidArgumentException;
use ChamberOrchestra\FileBundle\Mapping\Helper\Behaviour;
use ChamberOrchestra\FileBundle\NamingStrategy\HashingNamingStrategy;
use ChamberOrchestra\FileBundle\NamingStrategy\NamingStrategyInterface;
use Doctrine\ORM\Mapping\MappingAttribute;

#[\Attribute(\Attribute::TARGET_CLASS)]
final class Uploadable implements MappingAttribute
{
    public function __construct(
        public string $prefix = '',
        public string $namingStrategy = HashingNamingStrategy::class,
        public Behaviour $behaviour = Behaviour::Remove,
        public string $storage = 'default',
    ) {
        if (\str_contains($this->prefix, '..')) {
            throw new InvalidArgumentException(\sprintf('The prefix "%s" must not contain "..".', $this->prefix));
        }

        if ('' === $this->namingStrategy) {
            throw new InvalidArgumentException('The namingStrategy must not be empty.');
        }

        if (!\is_subclass_of($this->namingStrategy, NamingStrategyInterface::class)) {
            throw new InvalidArgumentException(\sprintf('The namingStrategy "%s" must implement "%s".', $this->namingStrategy, NamingStrategyInterface::class));
        }
    }
}
