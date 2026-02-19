<?php

declare(strict_types=1);

/*
 * This file is part of the ChamberOrchestra package.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace ChamberOrchestra\FileBundle\Events;

use ChamberOrchestra\FileBundle\Model\File;
use Symfony\Contracts\EventDispatcher\Event;

/**
 * Dispatched after a file has been uploaded to storage.
 *
 * The file is the resolved Model\File with path and URI already set.
 * Use this event for post-processing such as image resizing,
 * thumbnail generation, or metadata extraction.
 */
final class PostUploadEvent extends Event
{
    public function __construct(
        public readonly string $entityClass,
        public readonly object $entity,
        public readonly File $file,
        public readonly string $fieldName,
    ) {
    }
}
