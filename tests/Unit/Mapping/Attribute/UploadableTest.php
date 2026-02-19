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
use ChamberOrchestra\FileBundle\Mapping\Attribute\Uploadable;
use ChamberOrchestra\FileBundle\Mapping\Helper\Behaviour;
use ChamberOrchestra\FileBundle\NamingStrategy\HashingNamingStrategy;
use ChamberOrchestra\FileBundle\NamingStrategy\OriginNamingStrategy;
use Doctrine\ORM\Mapping\MappingAttribute;
use PHPUnit\Framework\TestCase;

class UploadableTest extends TestCase
{
    public function testDefaults(): void
    {
        $attr = new Uploadable(prefix: 'test');

        self::assertSame('test', $attr->prefix);
        self::assertSame(HashingNamingStrategy::class, $attr->namingStrategy);
        self::assertSame(Behaviour::Remove, $attr->behaviour);
    }

    public function testCustomValues(): void
    {
        $attr = new Uploadable(
            prefix: 'custom',
            namingStrategy: OriginNamingStrategy::class,
            behaviour: Behaviour::Keep,
        );

        self::assertSame('custom', $attr->prefix);
        self::assertSame(OriginNamingStrategy::class, $attr->namingStrategy);
        self::assertSame(Behaviour::Keep, $attr->behaviour);
    }

    public function testImplementsMappingAttribute(): void
    {
        $attr = new Uploadable(prefix: 'test');

        self::assertInstanceOf(MappingAttribute::class, $attr);
    }

    public function testPrefixWithPathTraversalThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('must not contain ".."');

        new Uploadable(prefix: '../secret');
    }

    public function testInvalidNamingStrategyThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('namingStrategy');

        new Uploadable(prefix: 'test', namingStrategy: \stdClass::class);
    }

    public function testEmptyNamingStrategyThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('must not be empty');

        new Uploadable(prefix: 'test', namingStrategy: '');
    }
}
