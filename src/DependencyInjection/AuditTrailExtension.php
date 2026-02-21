<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\DependencyInjection;

use LogicException;
use Override;
use Rcsofttech\AuditTrailBundle\Contract\AuditTransportInterface;
use Rcsofttech\AuditTrailBundle\Transport\ChainAuditTransport;
use Rcsofttech\AuditTrailBundle\Transport\DoctrineAuditTransport;
use Rcsofttech\AuditTrailBundle\Transport\HttpAuditTransport;
use Rcsofttech\AuditTrailBundle\Transport\QueueAuditTransport;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Extension\PrependExtensionInterface;
use Symfony\Component\DependencyInjection\Loader\YamlFileLoader;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

use function count;

final class AuditTrailExtension extends Extension implements PrependExtensionInterface
{
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
         *   enable_hard_delete: bool,
         *   defer_transport_until_commit: bool,
         *   fail_on_transport_error: bool,
         *   fallback_to_database: bool,
         *   integrity: array{enabled: bool, secret: ?string, algorithm: string},
         *   cache_pool: ?string,
         *   audited_methods: array<string>,
         *   transports: array{
         *     doctrine: bool,
         *     http: array{enabled: bool, endpoint: string, headers: array<string, string>, timeout: int},
         *     queue: array{enabled: bool, api_key: ?string, bus: ?string}
         *   }
         * } $config */
        $config = $this->processConfiguration($configuration, $configs);

        $container->setParameter('audit_trail.enabled', $config['enabled']);
        $container->setParameter('audit_trail.ignored_properties', $config['ignored_properties']);
        $container->setParameter('audit_trail.table_prefix', $config['table_prefix']);
        $container->setParameter('audit_trail.table_suffix', $config['table_suffix']);
        $container->setParameter('audit_trail.timezone', $config['timezone']);
        $container->setParameter('audit_trail.ignored_entities', $config['ignored_entities']);
        $container->setParameter('audit_trail.retention_days', $config['retention_days']);
        $container->setParameter('audit_trail.track_ip_address', $config['track_ip_address']);
        $container->setParameter('audit_trail.track_user_agent', $config['track_user_agent']);
        $container->setParameter('audit_trail.enable_soft_delete', $config['enable_soft_delete']);
        $container->setParameter('audit_trail.soft_delete_field', $config['soft_delete_field']);
        $container->setParameter('audit_trail.enable_hard_delete', $config['enable_hard_delete']);
        $container->setParameter('audit_trail.defer_transport_until_commit', $config['defer_transport_until_commit']);
        $container->setParameter('audit_trail.fail_on_transport_error', $config['fail_on_transport_error']);
        $container->setParameter('audit_trail.fallback_to_database', $config['fallback_to_database']);
        $container->setParameter('audit_trail.cache_pool', $config['cache_pool']);
        $container->setParameter('audit_trail.audited_methods', $config['audited_methods']);

        if ($config['cache_pool'] !== null) {
            $container->setAlias('rcsofttech_audit_trail.cache', $config['cache_pool']);
        }

        $container->setParameter('audit_trail.integrity.enabled', $config['integrity']['enabled']);
        $container->setParameter('audit_trail.integrity.secret', $config['integrity']['secret'] ?? '');
        $container->setParameter('audit_trail.integrity.algorithm', $config['integrity']['algorithm']);
        new YamlFileLoader($container, new FileLocator(__DIR__.'/../Resources/config'))->load('services.yaml');

        $this->configureTransports($config, $container);
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

    /**
     * @param array{
     *   transports: array{
     *     doctrine: bool,
     *     http: array{enabled: bool, endpoint: string, headers: array<string, string>, timeout: int},
     *     queue: array{enabled: bool, api_key: ?string, bus: ?string}
     *   }
     * } $config
     */
    private function configureTransports(array $config, ContainerBuilder $container): void
    {
        $transports = [];

        if ($config['transports']['doctrine'] === true) {
            $transports[] = $this->registerDoctrineTransport($container);
        }

        if ($config['transports']['http']['enabled'] === true) {
            $transports[] = $this->registerHttpTransport($container, $config['transports']['http']);
        }

        if ($config['transports']['queue']['enabled'] === true) {
            $transports[] = $this->registerQueueTransport($container, $config['transports']['queue']);
        }

        $this->registerMainTransport($container, $transports);
    }

    private function registerDoctrineTransport(ContainerBuilder $container): string
    {
        $id = 'rcsofttech_audit_trail.transport.doctrine';
        $container->register($id, DoctrineAuditTransport::class)
            ->setAutowired(true)
            ->addTag('audit_trail.transport');

        return $id;
    }

    /**
     * @param array{enabled: bool, endpoint: string, headers: array<string, string>, timeout: int} $config
     */
    private function registerHttpTransport(ContainerBuilder $container, array $config): string
    {
        if (!interface_exists(HttpClientInterface::class)) {
            throw new LogicException('To use the HTTP transport, you must install the symfony/http-client package.');
        }

        $id = 'rcsofttech_audit_trail.transport.http';
        $container->register($id, HttpAuditTransport::class)
            ->setAutowired(true)
            ->setArgument('$endpoint', $config['endpoint'])
            ->setArgument('$headers', $config['headers'])
            ->setArgument('$timeout', $config['timeout'])
            ->addTag('audit_trail.transport');

        return $id;
    }

    /**
     * @param array{enabled: bool, api_key: ?string, bus: ?string} $config
     */
    private function registerQueueTransport(ContainerBuilder $container, array $config): string
    {
        if (!interface_exists(MessageBusInterface::class)) {
            throw new LogicException('To use the Queue transport, you must install the symfony/messenger package.');
        }

        $id = 'rcsofttech_audit_trail.transport.queue';
        $definition = $container->register($id, QueueAuditTransport::class)
            ->setAutowired(true)
            ->setArgument('$apiKey', $config['api_key'])
            ->addTag('audit_trail.transport');

        if (isset($config['bus']) && $config['bus'] !== '') {
            $definition->setArgument('$bus', new Reference($config['bus']));
        }

        return $id;
    }

    /**
     * @param array<string> $transports
     */
    private function registerMainTransport(ContainerBuilder $container, array $transports): void
    {
        if (1 === count($transports)) {
            $container->setAlias(AuditTransportInterface::class, $transports[0])->setPublic(true);

            return;
        }

        if (count($transports) > 1) {
            $id = 'rcsofttech_audit_trail.transport.chain';
            $references = array_map(static fn ($id) => new Reference($id), $transports);

            $container->register($id, ChainAuditTransport::class)
                ->setArgument('$transports', $references);

            $container->setAlias(AuditTransportInterface::class, $id)->setPublic(true);
        }
    }

    #[Override]
    public function getAlias(): string
    {
        return 'audit_trail';
    }
}
