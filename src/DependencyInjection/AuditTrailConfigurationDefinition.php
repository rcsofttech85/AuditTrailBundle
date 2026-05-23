<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\DependencyInjection;

use Symfony\Component\Config\Definition\Builder\ArrayNodeDefinition;
use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;

use function array_key_exists;
use function is_array;
use function is_bool;
use function is_string;
use function preg_match;
use function sprintf;
use function str_ends_with;
use function str_starts_with;

/**
 * @internal
 */
final class AuditTrailConfigurationDefinition
{
    private const int MIN_INTEGRITY_SECRET_LENGTH = 32;

    private const array VALID_HTTP_SCHEMES = ['http://', 'https://'];

    private const string TABLE_NAME_FRAGMENT_PATTERN = '/^[A-Za-z_][A-Za-z0-9_]*$/';

    public function configure(DefinitionConfigurator $definition): void
    {
        /** @var ArrayNodeDefinition<TreeBuilder<'array'>> $rootNode */
        $rootNode = $definition->rootNode();

        $this->configureRootNode($rootNode);
    }

    public function createTreeBuilder(string $alias = 'audit_trail'): TreeBuilder
    {
        $treeBuilder = new TreeBuilder($alias, 'array');

        /** @var ArrayNodeDefinition<TreeBuilder<'array'>> $rootNode */
        $rootNode = $treeBuilder->getRootNode();

        $this->configureRootNode($rootNode);

        return $treeBuilder;
    }

    private function configureRootNode(ArrayNodeDefinition $rootNode): void
    {
        $this->configureBaseSettings($rootNode);
        $this->configureEasyAdmin($rootNode);
        $this->configureTransports($rootNode);
        $this->configureIntegrity($rootNode);

        $rootNode->end();
    }

    private function configureBaseSettings(ArrayNodeDefinition $rootNode): void
    {
        $rootNode
            ->beforeNormalization()
            ->ifArray()
            ->then(function (array $value): array {
                $value = $this->normalizeEasyAdminConfig($value);

                if (!$this->usesRemoteTransport($value)) {
                    return $value;
                }

                if (!array_key_exists('fail_on_transport_error', $value)) {
                    $value['fail_on_transport_error'] = true;
                }

                if (!array_key_exists('fallback_to_database', $value)) {
                    $value['fallback_to_database'] = false;
                }

                return $value;
            })
            ->end()
            ->children()
            ->booleanNode('enabled')->defaultTrue()->end()
            ->arrayNode('ignored_properties')
            ->scalarPrototype()->end()
            ->defaultValue(['updatedAt', 'updated_at'])
            ->end()
            ->stringNode('table_prefix')
            ->defaultValue('')
            ->validate()
            ->ifTrue(fn (string $value): bool => !$this->isValidTableNameFragment($value))
            ->thenInvalid(
                'The table_prefix may contain only letters, numbers, and underscores, and must not start with a digit.'
            )
            ->end()
            ->end()
            ->stringNode('table_suffix')
            ->defaultValue('')
            ->validate()
            ->ifTrue(fn (string $value): bool => !$this->isValidTableNameFragment($value))
            ->thenInvalid(
                'The table_suffix may contain only letters, numbers, and underscores, and must not start with a digit.'
            )
            ->end()
            ->end()
            ->scalarNode('timezone')->defaultValue('UTC')->end()
            ->arrayNode('ignored_entities')
            ->scalarPrototype()->end()
            ->defaultValue([])
            ->end()
            ->integerNode('retention_days')->defaultValue(365)->min(1)->end()
            ->booleanNode('track_ip_address')->defaultTrue()->end()
            ->booleanNode('track_user_agent')->defaultTrue()->end()
            ->booleanNode('enable_soft_delete')->defaultTrue()->end()
            ->scalarNode('soft_delete_field')
            ->defaultValue('deletedAt')
            ->cannotBeEmpty()
            ->info('Name of the nullable soft-delete field used by the built-in restore flow. Use timestamp-like fields such as deletedAt or archivedAt; boolean or status soft-delete markers require custom handling.')
            ->end()
            ->arrayNode('soft_delete_filter_names')
            ->scalarPrototype()->end()
            ->defaultValue(['softdeleteable'])
            ->info('Doctrine filter names that should be temporarily disabled while reverting soft-deleted entities.')
            ->end()
            ->booleanNode('enable_hard_delete')->defaultTrue()->end()
            ->booleanNode('defer_transport_until_commit')->defaultTrue()->end()
            ->booleanNode('fail_on_transport_error')->defaultFalse()->end()
            ->booleanNode('fallback_to_database')->defaultTrue()->end()
            ->scalarNode('cache_pool')->defaultNull()->end()
            ->scalarNode('admin_permission')
            ->defaultNull()
            ->setDeprecated(
                'rcsofttech/audit-trail-bundle',
                '4.1',
                'Configuring "audit_trail.%node%" is deprecated since rcsofttech/audit-trail-bundle 4.1; use "audit_trail.easyadmin.permission" instead.'
            )
            ->cannotBeEmpty()
            ->info('Deprecated: use easyadmin.permission instead.')
            ->end()
            ->integerNode('admin_export_limit')
            ->defaultNull()
            ->min(1)
            ->setDeprecated(
                'rcsofttech/audit-trail-bundle',
                '4.1',
                'Configuring "audit_trail.%node%" is deprecated since rcsofttech/audit-trail-bundle 4.1; use "audit_trail.easyadmin.export_limit" instead.'
            )
            ->info('Deprecated: use easyadmin.export_limit instead.')
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
            ->arrayNode('queue_limits')
            ->addDefaultsIfNotSet()
            ->info('Limits in-memory audit queues so long-running processes do not retain failed work indefinitely.')
            ->children()
            ->integerNode('scheduled_audits')
            ->defaultValue(1000)
            ->min(1)
            ->info('Maximum number of scheduled audits retained in memory before the manager throws an overflow exception.')
            ->end()
            ->integerNode('pending_audit_plans')
            ->defaultValue(1000)
            ->min(1)
            ->info('Maximum number of deferred audit plans retained in memory before the manager throws an overflow exception.')
            ->end()
            ->integerNode('pending_deletions')
            ->defaultValue(1000)
            ->min(1)
            ->info('Maximum number of pending deletions retained in memory before the manager throws an overflow exception.')
            ->end()
            ->end()
            ->end()
            ->end();
    }

    /**
     * @param array<string, mixed> $value
     *
     * @return array<string, mixed>
     */
    private function normalizeEasyAdminConfig(array $value): array
    {
        if (!isset($value['easyadmin']) || !is_array($value['easyadmin'])) {
            $value['easyadmin'] = [];
        }

        if (array_key_exists('admin_permission', $value) && !array_key_exists('permission', $value['easyadmin'])) {
            $value['easyadmin']['permission'] = $value['admin_permission'];
        }

        if (array_key_exists('admin_export_limit', $value) && !array_key_exists('export_limit', $value['easyadmin'])) {
            $value['easyadmin']['export_limit'] = $value['admin_export_limit'];
        }

        return $value;
    }

    private function configureEasyAdmin(ArrayNodeDefinition $rootNode): void
    {
        $rootNode
            ->children()
            ->arrayNode('easyadmin')
            ->addDefaultsIfNotSet()
            ->children()
            ->scalarNode('permission')
            ->defaultValue('ROLE_ADMIN')
            ->cannotBeEmpty()
            ->info('Required Symfony permission/role for EasyAdmin audit UI actions such as export and revert.')
            ->end()
            ->integerNode('export_limit')
            ->defaultValue(50000)
            ->min(1)
            ->info('Maximum number of audit rows the EasyAdmin export endpoints will stream in a single HTTP response.')
            ->end()
            ->end()
            ->end()
            ->end();
    }

    /**
     * @param array<string, mixed> $value
     */
    private function usesRemoteTransport(array $value): bool
    {
        $transports = $value['transports'] ?? null;
        if (!is_array($transports)) {
            return false;
        }

        return $this->isTransportEnabled($transports['http'] ?? null)
            || $this->isTransportEnabled($transports['queue'] ?? null);
    }

    private function isTransportEnabled(mixed $transport): bool
    {
        if (is_bool($transport)) {
            return $transport;
        }

        return is_array($transport) && ($transport['enabled'] ?? false) === true;
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
            ->stringNode('secret')
            ->info('Runtime env placeholder used for HMAC signature, e.g. "%env(string:AUDIT_INTEGRITY_SECRET)%". Required if integrity is enabled.')
            ->defaultNull()
            ->validate()
            ->ifTrue(
                static fn (mixed $value): bool => is_string($value)
                    && $value !== ''
                    && !str_starts_with($value, '%env(')
                    && mb_strlen($value) < self::MIN_INTEGRITY_SECRET_LENGTH
            )
            ->thenInvalid(sprintf(
                'The integrity secret must be at least %d characters long.',
                self::MIN_INTEGRITY_SECRET_LENGTH,
            ))
            ->end()
            ->validate()
            ->ifTrue(
                static fn (mixed $value): bool => is_string($value)
                    && $value !== ''
                    && (!str_starts_with($value, '%env(') || !str_ends_with($value, ')%'))
            )
            ->thenInvalid('The integrity secret must be configured as an env placeholder like "%env(string:AUDIT_INTEGRITY_SECRET)%".')
            ->end()
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

    private function isValidTableNameFragment(string $value): bool
    {
        if ($value === '') {
            return true;
        }

        return preg_match(self::TABLE_NAME_FRAGMENT_PATTERN, $value) === 1;
    }
}
