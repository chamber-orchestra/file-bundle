<?php

declare(strict_types=1);

/*
 * This file is part of the ChamberOrchestra package.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace ChamberOrchestra\FileBundle\DependencyInjection;

use Symfony\Component\Config\Definition\Builder\FloatNodeDefinition;
use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

final class Configuration implements ConfigurationInterface
{
    private const array SUPPORTED_STORAGE_DRIVERS = ['file_system', 's3'];
    private const array SUPPORTED_RETRY_MODES = ['legacy', 'standard', 'adaptive'];

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
                            ->booleanNode('path_style')
                                ->defaultFalse()
                                ->info('Use path-style addressing (required for LocalStack and some S3-compatible services).')
                            ->end()
                            ->arrayNode('credentials')
                                ->children()
                                    ->scalarNode('key')->isRequired()->end()
                                    ->scalarNode('secret')->isRequired()->end()
                                ->end()
                            ->end()
                            ->arrayNode('http')
                                ->info('S3 client HTTP timeouts. If set, both keys are required. Ignored by the file_system driver.')
                                ->children()
                                    ->append(self::positiveSeconds('connect_timeout', 'Connection phase timeout, seconds.'))
                                    ->append(self::positiveSeconds('timeout', 'Whole transfer timeout including the connection, seconds.'))
                                ->end()
                            ->end()
                            ->arrayNode('retries')
                                ->info('S3 client retry policy. If set, both keys are required. Ignored by the file_system driver.')
                                ->children()
                                    ->enumNode('mode')
                                        ->values(self::SUPPORTED_RETRY_MODES)
                                        ->isRequired()
                                    ->end()
                                    ->integerNode('max_attempts')
                                        ->min(1)
                                        ->isRequired()
                                        ->info('Total attempts per operation, the first one included.')
                                    ->end()
                                ->end()
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

    private static function positiveSeconds(string $name, string $info): FloatNodeDefinition
    {
        $node = new FloatNodeDefinition($name);
        $node
            ->info($info)
            ->isRequired()
            ->beforeNormalization()
                // FloatNode accepts an int but returns it unchanged; the S3 client contract wants a float.
                ->ifTrue(static fn (mixed $v): bool => \is_int($v))
                ->then(static fn (int $v): float => (float) $v)
            ->end()
            ->validate()
                ->ifTrue(static fn (float $v): bool => !($v > 0))
                ->thenInvalid('The value must be greater than 0, got %s.')
            ->end()
        ;

        return $node;
    }
}
