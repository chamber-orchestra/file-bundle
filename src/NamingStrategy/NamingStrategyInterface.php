<?php

declare(strict_types=1);

/*
 * This file is part of the ChamberOrchestra package.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace ChamberOrchestra\FileBundle\NamingStrategy;

use Symfony\Component\HttpFoundation\File\File;

interface NamingStrategyInterface
{
    /**
     * Creates a name for the file being uploaded.
     *
     * @param string $targetDir Resolved target directory (empty for remote storage)
     */
    public function name(File $file, string $targetDir = ''): string;
}
