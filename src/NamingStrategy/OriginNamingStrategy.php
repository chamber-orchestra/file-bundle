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

class OriginNamingStrategy implements NamingStrategyInterface
{
    public function name(File $file, string $targetDir = ''): string
    {
        $name = $file instanceof UploadedFile ? $file->getClientOriginalName() : $file->getBasename();
        $name = \basename($name);

        if ('' === $targetDir || !\is_dir($targetDir)) {
            return $name;
        }

        if (!\file_exists($targetDir.'/'.$name)) {
            return $name;
        }

        $extension = \pathinfo($name, \PATHINFO_EXTENSION);
        $baseName = '' !== $extension
            ? \substr($name, 0, -\strlen($extension) - 1)
            : $name;

        $version = 1;
        do {
            $candidate = '' !== $extension
                ? $baseName.'_'.$version.'.'.$extension
                : $baseName.'_'.$version;
            ++$version;
        } while (\file_exists($targetDir.'/'.$candidate));

        return $candidate;
    }
}
