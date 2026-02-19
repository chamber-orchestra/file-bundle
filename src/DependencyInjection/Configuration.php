<?php

declare(strict_types=1);

namespace Dev\FileBundle\DependencyInjection;

use Symfony\Component\Config\Definition\Builder\ArrayNodeDefinition;
use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

class Configuration implements ConfigurationInterface
{
    protected $supportedDbDrivers = ['orm'];
    protected $supportedStorage = ['file_system'];

    public function getConfigTreeBuilder() : TreeBuilder
    {
        $tb = new TreeBuilder('dev_file');
        $root = $tb->getRootNode();
        $this->addGeneralSection($root);

        return $tb;
    }

    protected function addGeneralSection(ArrayNodeDefinition $node) : void
    {
        $node
            ->children()
                ->scalarNode('db_driver')
                ->defaultValue('%dev_file.db_driver%')
                ->beforeNormalization()
                    ->ifString()
                    ->then(function ($v) {
                        return strtolower($v);
                    })
                ->end()
                ->validate()
                ->ifNotInArray($this->supportedDbDrivers)
                    ->thenInvalid('The db driver %s is not supported. Please choose one of '.implode(', ', $this->supportedDbDrivers))
                ->end()
            ->end()
            ->arrayNode('storage')
                ->children()
                    ->scalarNode('driver')->defaultValue('file_system')->isRequired()
                    ->beforeNormalization()
                        ->ifString()
                        ->then(function ($v) {
                            return strtolower($v);
                        })
                    ->end()
                    ->validate()
                        ->ifNotInArray($this->supportedStorage)
                            ->thenInvalid('The storage %s is not supported. Please choose one of '.implode(', ', $this->supportedStorage))
                        ->end()
                    ->end()
                    ->scalarNode('path')
                        ->isRequired()
                        ->defaultValue('%kernel.project_dir%/public/uploads')
                    ->end()
                    ->scalarNode('uri_prefix')
                        ->defaultValue('/uploads')
                    ->end()
                ->end()
            ->end()
            ->end();
    }
}
