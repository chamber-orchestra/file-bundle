<?php

declare(strict_types=1);

namespace Dev\FileBundle\Handler;

use Dev\FileBundle\Storage\StorageInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

abstract class AbstractHandler implements HandlerInterface
{
    public function __construct(
        protected readonly StorageInterface $storage,
        protected readonly EventDispatcherInterface $dispatcher
    )
    {
    }
}
