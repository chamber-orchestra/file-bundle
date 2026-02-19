<?php

declare(strict_types=1);

namespace Dev\FileBundle\Storage;

use Dev\FileBundle\NamingStrategy\NamingStrategyInterface;
use Symfony\Component\HttpFoundation\File\File;

class FileSystemStorage extends AbstractStorage
{
    /**
     * The full path for upload files.
     */
    private string $uploadPath;
    /**
     * The uri for uploads.
     */
    private string|null $uriPrefix = null;

    public function __construct(string $uploadPath, string|null $uriPrefix = null)
    {
        $this->uploadPath = \rtrim($uploadPath, '/');
        $this->uriPrefix = $uriPrefix !== null ? '/'.\trim($uriPrefix, '/') : null;
    }

    public function upload(File $file, NamingStrategyInterface $namingStrategy, string $prefix = ''): string
    {
        $prefix = '' !== $prefix ? '/'.\trim($prefix, '/') : '';
        $uploadPath = $this->resolvePath($prefix);

        $name = $namingStrategy->name($file);
        $file->move($uploadPath, $name);

        return $prefix.'/'.$name;
    }

    public function remove(string $resolvedPath): bool
    {
        return \file_exists($resolvedPath) && \unlink($resolvedPath);
    }

    public function resolvePath(string $path): string
    {
        return $this->uploadPath.$path;
    }

    public function resolveUri(string $path): string|null
    {
        if (null === $this->uriPrefix) {
            return null;
        }

        return $this->uriPrefix.$path;
    }

    public function resolveRelativePath(string $path, string $prefix = ''): string
    {
        return \str_replace($this->uploadPath, '', $path);
    }
}
