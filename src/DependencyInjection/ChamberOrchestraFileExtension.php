<?php

declare(strict_types=1);

namespace Dev\FileBundle\DependencyInjection;

use Dev\FileBundle\Handler\Handler;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\YamlFileLoader;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\File\File;
use Symfony\Component\HttpKernel\DependencyInjection\ConfigurableExtension;

class DevFileExtension extends ConfigurableExtension
{
    public function loadInternal(array $configs, ContainerBuilder $container): void
    {
        $container->setAlias('dev_file.storage', 'dev_file.storage.'.$configs['storage']['driver']);
        $container->setParameter('dev_file.storage.uri_prefix', $configs['storage']['uri_prefix']);
        $container->setParameter('dev_file.storage.path', $configs['storage']['path']);

        $this->loadServicesFiles($container, $configs);
    }

    private function loadServicesFiles(ContainerBuilder $container, array $configs): void
    {
        $loader = new YamlFileLoader($container, new FileLocator(__DIR__.'/../Resources/config'));
        $loader->load('services.yaml');
    }
}
