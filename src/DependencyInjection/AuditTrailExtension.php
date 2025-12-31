<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\DependencyInjection;

use Rcsofttech\AuditTrailBundle\Contract\AuditTransportInterface;
use Rcsofttech\AuditTrailBundle\Transport\ChainAuditTransport;
use Rcsofttech\AuditTrailBundle\Transport\DoctrineAuditTransport;
use Rcsofttech\AuditTrailBundle\Transport\HttpAuditTransport;
use Rcsofttech\AuditTrailBundle\Transport\QueueAuditTransport;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Loader\YamlFileLoader;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class AuditTrailExtension extends Extension
{
    public function load(array $configs, ContainerBuilder $container): void
    {
        $configuration = new Configuration();
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

        $loader = new YamlFileLoader($container, new FileLocator(__DIR__ . '/../Resources/config'));
        $loader->load('services.yaml');

        $this->configureTransports($config, $container);
    }

    /**
     * @param array<string, mixed> $config
     */
    /**
     * @param array<string, mixed> $config
     */
    private function configureTransports(array $config, ContainerBuilder $container): void
    {
        $transports = [];

        if (true === $config['transports']['doctrine']) {
            $transports[] = $this->registerDoctrineTransport($container);
        }

        if (true === $config['transports']['http']['enabled']) {
            $transports[] = $this->registerHttpTransport($container, $config['transports']['http']);
        }

        if (true === $config['transports']['queue']['enabled']) {
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
     * @param array<string, mixed> $config
     */
    private function registerHttpTransport(ContainerBuilder $container, array $config): string
    {
        if (!interface_exists(HttpClientInterface::class)) {
            throw new \LogicException('To use the HTTP transport, you must install the symfony/http-client package.');
        }

        $id = 'rcsofttech_audit_trail.transport.http';
        $container->register($id, HttpAuditTransport::class)
            ->setAutowired(true)
            ->setArgument('$endpoint', $config['endpoint'])
            ->addTag('audit_trail.transport');

        return $id;
    }

    /**
     * @param array<string, mixed> $config
     */
    private function registerQueueTransport(ContainerBuilder $container, array $config): string
    {
        if (!interface_exists(MessageBusInterface::class)) {
            throw new \LogicException('To use the Queue transport, you must install the symfony/messenger package.');
        }

        $id = 'rcsofttech_audit_trail.transport.queue';
        $definition = $container->register($id, QueueAuditTransport::class)
            ->setAutowired(true)
            ->addTag('audit_trail.transport');

        if (isset($config['bus']) && '' !== $config['bus']) {
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
            $container->setAlias(AuditTransportInterface::class, $transports[0]);

            return;
        }

        if (count($transports) > 1) {
            $id = 'rcsofttech_audit_trail.transport.chain';
            $references = array_map(fn ($id) => new Reference($id), $transports);

            $container->register($id, ChainAuditTransport::class)
                ->setArgument('$transports', $references);

            $container->setAlias(AuditTransportInterface::class, $id);
        }
    }

    public function getAlias(): string
    {
        return 'audit_trail';
    }
}
