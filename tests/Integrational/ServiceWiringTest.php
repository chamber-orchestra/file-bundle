<?php

declare(strict_types=1);

/*
 * This file is part of the ChamberOrchestra package.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Tests\Integrational;

use ChamberOrchestra\FileBundle\Handler\Handler;
use ChamberOrchestra\FileBundle\Mapping\Driver\UploadableDriver;
use ChamberOrchestra\FileBundle\Storage\StorageResolver;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

class ServiceWiringTest extends KernelTestCase
{
    protected static function getKernelClass(): string
    {
        return TestKernel::class;
    }

    public function testHandlerServiceExists(): void
    {
        self::bootKernel();
        $container = self::getContainer();

        self::assertTrue($container->has(Handler::class));
        self::assertInstanceOf(Handler::class, $container->get(Handler::class));
    }

    public function testStorageResolverExists(): void
    {
        self::bootKernel();
        $container = self::getContainer();

        self::assertTrue($container->has(StorageResolver::class));
        self::assertInstanceOf(StorageResolver::class, $container->get(StorageResolver::class));
    }

    public function testUploadableDriverExists(): void
    {
        self::bootKernel();
        $container = self::getContainer();

        self::assertTrue($container->has(UploadableDriver::class));
        self::assertInstanceOf(UploadableDriver::class, $container->get(UploadableDriver::class));
    }
}
