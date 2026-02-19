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
use Doctrine\ORM\Mapping\MappingAttribute;

#[\Attribute(\Attribute::TARGET_PROPERTY)]
final class UploadableProperty implements MappingAttribute
{
    public function __construct(
        public string $mappedBy,
    ) {
        if ('' === $this->mappedBy) {
            throw new InvalidArgumentException('The mappedBy property name must not be empty.');
        }
    }
}
