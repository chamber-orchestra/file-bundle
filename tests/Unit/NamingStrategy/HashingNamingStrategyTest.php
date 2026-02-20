<?php

declare(strict_types=1);

/*
 * This file is part of the ChamberOrchestra package.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Tests\Unit\NamingStrategy;

use ChamberOrchestra\FileBundle\NamingStrategy\HashingNamingStrategy;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\File\File;
use Symfony\Component\HttpFoundation\File\UploadedFile;

class HashingNamingStrategyTest extends TestCase
{
    private HashingNamingStrategy $strategy;

    protected function setUp(): void
    {
        $this->strategy = new HashingNamingStrategy();
    }

    public function testNameReturnsHashWithExtension(): void
    {
        $tmpFile = \tempnam(\sys_get_temp_dir(), 'test');
        \file_put_contents($tmpFile, 'test content');

        $file = new File($tmpFile);
        $name = $this->strategy->name($file);

        self::assertMatchesRegularExpression('/^[a-f0-9]{32}\..+$/', $name);

        \unlink($tmpFile);
    }

    public function testNameWithUploadedFileUsesClientOriginalName(): void
    {
        $tmpFile = \tempnam(\sys_get_temp_dir(), 'test');
        \file_put_contents($tmpFile, 'test content');

        $file = new UploadedFile($tmpFile, 'original.txt', 'text/plain', null, true);
        $name = $this->strategy->name($file);

        self::assertMatchesRegularExpression('/^[a-f0-9]{32}\..+$/', $name);

        \unlink($tmpFile);
    }

    public function testNameWithRegularFileUsesBasename(): void
    {
        $tmpFile = \tempnam(\sys_get_temp_dir(), 'test');
        \file_put_contents($tmpFile, 'test content');

        $file = new File($tmpFile);
        $name = $this->strategy->name($file);

        self::assertMatchesRegularExpression('/^[a-f0-9]{32}\..+$/', $name);

        \unlink($tmpFile);
    }

    public function testNameGeneratesUniqueNamesForSameFile(): void
    {
        $tmpFile = \tempnam(\sys_get_temp_dir(), 'test');
        \file_put_contents($tmpFile, 'test content');

        $file = new File($tmpFile);
        $name1 = $this->strategy->name($file);
        $name2 = $this->strategy->name($file);

        self::assertNotSame($name1, $name2);

        \unlink($tmpFile);
    }
}
