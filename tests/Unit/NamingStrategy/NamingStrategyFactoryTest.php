<?php

declare(strict_types=1);

/*
 * This file is part of the ChamberOrchestra package.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Tests\Unit\NamingStrategy;

use ChamberOrchestra\FileBundle\Exception\InvalidArgumentException;
use ChamberOrchestra\FileBundle\NamingStrategy\HashingNamingStrategy;
use ChamberOrchestra\FileBundle\NamingStrategy\NamingStrategyFactory;
use ChamberOrchestra\FileBundle\NamingStrategy\OriginNamingStrategy;
use PHPUnit\Framework\TestCase;

class NamingStrategyFactoryTest extends TestCase
{
    protected function setUp(): void
    {
        $ref = new \ReflectionClass(NamingStrategyFactory::class);
        $prop = $ref->getProperty('factories');
        $prop->setValue(null, []);
    }

    public function testCreateReturnsCorrectInstance(): void
    {
        $hashing = NamingStrategyFactory::create(HashingNamingStrategy::class);
        self::assertInstanceOf(HashingNamingStrategy::class, $hashing);

        $origin = NamingStrategyFactory::create(OriginNamingStrategy::class);
        self::assertInstanceOf(OriginNamingStrategy::class, $origin);
    }

    public function testCreateReturnsCachedInstance(): void
    {
        $first = NamingStrategyFactory::create(HashingNamingStrategy::class);
        $second = NamingStrategyFactory::create(HashingNamingStrategy::class);

        self::assertSame($first, $second);
    }

    public function testCreateThrowsForNonExistentClass(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('does not exist');

        NamingStrategyFactory::create('App\\NonExistent\\Strategy');
    }

    public function testCreateThrowsForNonImplementingClass(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('must implement');

        NamingStrategyFactory::create(\stdClass::class);
    }
}
