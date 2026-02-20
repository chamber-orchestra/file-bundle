<?php

declare(strict_types=1);

/*
 * This file is part of the ChamberOrchestra package.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace ChamberOrchestra\FileBundle\Storage;

use Aws\S3\Exception\S3Exception;
use Aws\S3\S3Client;
use ChamberOrchestra\FileBundle\Exception\RuntimeException;
use ChamberOrchestra\FileBundle\NamingStrategy\NamingStrategyInterface;
use Symfony\Component\HttpFoundation\File\File;

readonly class S3Storage implements StorageInterface
{
    private string $bucket;
    private ?string $uriPrefix;

    public function __construct(
        private S3Client $client,
        string $bucket,
        ?string $uriPrefix = null,
    ) {
        $this->bucket = $bucket;
        $this->uriPrefix = null !== $uriPrefix ? '/'.\trim($uriPrefix, '/') : null;
    }

    public function upload(File $file, NamingStrategyInterface $namingStrategy, string $prefix = ''): string
    {
        $prefix = '' !== $prefix ? '/'.\trim($prefix, '/') : '';
        $name = $namingStrategy->name($file);
        $key = \ltrim($prefix.'/'.$name, '/');

        $params = [
            'Bucket' => $this->bucket,
            'Key' => $key,
            'SourceFile' => $file->getRealPath(),
        ];

        $mimeType = $file->getMimeType();
        if (null !== $mimeType) {
            $params['ContentType'] = $mimeType;
        }

        try {
            $this->client->putObject($params);
        } catch (S3Exception $e) {
            throw new RuntimeException(\sprintf('Failed to upload file to S3 bucket "%s" with key "%s": %s', $this->bucket, $key, $e->getMessage()), 0, $e);
        }

        return $prefix.'/'.$name;
    }

    public function remove(string $resolvedPath): bool
    {
        try {
            $this->client->deleteObject([
                'Bucket' => $this->bucket,
                'Key' => $resolvedPath,
            ]);
        } catch (S3Exception $e) {
            if ('NoSuchKey' === $e->getAwsErrorCode()) {
                return false;
            }

            throw $e;
        }

        return true;
    }

    public function resolvePath(string $path): string
    {
        if (\str_contains($path, '..')) {
            throw new RuntimeException(\sprintf('Path traversal detected: "%s" contains "..".', $path));
        }

        return \ltrim($path, '/');
    }

    public function resolveUri(string $path): ?string
    {
        if (null === $this->uriPrefix) {
            return $this->client->getObjectUrl($this->bucket, \ltrim($path, '/'));
        }

        return $this->uriPrefix.$path;
    }

    public function resolveRelativePath(string $path, string $prefix = ''): string
    {
        $prefix = '' !== $prefix ? '/'.\trim($prefix, '/') : '';

        if ('' !== $prefix && \str_starts_with($path, $prefix)) {
            return $path;
        }

        return $prefix.'/'.\ltrim(\basename($path), '/');
    }

    public function download(string $relativePath, string $targetPath): void
    {
        $key = \ltrim($relativePath, '/');

        try {
            $this->client->getObject([
                'Bucket' => $this->bucket,
                'Key' => $key,
                'SaveAs' => $targetPath,
            ]);
        } catch (S3Exception $e) {
            throw new RuntimeException(\sprintf('Failed to download file from S3 bucket "%s" with key "%s": %s', $this->bucket, $key, $e->getMessage()), 0, $e);
        }
    }
}
