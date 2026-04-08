<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\DependencyInjection;

use Override;
use Symfony\Component\Config\Definition\Builder\ArrayNodeDefinition;
use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

use function is_string;
use function str_starts_with;

final class Configuration implements ConfigurationInterface
{
    private const array VALID_HTTP_SCHEMES = ['http://', 'https://'];

    #[Override]
    public function getConfigTreeBuilder(): TreeBuilder
    {
        $treeBuilder = new TreeBuilder('audit_trail');
        $rootNode = $treeBuilder->getRootNode();

        $this->configureBaseSettings($rootNode);
        $this->configureTransports($rootNode);
        $this->configureIntegrity($rootNode);

        $rootNode->end();

        return $treeBuilder;
    }

    private function configureBaseSettings(ArrayNodeDefinition $rootNode): void
    {
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
            ->scalarNode('cache_pool')->defaultNull()->end()
            ->scalarNode('admin_permission')
            ->defaultValue('ROLE_ADMIN')
            ->cannotBeEmpty()
            ->info('Required Symfony permission/role for EasyAdmin audit UI actions such as export and revert.')
            ->end()
            ->arrayNode('audited_methods')
            ->scalarPrototype()->end()
            ->defaultValue(['GET'])
            ->end()
            ->enumNode('collection_serialization_mode')
            ->values(['lazy', 'ids_only', 'eager'])
            ->defaultValue('lazy')
            ->info('Determines how to serialize Doctrine collections: lazy (placeholder), ids_only (query IDs), or eager (initialize).')
            ->end()
            ->integerNode('max_collection_items')
            ->defaultValue(100)
            ->min(1)
            ->info('Maximum number of items to serialize in a collection.')
            ->end()
            ->end();
    }

    private function configureTransports(ArrayNodeDefinition $rootNode): void
    {
        $rootNode
            ->children()
            ->arrayNode('transports')
            ->addDefaultsIfNotSet()
            ->children()
            ->arrayNode('database')
            ->addDefaultsIfNotSet()
            ->children()
            ->booleanNode('enabled')->defaultTrue()->end()
            ->booleanNode('async')->defaultFalse()
            ->info('When true, audit logs are persisted via a Messenger worker instead of synchronously.')
            ->end()
            ->end()
            ->end()
            ->arrayNode('http')
            ->canBeEnabled()
            ->children()
            ->scalarNode('endpoint')
            ->defaultNull()
            ->validate()
            ->ifTrue(fn (mixed $value): bool => $this->isInvalidHttpEndpoint($value))
            ->thenInvalid('The endpoint must start with http:// or https://')
            ->end()
            ->end()
            ->arrayNode('headers')
            ->scalarPrototype()->end()
            ->defaultValue([])
            ->end()
            ->integerNode('timeout')->defaultValue(5)->min(1)->end()
            ->end()
            ->validate()
            ->ifTrue(static fn (array $v): bool => $v['enabled'] && ($v['endpoint'] === null || $v['endpoint'] === ''))
            ->thenInvalid('The "endpoint" must be configured when the HTTP transport is enabled.')
            ->end()
            ->end()
            ->arrayNode('queue')
            ->canBeEnabled()
            ->info(
                'When enabled, audit logs are dispatched via Symfony Messenger. '.
                'You must define a transport named \'audit_trail\'.'
            )
            ->children()
            ->scalarNode('bus')->defaultNull()->end()
            ->scalarNode('api_key')->defaultNull()->end()
            ->end()
            ->end()
            ->end()
            ->end()
            ->end();
    }

    private function configureIntegrity(ArrayNodeDefinition $rootNode): void
    {
        $rootNode
            ->children()
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
            ->ifTrue(static fn (array $v): bool => $v['enabled'] && ($v['secret'] === null || $v['secret'] === ''))
            ->thenInvalid('The "secret" must be configured when integrity is enabled.')
            ->end()
            ->end()
            ->end();
    }

    private function isInvalidHttpEndpoint(mixed $value): bool
    {
        if ($value === null) {
            return false;
        }

        if (!is_string($value)) {
            return true;
        }

        foreach (self::VALID_HTTP_SCHEMES as $scheme) {
            if (str_starts_with($value, $scheme)) {
                return false;
            }
        }

        return true;
    }
}
