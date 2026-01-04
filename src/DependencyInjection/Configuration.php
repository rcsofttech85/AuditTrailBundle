<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\DependencyInjection;

use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

final class Configuration implements ConfigurationInterface
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
            ->scalarNode('table_prefix')->defaultValue('')->end()
            ->scalarNode('table_suffix')->defaultValue('')->end()
            ->scalarNode('timezone')->defaultValue('UTC')->end()
            ->arrayNode('ignored_entities')
            ->scalarPrototype()->end()
            ->defaultValue([])
            ->end()
            ->integerNode('retention_days')->defaultValue(365)->min(1)->end()
            ->booleanNode('track_ip_address')->defaultTrue()->end()
            ->booleanNode('track_user_agent')->defaultTrue()->end()
            ->booleanNode('enable_soft_delete')->defaultTrue()->end()
            ->scalarNode('soft_delete_field')->defaultValue('deletedAt')->end()
            ->booleanNode('enable_hard_delete')->defaultTrue()->end()
            ->booleanNode('defer_transport_until_commit')->defaultTrue()->end()
            ->booleanNode('fail_on_transport_error')->defaultFalse()->end()
            ->booleanNode('fallback_to_database')->defaultTrue()->end()
            ->arrayNode('transports')
            ->addDefaultsIfNotSet()
            ->children()
            ->booleanNode('doctrine')->defaultTrue()->end()
            ->arrayNode('http')
            ->canBeEnabled()
            ->children()
            ->scalarNode('endpoint')->isRequired()->cannotBeEmpty()->end()
            ->arrayNode('headers')
            ->scalarPrototype()->end()
            ->defaultValue([])
            ->end()
            ->integerNode('timeout')->defaultValue(5)->min(1)->end()
            ->end()
            ->end()
            ->arrayNode('queue')
            ->canBeEnabled()
            ->info('When enabled, audit logs are dispatched via Symfony Messenger. ' .
                "You must define a transport named 'audit_trail'.")

            ->children()
            ->scalarNode('bus')
            ->defaultNull()->end()
            ->end()
            ->end()
            ->end()
            ->end()
            ->arrayNode('integrity')
            ->canBeEnabled()
            ->children()
            ->scalarNode('secret')
            ->info('Secret key used for HMAC signature. Required if integrity is enabled.')
            ->defaultNull()
            ->end()
            ->enumNode('algorithm')
            ->values(['sha256', 'sha384', 'sha512'])
            ->defaultValue('sha256')
            ->end()
            ->end()
            ->validate()
            ->ifTrue(fn ($v) => $v['enabled'] && (null === $v['secret'] || '' === $v['secret']))
            ->thenInvalid('The "secret" must be configured when integrity is enabled.')
            ->end()
            ->end()
            ->end();

        return $treeBuilder;
    }
}
