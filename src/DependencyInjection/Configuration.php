<?php

namespace Rcsofttech\AuditTrailBundle\DependencyInjection;

use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

class Configuration implements ConfigurationInterface
{
    public function getConfigTreeBuilder(): TreeBuilder
    {
        $treeBuilder = new TreeBuilder('audit_trail');
        $rootNode = $treeBuilder->getRootNode();

        $rootNode
            ->children()
                ->booleanNode('enabled')->defaultTrue()->end()
                ->arrayNode('ignored_properties')
                    ->scalarPrototype()->end()
                    ->defaultValue(['updatedAt', 'updated_at'])
                ->end()
                ->arrayNode('ignored_entities')
                    ->scalarPrototype()->end()
                    ->defaultValue([])
                ->end()
                ->integerNode('retention_days')->defaultValue(365)->min(1)->end()
                ->booleanNode('track_ip_address')->defaultTrue()->end()
                ->booleanNode('track_user_agent')->defaultTrue()->end()
            ->end();

        return $treeBuilder;
    }
}
