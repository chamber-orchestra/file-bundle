<?php

declare(strict_types=1);

namespace Dev\FileBundle\Events;

use Symfony\Contracts\EventDispatcher\Event;

abstract class AbstractEvent extends Event
{
    public function __construct(
        public readonly string $relativePath,
        public readonly string $resolvedPath,
        public readonly string $resolvedUri
    )
    {
    }
}