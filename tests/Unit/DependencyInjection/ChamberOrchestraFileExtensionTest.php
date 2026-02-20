<?php

declare(strict_types=1);

/*
 * This file is part of the ChamberOrchestra package.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Tests\Unit\DependencyInjection;

use ChamberOrchestra\FileBundle\DependencyInjection\ChamberOrchestraFileExtension;
use ChamberOrchestra\FileBundle\Handler\Handler;
use ChamberOrchestra\FileBundle\Storage\StorageResolver;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;

class ChamberOrchestraFileExtensionTest extends TestCase
{
    private ContainerBuilder $container;

    protected function setUp(): void
    {
        $this->container = new ContainerBuilder();
        $this->container->setParameter('kernel.project_dir', '/project');

        $extension = new ChamberOrchestraFileExtension();
        $extension->load([
            'chamber_orchestra_file' => [
                'storages' => [
                    'default' => [
                        'driver' => 'file_system',
                        'path' => '/var/uploads',
                        'uri_prefix' => '/uploads',
                    ],
                ],
            ],
        ], $this->container);
    }

    public function testLoadRegistersStorageResolver(): void
    {
        self::assertTrue($this->container->hasDefinition(StorageResolver::class));
    }

    public function testLoadRegistersNamedStorage(): void
    {
        self::assertTrue($this->container->hasDefinition('chamber_orchestra_file.storage.default'));
    }

    public function testLoadRegistersHandler(): void
    {
        self::assertTrue($this->container->hasDefinition(Handler::class));
    }
}
