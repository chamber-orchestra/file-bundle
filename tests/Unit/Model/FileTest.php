<?php

declare(strict_types=1);

/*
 * This file is part of the ChamberOrchestra package.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Tests\Unit\Model;

use ChamberOrchestra\FileBundle\Model\File;
use ChamberOrchestra\FileBundle\Model\FileInterface;
use PHPUnit\Framework\TestCase;

class FileTest extends TestCase
{
    public function testConstructorSetsPathAndUri(): void
    {
        $file = new File('/tmp/test.txt', '/uploads/test.txt');

        self::assertSame('/tmp/test.txt', $file->getPathname());
        self::assertSame('/uploads/test.txt', $file->getUri());
    }

    public function testConstructorWithNullUri(): void
    {
        $file = new File('/tmp/test.txt');

        self::assertNull($file->getUri());
    }

    public function testImplementsFileInterface(): void
    {
        $file = new File('/tmp/test.txt', '/uploads/test.txt');

        self::assertInstanceOf(FileInterface::class, $file);
    }

    public function testFileDoesNotRequirePhysicalFile(): void
    {
        $file = new File('/nonexistent/path/file.txt', '/uploads/file.txt');

        self::assertSame('/nonexistent/path/file.txt', $file->getPathname());
        self::assertSame('/uploads/file.txt', $file->getUri());
    }
}
