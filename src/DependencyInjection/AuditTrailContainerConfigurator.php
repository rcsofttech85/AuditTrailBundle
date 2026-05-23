<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\DependencyInjection;

use EasyCorp\Bundle\EasyAdminBundle\EasyAdminBundle;
use LogicException;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\DependencyInjection\ContainerBuilder;

use function array_is_list;
use function array_key_exists;
use function class_exists;
use function get_debug_type;
use function is_array;
use function is_bool;
use function is_int;
use function is_string;
use function sprintf;
use function str_starts_with;

/**
 * @phpstan-type EasyAdminConfig array{permission: string, export_limit: int}
 * @phpstan-type QueueLimitsConfig array{scheduled_audits: int, pending_audit_plans: int, pending_deletions: int}
 * @phpstan-type IntegrityConfig array{enabled: bool, secret: ?string, algorithm: string}
 * @phpstan-type DatabaseTransportConfig array{enabled: bool, async: bool}
 * @phpstan-type HttpTransportConfig array{enabled: bool, endpoint: string, headers: array<string, string>, timeout: int}
 * @phpstan-type QueueTransportConfig array{enabled: bool, api_key: ?string, bus: ?string}
 * @phpstan-type TransportsConfig array{
 *   database: DatabaseTransportConfig,
 *   http: HttpTransportConfig,
 *   queue: QueueTransportConfig
 * }
 *
 * @internal
 */
final class AuditTrailContainerConfigurator
{
    public function __construct(
        private readonly AuditParameterRegistrar $parameterRegistrar = new AuditParameterRegistrar(),
        private readonly AuditIntegrityConfigurator $integrityConfigurator = new AuditIntegrityConfigurator(),
        private readonly AuditProfilerRegistrar $profilerRegistrar = new AuditProfilerRegistrar(),
        private readonly AuditTransportRegistrar $transportRegistrar = new AuditTransportRegistrar(),
    ) {
    }

    /**
     * @param array<string, mixed>   $config
     * @param callable(string): void $loadConfigFile
     */
    public function load(array $config, ContainerBuilder $container, callable $loadConfigFile): void
    {
        $enabled = $this->boolValue($config, 'enabled');
        $queueLimits = $this->queueLimitsConfig($config);
        $easyAdminConfig = $this->easyAdminConfig($config);
        $integrityConfig = $this->integrityConfig($config);
        $transportsConfig = $this->transportsConfig($config);

        $this->assertHttpTransportSecurity($container, $transportsConfig['http']);

        $this->parameterRegistrar->register($container, [
            'audit_trail.enabled' => $enabled,
            'audit_trail.ignored_properties' => $this->stringListValue($config, 'ignored_properties'),
            'audit_trail.table_prefix' => $this->stringValue($config, 'table_prefix'),
            'audit_trail.table_suffix' => $this->stringValue($config, 'table_suffix'),
            'audit_trail.timezone' => $this->stringValue($config, 'timezone'),
            'audit_trail.ignored_entities' => $this->stringListValue($config, 'ignored_entities'),
            'audit_trail.retention_days' => $this->intValue($config, 'retention_days'),
            'audit_trail.track_ip_address' => $this->boolValue($config, 'track_ip_address'),
            'audit_trail.track_user_agent' => $this->boolValue($config, 'track_user_agent'),
            'audit_trail.enable_soft_delete' => $this->boolValue($config, 'enable_soft_delete'),
            'audit_trail.soft_delete_field' => $this->stringValue($config, 'soft_delete_field'),
            'audit_trail.soft_delete_filter_names' => $this->stringListValue($config, 'soft_delete_filter_names'),
            'audit_trail.enable_hard_delete' => $this->boolValue($config, 'enable_hard_delete'),
            'audit_trail.defer_transport_until_commit' => $this->boolValue($config, 'defer_transport_until_commit'),
            'audit_trail.fail_on_transport_error' => $this->boolValue($config, 'fail_on_transport_error'),
            'audit_trail.fallback_to_database' => $this->boolValue($config, 'fallback_to_database'),
            'audit_trail.cache_pool' => $this->nullableStringValue($config, 'cache_pool'),
            'audit_trail.easyadmin.permission' => $easyAdminConfig['permission'],
            'audit_trail.easyadmin.export_limit' => $easyAdminConfig['export_limit'],
            'audit_trail.admin_permission' => $easyAdminConfig['permission'],
            'audit_trail.admin_export_limit' => $easyAdminConfig['export_limit'],
            'audit_trail.audited_methods' => $this->stringListValue($config, 'audited_methods'),
            'audit_trail.collection_serialization_mode' => $this->stringValue($config, 'collection_serialization_mode'),
            'audit_trail.max_collection_items' => $this->intValue($config, 'max_collection_items'),
            'audit_trail.queue_limits.scheduled_audits' => $queueLimits['scheduled_audits'],
            'audit_trail.queue_limits.pending_audit_plans' => $queueLimits['pending_audit_plans'],
            'audit_trail.queue_limits.pending_deletions' => $queueLimits['pending_deletions'],
        ]);

        $cachePool = $this->nullableStringValue($config, 'cache_pool');
        if ($cachePool !== null) {
            $container->setAlias('rcsofttech_audit_trail.cache', $cachePool);
        }

        $loadConfigFile('services.yaml');
        $this->integrityConfigurator->configure($container, $integrityConfig);
        $this->transportRegistrar->register($container, [
            'enabled' => $enabled,
            'transports' => $transportsConfig,
        ]);

        $bundles = $this->resolveRegisteredBundles($container);
        if ($this->isEasyAdminEnabled($bundles)) {
            $loadConfigFile('services_easyadmin.yaml');
        }

        $this->profilerRegistrar->register($container, $bundles);
    }

    public function prepend(ContainerBuilder $container, string $entityDirectory): void
    {
        if ($container->hasExtension('framework')) {
            $container->prependExtensionConfig('framework', [
                'uid' => [],
            ]);
        }

        if (!$container->hasExtension('doctrine')) {
            return;
        }

        $container->prependExtensionConfig('doctrine', [
            'orm' => [
                'mappings' => [
                    'RcsofttechAuditTrailBundle' => [
                        'is_bundle' => false,
                        'type' => 'attribute',
                        'dir' => $entityDirectory,
                        'prefix' => 'Rcsofttech\AuditTrailBundle\Entity',
                        'alias' => 'AuditTrail',
                    ],
                ],
            ],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function resolveRegisteredBundles(ContainerBuilder $container): array
    {
        if (!$container->hasParameter('kernel.bundles')) {
            return [];
        }

        $bundles = $container->getParameter('kernel.bundles');

        return is_array($bundles) ? $bundles : [];
    }

    /**
     * @param array<string, mixed> $bundles
     */
    private function isEasyAdminEnabled(array $bundles): bool
    {
        return isset($bundles['EasyAdminBundle'])
            && class_exists(EasyAdminBundle::class);
    }

    /**
     * @param HttpTransportConfig $config
     */
    private function assertHttpTransportSecurity(ContainerBuilder $container, array $config): void
    {
        $endpoint = $config['endpoint'];

        if (!$config['enabled'] || !str_starts_with($endpoint, 'http://')) {
            return;
        }

        $environment = 'prod';
        if ($container->hasParameter('kernel.environment')) {
            $parameter = $container->getParameter('kernel.environment');
            if (is_string($parameter) && $parameter !== '') {
                $environment = $parameter;
            }
        }

        if ($environment !== 'dev') {
            throw new InvalidConfigurationException(sprintf('Insecure audit HTTP endpoints are only allowed in the "dev" environment. Received "%s" for endpoint "%s". Use HTTPS instead.', $environment, $endpoint));
        }
    }

    /**
     * @param array<string, mixed> $config
     *
     * @return EasyAdminConfig
     */
    private function easyAdminConfig(array $config): array
    {
        $easyAdminConfig = $this->arrayValue($config, 'easyadmin');

        return [
            'permission' => $this->stringValue($easyAdminConfig, 'permission'),
            'export_limit' => $this->intValue($easyAdminConfig, 'export_limit'),
        ];
    }

    /**
     * @param array<string, mixed> $config
     *
     * @return QueueLimitsConfig
     */
    private function queueLimitsConfig(array $config): array
    {
        $queueLimitsConfig = $this->arrayValue($config, 'queue_limits');

        return [
            'scheduled_audits' => $this->intValue($queueLimitsConfig, 'scheduled_audits'),
            'pending_audit_plans' => $this->intValue($queueLimitsConfig, 'pending_audit_plans'),
            'pending_deletions' => $this->intValue($queueLimitsConfig, 'pending_deletions'),
        ];
    }

    /**
     * @param array<string, mixed> $config
     *
     * @return IntegrityConfig
     */
    private function integrityConfig(array $config): array
    {
        $integrityConfig = $this->arrayValue($config, 'integrity');

        return [
            'enabled' => $this->boolValue($integrityConfig, 'enabled'),
            'secret' => $this->nullableStringValue($integrityConfig, 'secret'),
            'algorithm' => $this->stringValue($integrityConfig, 'algorithm'),
        ];
    }

    /**
     * @param array<string, mixed> $config
     *
     * @return TransportsConfig
     */
    private function transportsConfig(array $config): array
    {
        $transportsConfig = $this->arrayValue($config, 'transports');

        return [
            'database' => $this->databaseTransportConfig($transportsConfig),
            'http' => $this->httpTransportConfig($transportsConfig),
            'queue' => $this->queueTransportConfig($transportsConfig),
        ];
    }

    /**
     * @param array<string, mixed> $config
     *
     * @return DatabaseTransportConfig
     */
    private function databaseTransportConfig(array $config): array
    {
        $databaseConfig = $this->arrayValue($config, 'database');

        return [
            'enabled' => $this->boolValue($databaseConfig, 'enabled'),
            'async' => $this->boolValue($databaseConfig, 'async'),
        ];
    }

    /**
     * @param array<string, mixed> $config
     *
     * @return HttpTransportConfig
     */
    private function httpTransportConfig(array $config): array
    {
        $httpConfig = $this->arrayValue($config, 'http');

        return [
            'enabled' => $this->boolValue($httpConfig, 'enabled'),
            'endpoint' => $this->nullableStringValue($httpConfig, 'endpoint') ?? '',
            'headers' => $this->stringMapValue($httpConfig, 'headers'),
            'timeout' => $this->intValue($httpConfig, 'timeout'),
        ];
    }

    /**
     * @param array<string, mixed> $config
     *
     * @return QueueTransportConfig
     */
    private function queueTransportConfig(array $config): array
    {
        $queueConfig = $this->arrayValue($config, 'queue');

        return [
            'enabled' => $this->boolValue($queueConfig, 'enabled'),
            'api_key' => $this->nullableStringValue($queueConfig, 'api_key'),
            'bus' => $this->nullableStringValue($queueConfig, 'bus'),
        ];
    }

    /**
     * @param array<string, mixed> $config
     *
     * @return array<mixed>
     */
    private function arrayValue(array $config, string $key): array
    {
        if (!array_key_exists($key, $config) || !is_array($config[$key])) {
            throw $this->unexpectedConfigValue($key, 'array', $config[$key] ?? null);
        }

        return $config[$key];
    }

    /**
     * @param array<string, mixed> $config
     *
     * @return list<string>
     */
    private function stringListValue(array $config, string $key): array
    {
        $value = $this->arrayValue($config, $key);
        if (!array_is_list($value)) {
            throw $this->unexpectedConfigValue($key, 'list<string>', $value);
        }

        foreach ($value as $item) {
            if (!is_string($item)) {
                throw $this->unexpectedConfigValue($key, 'list<string>', $value);
            }
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $config
     *
     * @return array<string, string>
     */
    private function stringMapValue(array $config, string $key): array
    {
        $value = $this->arrayValue($config, $key);

        foreach ($value as $mapKey => $mapValue) {
            if (!is_string($mapKey) || !is_string($mapValue)) {
                throw $this->unexpectedConfigValue($key, 'array<string, string>', $value);
            }
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $config
     */
    private function stringValue(array $config, string $key): string
    {
        if (!array_key_exists($key, $config) || !is_string($config[$key])) {
            throw $this->unexpectedConfigValue($key, 'string', $config[$key] ?? null);
        }

        return $config[$key];
    }

    /**
     * @param array<string, mixed> $config
     */
    private function nullableStringValue(array $config, string $key): ?string
    {
        if (!array_key_exists($key, $config)) {
            throw $this->unexpectedConfigValue($key, '?string', null);
        }

        $value = $config[$key];
        if ($value !== null && !is_string($value)) {
            throw $this->unexpectedConfigValue($key, '?string', $value);
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $config
     */
    private function intValue(array $config, string $key): int
    {
        if (!array_key_exists($key, $config) || !is_int($config[$key])) {
            throw $this->unexpectedConfigValue($key, 'int', $config[$key] ?? null);
        }

        return $config[$key];
    }

    /**
     * @param array<string, mixed> $config
     */
    private function boolValue(array $config, string $key): bool
    {
        if (!array_key_exists($key, $config) || !is_bool($config[$key])) {
            throw $this->unexpectedConfigValue($key, 'bool', $config[$key] ?? null);
        }

        return $config[$key];
    }

    private function unexpectedConfigValue(string $key, string $expectedType, mixed $value): LogicException
    {
        return new LogicException(sprintf(
            'Expected "%s" to be %s, got %s.',
            $key,
            $expectedType,
            get_debug_type($value),
        ));
    }
}
