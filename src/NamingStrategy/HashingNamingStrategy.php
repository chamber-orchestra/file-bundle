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
use Symfony\Component\HttpFoundation\File\UploadedFile;

class HashingNamingStrategy implements NamingStrategyInterface
{
    public function name(File $file, string $targetDir = ''): string
    {
        $originalName = $file instanceof UploadedFile ? $file->getClientOriginalName() : $file->getBasename();

        return \md5($originalName.\bin2hex(\random_bytes(8))).'.'.$file->guessExtension();
    }
}
