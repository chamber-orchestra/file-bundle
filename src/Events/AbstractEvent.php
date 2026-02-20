<?php

declare(strict_types=1);

/*
 * This file is part of the ChamberOrchestra package.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace ChamberOrchestra\FileBundle\Events;

use Symfony\Contracts\EventDispatcher\Event;

abstract class AbstractEvent extends Event
{
    public function __construct(
        public readonly string $entityClass,
        public readonly string $relativePath,
        public readonly string $resolvedPath,
        public readonly ?string $resolvedUri,
    ) {
    }
}
