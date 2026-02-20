<?php

declare(strict_types=1);

use ChamberOrchestra\FileBundle\Handler\Handler;
use ChamberOrchestra\FileBundle\Mapping\Driver\UploadableDriver;
use ChamberOrchestra\FileBundle\Storage\StorageResolver;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

return static function (ContainerConfigurator $container): void {
    $services = $container->services();

    $services->defaults()
        ->autowire()
        ->autoconfigure()
    ;

    $services->load('ChamberOrchestra\\FileBundle\\', '../../*')
        ->exclude([
            '../../DependencyInjection',
            '../../Resources',
            '../../ExceptionInterface',
            '../../NamingStrategy',
            '../../Model',
            '../../Mapping',
            '../../Entity',
            '../../Events',
            '../../Storage',
            '../../Form',
        ]);

    $services->set(StorageResolver::class);

    $services->set(Handler::class)
        ->lazy();

    $services->set(UploadableDriver::class)
        ->autowire()
        ->autoconfigure();
};
