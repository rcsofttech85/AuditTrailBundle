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

        $loader = new YamlFileLoader($container, new FileLocator(__DIR__.'/../Resources/config'));
        $loader->load('services.yaml');

        $this->configureTransports($config, $container);
    }

    /**
     * @param array<string, mixed> $config
     */
    private function configureTransports(array $config, ContainerBuilder $container): void
    {
        $transports = [];

        // Doctrine Transport
        if ($config['transports']['doctrine']) {
            $id = 'rcsofttech_audit_trail.transport.doctrine';
            $container->register($id, DoctrineAuditTransport::class)
                ->setAutowired(true);
            $transports[] = $id;
        }

        // HTTP Transport
        if ($config['transports']['http']['enabled']) {
            if (!interface_exists(HttpClientInterface::class)) {
                throw new \LogicException('To use the HTTP transport, you must install the symfony/http-client package.');
            }

            $id = 'rcsofttech_audit_trail.transport.http';
            $container->register($id, HttpAuditTransport::class)
                ->setAutowired(true)
                ->setArgument('$endpoint', $config['transports']['http']['endpoint']);
            $transports[] = $id;
        }

        // Queue Transport
        if ($config['transports']['queue']['enabled']) {
            if (!interface_exists(MessageBusInterface::class)) {
                throw new \LogicException('To use the Queue transport, you must install the symfony/messenger package.');
            }

            $id = 'rcsofttech_audit_trail.transport.queue';
            $definition = $container->register($id, QueueAuditTransport::class)
                ->setAutowired(true);

            if ($config['transports']['queue']['bus']) {
                $definition->setArgument('$bus', new Reference($config['transports']['queue']['bus']));
            }

            $transports[] = $id;
        }

        // Alias AuditTransportInterface
        if (1 === count($transports)) {
            $container->setAlias(AuditTransportInterface::class, $transports[0]);
        } elseif (count($transports) > 1) {
            $chainTransportId = 'rcsofttech_audit_trail.transport.chain';
            $transportReferences = array_map(fn ($id) => new Reference($id), $transports);

            $container->register($chainTransportId, ChainAuditTransport::class)
                ->setArgument('$transports', $transportReferences);

            $container->setAlias(AuditTransportInterface::class, $chainTransportId);
        }
    }

    public function getAlias(): string
    {
        return 'audit_trail';
    }
}
