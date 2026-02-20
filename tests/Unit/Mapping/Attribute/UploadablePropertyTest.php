<?php

declare(strict_types=1);

/*
 * This file is part of the ChamberOrchestra package.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Tests\Unit\Mapping\Attribute;

use ChamberOrchestra\FileBundle\Exception\InvalidArgumentException;
use ChamberOrchestra\FileBundle\Mapping\Attribute\UploadableProperty;
use Doctrine\ORM\Mapping\MappingAttribute;
use PHPUnit\Framework\TestCase;

class UploadablePropertyTest extends TestCase
{
    public function testMappedByIsStored(): void
    {
        $attr = new UploadableProperty(mappedBy: 'filePath');

        self::assertSame('filePath', $attr->mappedBy);
    }

    public function testImplementsMappingAttribute(): void
    {
        $attr = new UploadableProperty(mappedBy: 'filePath');

        self::assertInstanceOf(MappingAttribute::class, $attr);
    }

    public function testEmptyMappedByThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('must not be empty');

        new UploadableProperty(mappedBy: '');
    }
}
