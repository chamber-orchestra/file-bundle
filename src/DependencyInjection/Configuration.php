<?php

declare(strict_types=1);

/*
 * This file is part of the ChamberOrchestra package.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace ChamberOrchestra\FileBundle\DependencyInjection;

use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

final class Configuration implements ConfigurationInterface
{
    private const array SUPPORTED_STORAGE_DRIVERS = ['file_system', 's3'];

    public function getConfigTreeBuilder(): TreeBuilder
    {
        $tb = new TreeBuilder('chamber_orchestra_file');

        $tb->getRootNode()
            ->children()
                ->scalarNode('default_storage')
                    ->defaultNull()
                    ->info('Name of the default storage. If null, the first defined storage is used.')
                ->end()
                ->scalarNode('archive_path')
                    ->defaultValue('%kernel.project_dir%/var/archive')
                    ->info('Directory where files are moved when using Behaviour::Archive.')
                ->end()
                ->arrayNode('storages')
                    ->useAttributeAsKey('name')
                    ->requiresAtLeastOneElement()
                    ->arrayPrototype()
                        ->canBeEnabled()
                        ->children()
                            ->enumNode('driver')
                                ->values(self::SUPPORTED_STORAGE_DRIVERS)
                                ->defaultValue('file_system')
                                ->beforeNormalization()
                                    ->ifString()
                                    ->then(static fn (string $v): string => \strtolower($v))
                                ->end()
                            ->end()
                            ->scalarNode('path')
                                ->defaultValue('%kernel.project_dir%/public/uploads')
                            ->end()
                            ->scalarNode('uri_prefix')
                                ->defaultNull()
                            ->end()
                            ->scalarNode('bucket')
                                ->defaultNull()
                            ->end()
                            ->scalarNode('region')
                                ->defaultNull()
                            ->end()
                            ->scalarNode('endpoint')
                                ->defaultNull()
                            ->end()
                        ->end()
                        ->validate()
                            ->ifTrue(static fn (array $v): bool => 's3' === ($v['driver'] ?? 'file_system') && (null === $v['bucket'] || '' === $v['bucket']))
                            ->thenInvalid('The "bucket" option is required when driver is "s3".')
                        ->end()
                        ->validate()
                            ->ifTrue(static fn (array $v): bool => 's3' === ($v['driver'] ?? 'file_system') && (null === $v['region'] || '' === $v['region']))
                            ->thenInvalid('The "region" option is required when driver is "s3".')
                        ->end()
                    ->end()
                ->end()
            ->end()
        ;

        return $tb;
    }
}
