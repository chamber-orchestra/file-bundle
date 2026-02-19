<?php

declare(strict_types=1);

/*
 * This file is part of the ChamberOrchestra package.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Tests\Unit\Model;

use ChamberOrchestra\FileBundle\Exception\RuntimeException;
use ChamberOrchestra\FileBundle\Model\File;
use PHPUnit\Framework\TestCase;

class ImageTraitTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = \sys_get_temp_dir().'/image_trait_test_'.\uniqid();
        \mkdir($this->tempDir, 0777, true);
    }

    protected function tearDown(): void
    {
        $files = \glob($this->tempDir.'/*');
        foreach ($files as $f) {
            \unlink($f);
        }
        \rmdir($this->tempDir);
    }

    public function testIsImageReturnsTrueForImage(): void
    {
        $path = $this->createTestImage(100, 50);
        $file = new File($path, '/uploads/test.png');

        self::assertTrue($file->isImage());
    }

    public function testIsImageReturnsFalseForNonImage(): void
    {
        $path = $this->tempDir.'/test.txt';
        \file_put_contents($path, 'not an image');
        $file = new File($path, '/uploads/test.txt');

        self::assertFalse($file->isImage());
    }

    public function testIsImageReturnsFalseForNonExistentFile(): void
    {
        $file = new File('/nonexistent/path.png', '/uploads/path.png');

        self::assertFalse($file->isImage());
    }

    public function testGetImageSizeReturnsCorrectDimensions(): void
    {
        $path = $this->createTestImage(100, 50);
        $file = new File($path, '/uploads/test.png');

        self::assertSame(100, $file->getWidth());
        self::assertSame(50, $file->getHeight());
    }

    public function testGetRatio(): void
    {
        $path = $this->createTestImage(100, 50);
        $file = new File($path, '/uploads/test.png');

        self::assertSame(2.0, $file->getRatio());
    }

    public function testGetImageSizeThrowsForNonImage(): void
    {
        $path = $this->tempDir.'/test.txt';
        \file_put_contents($path, 'not an image');
        $file = new File($path, '/uploads/test.txt');

        $this->expectException(RuntimeException::class);
        $file->getImageSize();
    }

    private function createTestImage(int $width, int $height): string
    {
        $image = \imagecreatetruecolor($width, $height);
        $path = $this->tempDir.'/test.png';
        \imagepng($image, $path);
        \imagedestroy($image);

        return $path;
    }
}
