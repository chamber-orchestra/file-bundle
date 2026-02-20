<?php

declare(strict_types=1);

/*
 * This file is part of the ChamberOrchestra package.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace ChamberOrchestra\FileBundle\Events;

use Symfony\Component\HttpFoundation\File\File;
use Symfony\Contracts\EventDispatcher\Event;

/**
 * Dispatched before a file is uploaded to storage.
 *
 * The file is the original source file (UploadedFile or regular File)
 * before it has been moved to storage.
 */
final class PreUploadEvent extends Event
{
    public function __construct(
        public readonly object $entity,
        public readonly File $file,
        public readonly string $fieldName,
    ) {
    }
}
