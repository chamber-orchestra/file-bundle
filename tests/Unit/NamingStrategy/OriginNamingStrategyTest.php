<?php

declare(strict_types=1);

/*
 * This file is part of the ChamberOrchestra package.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Tests\Unit\NamingStrategy;

use ChamberOrchestra\FileBundle\NamingStrategy\OriginNamingStrategy;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\File\File;
use Symfony\Component\HttpFoundation\File\UploadedFile;

class OriginNamingStrategyTest extends TestCase
{
    private OriginNamingStrategy $strategy;

    protected function setUp(): void
    {
        $this->strategy = new OriginNamingStrategy();
    }

    public function testNameWithRegularFileReturnsBasename(): void
    {
        $tmpFile = \tempnam(\sys_get_temp_dir(), 'test');
        \file_put_contents($tmpFile, 'content');

        $file = new File($tmpFile);
        $name = $this->strategy->name($file);

        self::assertSame(\basename($tmpFile), $name);

        \unlink($tmpFile);
    }

    public function testNameWithUploadedFileReturnsClientOriginalName(): void
    {
        $tmpFile = \tempnam(\sys_get_temp_dir(), 'test');
        \file_put_contents($tmpFile, 'content');

        $file = new UploadedFile($tmpFile, 'my-photo.jpg', 'image/jpeg', null, true);
        $name = $this->strategy->name($file);

        self::assertSame('my-photo.jpg', $name);

        \unlink($tmpFile);
    }

    public function testNameStripsPathTraversalFromUploadedFile(): void
    {
        $tmpFile = \tempnam(\sys_get_temp_dir(), 'test');
        \file_put_contents($tmpFile, 'content');

        $file = new UploadedFile($tmpFile, '../../etc/passwd', 'text/plain', null, true);
        $name = $this->strategy->name($file);

        self::assertSame('passwd', $name);
        self::assertStringNotContainsString('..', $name);
        self::assertStringNotContainsString('/', $name);

        \unlink($tmpFile);
    }
}
