<?php

declare(strict_types=1);

/*
 * This file is part of the ChamberOrchestra package.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Tests\Integrational;

use ChamberOrchestra\FileBundle\ChamberOrchestraFileBundle;
use ChamberOrchestra\MetadataBundle\ChamberOrchestraMetadataBundle;
use Doctrine\Bundle\DoctrineBundle\DoctrineBundle;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Component\Config\Loader\LoaderInterface;
use Symfony\Component\HttpKernel\Kernel;

class TestKernel extends Kernel
{
    public function registerBundles(): iterable
    {
        return [
            new FrameworkBundle(),
            new DoctrineBundle(),
            new ChamberOrchestraMetadataBundle(),
            new ChamberOrchestraFileBundle(),
        ];
    }

    public function registerContainerConfiguration(LoaderInterface $loader): void
    {
        $loader->load(function ($container): void {
            $container->loadFromExtension('framework', [
                'test' => true,
                'secret' => 'test',
                'http_method_override' => false,
                'handle_all_throwables' => true,
                'php_errors' => ['log' => true],
            ]);

            $container->loadFromExtension('doctrine', [
                'dbal' => [
                    'driver' => 'pdo_sqlite',
                    'url' => 'sqlite:///:memory:',
                ],
                'orm' => [
                    'auto_mapping' => false,
                    'mappings' => [
                        'TestFixtures' => [
                            'type' => 'attribute',
                            'dir' => __DIR__.'/../Fixtures/Entity',
                            'prefix' => 'Tests\Fixtures\Entity',
                            'is_bundle' => false,
                        ],
                    ],
                ],
            ]);

            $storagePath = \realpath(\sys_get_temp_dir()).'/file_bundle_test';
            $container->loadFromExtension('chamber_orchestra_file', [
                'storages' => [
                    'default' => [
                        'driver' => 'file_system',
                        'path' => $storagePath,
                        'uri_prefix' => '/uploads',
                    ],
                ],
            ]);
        });
    }

    public function getCacheDir(): string
    {
        return \sys_get_temp_dir().'/file_bundle_test_cache/'.$this->environment;
    }

    public function getLogDir(): string
    {
        return \sys_get_temp_dir().'/file_bundle_test_logs';
    }
}
