<?php

declare(strict_types=1);

/*
 * This file is part of the ChamberOrchestra package.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Tests\Unit\Storage;

use ChamberOrchestra\FileBundle\Exception\InvalidArgumentException;
use ChamberOrchestra\FileBundle\Storage\StorageInterface;
use ChamberOrchestra\FileBundle\Storage\StorageResolver;
use PHPUnit\Framework\TestCase;

class StorageResolverTest extends TestCase
{
    public function testAddAndGetReturnsStorage(): void
    {
        $resolver = new StorageResolver();
        $storage = $this->createMock(StorageInterface::class);

        $resolver->add('concert_hall', $storage);

        self::assertSame($storage, $resolver->get('concert_hall'));
    }

    public function testGetThrowsForUnknownStorage(): void
    {
        $resolver = new StorageResolver();
        $resolver->add('default', $this->createMock(StorageInterface::class));

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Storage "backstage" is not registered');

        $resolver->get('backstage');
    }

    public function testAddThrowsForEmptyName(): void
    {
        $resolver = new StorageResolver();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Storage name must not be empty');

        $resolver->add('', $this->createMock(StorageInterface::class));
    }

    public function testMultipleStoragesCanBeRegistered(): void
    {
        $resolver = new StorageResolver();
        $public = $this->createMock(StorageInterface::class);
        $secure = $this->createMock(StorageInterface::class);

        $resolver->add('public', $public);
        $resolver->add('secure', $secure);

        self::assertSame($public, $resolver->get('public'));
        self::assertSame($secure, $resolver->get('secure'));
    }

    public function testAddOverwritesExistingStorage(): void
    {
        $resolver = new StorageResolver();
        $first = $this->createMock(StorageInterface::class);
        $second = $this->createMock(StorageInterface::class);

        $resolver->add('default', $first);
        $resolver->add('default', $second);

        self::assertSame($second, $resolver->get('default'));
    }
}
