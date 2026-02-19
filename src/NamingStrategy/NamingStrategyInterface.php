<?php

declare(strict_types=1);

namespace Dev\FileBundle\NamingStrategy;

use Symfony\Component\HttpFoundation\File\File;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * NamingStrategy.
 */
interface NamingStrategyInterface
{
    /**
     * Creates a name for the file being uploaded.
     */
    public function name(File $file): string;
}
