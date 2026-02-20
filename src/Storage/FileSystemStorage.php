<?php

declare(strict_types=1);

/*
 * This file is part of the ChamberOrchestra package.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace ChamberOrchestra\FileBundle\Storage;

use ChamberOrchestra\FileBundle\Exception\RuntimeException;
use ChamberOrchestra\FileBundle\NamingStrategy\NamingStrategyInterface;
use Symfony\Component\HttpFoundation\File\File;

readonly class FileSystemStorage implements StorageInterface
{
    private string $uploadPath;
    private ?string $uriPrefix;

    public function __construct(string $uploadPath, ?string $uriPrefix = null)
    {
        $this->uploadPath = \rtrim($uploadPath, '/');
        $this->uriPrefix = null !== $uriPrefix ? '/'.\trim($uriPrefix, '/') : null;
    }

    public function upload(File $file, NamingStrategyInterface $namingStrategy, string $prefix = ''): string
    {
        $prefix = '' !== $prefix ? '/'.\trim($prefix, '/') : '';
        $uploadPath = $this->resolvePath($prefix);

        if (!\is_dir($uploadPath)) {
            \mkdir($uploadPath, 0755, true);
        }

        $name = $namingStrategy->name($file, $uploadPath);

        if (\str_contains($name, '/') || \str_contains($name, '\\') || \str_contains($name, '..')) {
            throw new RuntimeException(\sprintf('Invalid filename "%s" returned by naming strategy "%s". Filenames must not contain directory separators or "..".', $name, $namingStrategy::class));
        }

        $file->move($uploadPath, $name);

        return $prefix.'/'.$name;
    }

    public function remove(string $resolvedPath): bool
    {
        return \file_exists($resolvedPath) && \unlink($resolvedPath);
    }

    public function resolvePath(string $path): string
    {
        if (\str_contains($path, '..')) {
            throw new RuntimeException(\sprintf('Path traversal detected: "%s" contains "..".', $path));
        }

        return $this->uploadPath.$path;
    }

    public function resolveUri(string $path): ?string
    {
        if (null === $this->uriPrefix) {
            return null;
        }

        return $this->uriPrefix.$path;
    }

    public function resolveRelativePath(string $path, string $prefix = ''): string
    {
        if (\str_starts_with($path, $this->uploadPath)) {
            return \substr($path, \strlen($this->uploadPath));
        }

        return $path;
    }

    public function download(string $relativePath, string $targetPath): void
    {
        $sourcePath = $this->resolvePath($relativePath);

        if (!\is_file($sourcePath)) {
            throw new RuntimeException(\sprintf('Cannot download file: "%s" does not exist.', $sourcePath));
        }

        if (!\copy($sourcePath, $targetPath)) {
            throw new RuntimeException(\sprintf('Failed to copy file from "%s" to "%s".', $sourcePath, $targetPath));
        }
    }
}
