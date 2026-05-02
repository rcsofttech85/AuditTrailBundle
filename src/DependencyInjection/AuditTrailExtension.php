<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\DependencyInjection;

use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use Override;
use Rcsofttech\AuditTrailBundle\Controller\Admin\AuditLogCrudController;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Extension\PrependExtensionInterface;
use Symfony\Component\DependencyInjection\Loader\YamlFileLoader;

use function class_exists;
use function is_string;
use function sprintf;

final class AuditTrailExtension extends Extension implements PrependExtensionInterface
{
    private readonly AuditParameterRegistrar $parameterRegistrar;

    private readonly AuditIntegrityConfigurator $integrityConfigurator;

    private readonly AuditProfilerRegistrar $profilerRegistrar;

    private readonly AuditTransportRegistrar $transportRegistrar;

    public function __construct(
        ?AuditParameterRegistrar $parameterRegistrar = null,
        ?AuditIntegrityConfigurator $integrityConfigurator = null,
        ?AuditProfilerRegistrar $profilerRegistrar = null,
        ?AuditTransportRegistrar $transportRegistrar = null,
    ) {
        $this->parameterRegistrar = $parameterRegistrar ?? new AuditParameterRegistrar();
        $this->integrityConfigurator = $integrityConfigurator ?? new AuditIntegrityConfigurator();
        $this->profilerRegistrar = $profilerRegistrar ?? new AuditProfilerRegistrar();
        $this->transportRegistrar = $transportRegistrar ?? new AuditTransportRegistrar();
    }

    #[Override]
    public function load(array $configs, ContainerBuilder $container): void
    {
        $configuration = new Configuration();
        /** @var array{
         *   enabled: bool,
         *   ignored_properties: array<string>,
         *   table_prefix: string,
         *   table_suffix: string,
         *   timezone: string,
         *   ignored_entities: array<string>,
         *   retention_days: int,
         *   track_ip_address: bool,
         *   track_user_agent: bool,
         *   enable_soft_delete: bool,
         *   soft_delete_field: string,
         *   soft_delete_filter_names: array<string>,
         *   enable_hard_delete: bool,
         *   defer_transport_until_commit: bool,
         *   fail_on_transport_error: bool,
         *   fallback_to_database: bool,
         *   integrity: array{enabled: bool, secret: ?string, algorithm: string},
         *   cache_pool: ?string,
         *   admin_permission: string,
         *   audited_methods: array<string>,
         *   collection_serialization_mode: string,
         *   max_collection_items: int,
         *   transports: array{
         *     database: array{enabled: bool, async: bool},
         *     http: array{enabled: bool, endpoint: string, headers: array<string, string>, timeout: int},
         *     queue: array{enabled: bool, api_key: ?string, bus: ?string}
         *   }
         * } $config */
        $config = $this->processConfiguration($configuration, $configs);
        $this->assertHttpTransportSecurity($container, $config);

        $this->parameterRegistrar->register($container, [
            'audit_trail.enabled' => $config['enabled'],
            'audit_trail.ignored_properties' => $config['ignored_properties'],
            'audit_trail.table_prefix' => $config['table_prefix'],
            'audit_trail.table_suffix' => $config['table_suffix'],
            'audit_trail.timezone' => $config['timezone'],
            'audit_trail.ignored_entities' => $config['ignored_entities'],
            'audit_trail.retention_days' => $config['retention_days'],
            'audit_trail.track_ip_address' => $config['track_ip_address'],
            'audit_trail.track_user_agent' => $config['track_user_agent'],
            'audit_trail.enable_soft_delete' => $config['enable_soft_delete'],
            'audit_trail.soft_delete_field' => $config['soft_delete_field'],
            'audit_trail.soft_delete_filter_names' => $config['soft_delete_filter_names'],
            'audit_trail.enable_hard_delete' => $config['enable_hard_delete'],
            'audit_trail.defer_transport_until_commit' => $config['defer_transport_until_commit'],
            'audit_trail.fail_on_transport_error' => $config['fail_on_transport_error'],
            'audit_trail.fallback_to_database' => $config['fallback_to_database'],
            'audit_trail.cache_pool' => $config['cache_pool'],
            'audit_trail.admin_permission' => $config['admin_permission'],
            'audit_trail.audited_methods' => $config['audited_methods'],
            'audit_trail.collection_serialization_mode' => $config['collection_serialization_mode'],
            'audit_trail.max_collection_items' => $config['max_collection_items'],
        ]);

        if ($config['cache_pool'] !== null) {
            $container->setAlias('rcsofttech_audit_trail.cache', $config['cache_pool']);
        }
        new YamlFileLoader($container, new FileLocator(__DIR__.'/../Resources/config'))->load('services.yaml');
        $this->integrityConfigurator->configure($container, $config['integrity']);

        $this->transportRegistrar->register($container, $config);

        /** @var array<string, mixed> $bundles */
        $bundles = $container->hasParameter('kernel.bundles') ? $container->getParameter('kernel.bundles') : [];
        if (isset($bundles['EasyAdminBundle']) && class_exists(AbstractCrudController::class)) {
            $container->register(AuditLogCrudController::class, AuditLogCrudController::class)
                ->setAutowired(true)
                ->setAutoconfigured(true)
                ->setArgument('$adminPermission', '%audit_trail.admin_permission%')
                ->addTag('controller.service_arguments');
        }

        $this->profilerRegistrar->register($container, $bundles);
    }

    #[Override]
    public function prepend(ContainerBuilder $container): void
    {
        if (!$container->hasExtension('doctrine')) {
            return;
        }

        $container->prependExtensionConfig('doctrine', [
            'orm' => [
                'mappings' => [
                    'RcsofttechAuditTrailBundle' => [
                        'is_bundle' => false,
                        'type' => 'attribute',
                        'dir' => __DIR__.'/../Entity',
                        'prefix' => 'Rcsofttech\AuditTrailBundle\Entity',
                        'alias' => 'AuditTrail',
                    ],
                ],
            ],
        ]);
    }

    #[Override]
    public function getAlias(): string
    {
        return 'audit_trail';
    }

    /**
     * @param array{
     *   transports: array{
     *     http: array{enabled: bool, endpoint: string|null}
     *   }
     * } $config
     */
    private function assertHttpTransportSecurity(ContainerBuilder $container, array $config): void
    {
        $httpConfig = $config['transports']['http'];
        $endpoint = $httpConfig['endpoint'];

        if (!$httpConfig['enabled'] || !is_string($endpoint) || !str_starts_with($endpoint, 'http://')) {
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
}
