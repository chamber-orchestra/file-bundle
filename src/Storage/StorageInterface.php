<?php

declare(strict_types=1);

/*
 * This file is part of the ChamberOrchestra package.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace ChamberOrchestra\FileBundle\Storage;

use ChamberOrchestra\FileBundle\NamingStrategy\NamingStrategyInterface;
use Symfony\Component\HttpFoundation\File\File;

/**
 * StorageInterface.
 */
interface StorageInterface
{
    /**
     * Uploads the file in the uploadable field of the specified object
     * according to the property configuration.
     *
     * Used in EventSubscribers. Not called outer
     */
    public function upload(File $file, NamingStrategyInterface $namingStrategy, string $prefix = ''): string;

    /**
     * Removes the files associated with the given mapping.
     */
    public function remove(string $resolvedPath): bool;

    /**
     * Resolves the path for a file based on the specified object
     * and mapping name.
     */
    public function resolvePath(string $path): string;

    /**
     * Resolves relative path for specified path before persisting.
     */
    public function resolveRelativePath(string $path, string $prefix = ''): string;

    /**
     * Resolves the URI for a file based on the specified object.
     */
    public function resolveUri(string $path): ?string;

    /**
     * Downloads a file from storage to a local target path.
     */
    public function download(string $relativePath, string $targetPath): void;
}
