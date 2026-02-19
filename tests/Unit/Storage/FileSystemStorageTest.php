<?php

declare(strict_types=1);

/*
 * This file is part of the ChamberOrchestra package.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Tests\Unit\Storage;

use ChamberOrchestra\FileBundle\Exception\RuntimeException;
use ChamberOrchestra\FileBundle\NamingStrategy\NamingStrategyInterface;
use ChamberOrchestra\FileBundle\Storage\FileSystemStorage;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\File\File;

class FileSystemStorageTest extends TestCase
{
    private string $uploadPath;
    private FileSystemStorage $storage;

    protected function setUp(): void
    {
        $this->uploadPath = \sys_get_temp_dir().'/file_system_storage_test_'.\uniqid();
        \mkdir($this->uploadPath, 0777, true);

        $this->storage = new FileSystemStorage($this->uploadPath, '/uploads');
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->uploadPath);
    }

    public function testUploadMovesFileAndReturnsRelativePath(): void
    {
        $tmpFile = $this->uploadPath.'/source.txt';
        \file_put_contents($tmpFile, 'content');
        $file = new File($tmpFile);

        $namingStrategy = $this->createMock(NamingStrategyInterface::class);
        $namingStrategy->method('name')->willReturn('renamed.txt');

        $relativePath = $this->storage->upload($file, $namingStrategy);

        self::assertSame('/renamed.txt', $relativePath);
        self::assertFileExists($this->uploadPath.'/renamed.txt');
    }

    public function testUploadWithEmptyPrefix(): void
    {
        $tmpFile = $this->uploadPath.'/source.txt';
        \file_put_contents($tmpFile, 'content');
        $file = new File($tmpFile);

        $namingStrategy = $this->createMock(NamingStrategyInterface::class);
        $namingStrategy->method('name')->willReturn('file.txt');

        $result = $this->storage->upload($file, $namingStrategy, '');

        self::assertSame('/file.txt', $result);
    }

    public function testUploadWithPrefix(): void
    {
        $tmpFile = $this->uploadPath.'/source.txt';
        \file_put_contents($tmpFile, 'content');
        $file = new File($tmpFile);

        $namingStrategy = $this->createMock(NamingStrategyInterface::class);
        $namingStrategy->method('name')->willReturn('file.txt');

        $result = $this->storage->upload($file, $namingStrategy, 'avatars');

        self::assertSame('/avatars/file.txt', $result);
        self::assertFileExists($this->uploadPath.'/avatars/file.txt');
    }

    public function testRemoveDeletesExistingFile(): void
    {
        $filePath = $this->uploadPath.'/to-delete.txt';
        \file_put_contents($filePath, 'content');

        self::assertTrue($this->storage->remove($filePath));
        self::assertFileDoesNotExist($filePath);
    }

    public function testRemoveReturnsFalseForNonExistent(): void
    {
        self::assertFalse($this->storage->remove($this->uploadPath.'/nonexistent.txt'));
    }

    public function testResolvePathPrependsUploadPath(): void
    {
        $result = $this->storage->resolvePath('/test/file.txt');

        self::assertSame($this->uploadPath.'/test/file.txt', $result);
    }

    public function testResolveUriPrependsUriPrefix(): void
    {
        $result = $this->storage->resolveUri('/test/file.txt');

        self::assertSame('/uploads/test/file.txt', $result);
    }

    public function testResolveUriReturnsNullWithoutPrefix(): void
    {
        $storage = new FileSystemStorage($this->uploadPath);

        self::assertNull($storage->resolveUri('/test/file.txt'));
    }

    public function testResolveRelativePathStripsUploadPath(): void
    {
        $fullPath = $this->uploadPath.'/test/file.txt';

        $result = $this->storage->resolveRelativePath($fullPath);

        self::assertSame('/test/file.txt', $result);
    }

    public function testResolveRelativePathReturnsPathAsIsWhenNotPrefixed(): void
    {
        $result = $this->storage->resolveRelativePath('/some/other/path/file.txt');

        self::assertSame('/some/other/path/file.txt', $result);
    }

    public function testResolveRelativePathDoesNotReplaceMiddleOccurrences(): void
    {
        // Ensure that the upload path appearing in the middle of the string is not stripped
        $pathWithUploadPathInMiddle = '/prefix'.$this->uploadPath.'/file.txt';

        $result = $this->storage->resolveRelativePath($pathWithUploadPathInMiddle);

        self::assertSame($pathWithUploadPathInMiddle, $result);
    }

    public function testUploadRejectsFilenameWithDirectorySeparator(): void
    {
        $tmpFile = $this->uploadPath.'/source.txt';
        \file_put_contents($tmpFile, 'content');
        $file = new File($tmpFile);

        $namingStrategy = $this->createMock(NamingStrategyInterface::class);
        $namingStrategy->method('name')->willReturn('../etc/passwd');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('must not contain directory separators');

        $this->storage->upload($file, $namingStrategy);
    }

    public function testUploadRejectsFilenameWithBackslash(): void
    {
        $tmpFile = $this->uploadPath.'/source.txt';
        \file_put_contents($tmpFile, 'content');
        $file = new File($tmpFile);

        $namingStrategy = $this->createMock(NamingStrategyInterface::class);
        $namingStrategy->method('name')->willReturn('..\\etc\\passwd');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('must not contain directory separators');

        $this->storage->upload($file, $namingStrategy);
    }

    public function testUploadRejectsFilenameWithDotDot(): void
    {
        $tmpFile = $this->uploadPath.'/source.txt';
        \file_put_contents($tmpFile, 'content');
        $file = new File($tmpFile);

        $namingStrategy = $this->createMock(NamingStrategyInterface::class);
        $namingStrategy->method('name')->willReturn('..malicious.txt');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('must not contain directory separators');

        $this->storage->upload($file, $namingStrategy);
    }

    public function testResolvePathRejectsPathTraversal(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Path traversal detected');

        $this->storage->resolvePath('/../../../etc/passwd');
    }

    public function testDownloadRejectsPathTraversal(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Path traversal detected');

        $this->storage->download('/../../etc/passwd', '/tmp/target.txt');
    }

    public function testDownloadCopiesFileToTarget(): void
    {
        $sourceFile = $this->uploadPath.'/test/file.txt';
        \mkdir(\dirname($sourceFile), 0777, true);
        \file_put_contents($sourceFile, 'downloaded content');

        $targetPath = $this->uploadPath.'/archive/file.txt';
        \mkdir(\dirname($targetPath), 0777, true);

        $this->storage->download('/test/file.txt', $targetPath);

        self::assertFileExists($targetPath);
        self::assertSame('downloaded content', \file_get_contents($targetPath));
        // Source file should still exist
        self::assertFileExists($sourceFile);
    }

    public function testDownloadThrowsWhenSourceDoesNotExist(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('does not exist');

        $this->storage->download('/nonexistent/file.txt', $this->uploadPath.'/target.txt');
    }

    private function removeDirectory(string $dir): void
    {
        if (!\is_dir($dir)) {
            return;
        }

        $items = \scandir($dir);
        foreach ($items as $item) {
            if ('.' === $item || '..' === $item) {
                continue;
            }
            $path = $dir.'/'.$item;
            \is_dir($path) ? $this->removeDirectory($path) : \unlink($path);
        }
        \rmdir($dir);
    }
}
