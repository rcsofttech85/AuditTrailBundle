<?php

namespace Rcsofttech\AuditTrailBundle\Tests\Integration\DependencyInjection;

use PHPUnit\Framework\TestCase;
use Rcsofttech\AuditTrailBundle\Contract\AuditTransportInterface;
use Rcsofttech\AuditTrailBundle\DependencyInjection\AuditTrailExtension;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class AuditTrailExtensionTest extends TestCase
{
    public function testDefaultConfigurationLoadsDoctrineTransport(): void
    {
        $container = new ContainerBuilder();
        $extension = new AuditTrailExtension();

        $config = [];
        $extension->load($config, $container);

        $this->assertTrue($container->hasDefinition('rcsofttech_audit_trail.transport.doctrine'));
        $this->assertTrue($container->hasAlias(AuditTransportInterface::class));
        $this->assertEquals('rcsofttech_audit_trail.transport.doctrine', (string) $container->getAlias(AuditTransportInterface::class));
    }

    public function testHttpTransportConfiguration(): void
    {
        if (!interface_exists(HttpClientInterface::class)) {
            $this->markTestSkipped('HttpClient is not installed.');
        }

        $container = new ContainerBuilder();
        $extension = new AuditTrailExtension();

        $config = [
            'audit_trail' => [
                'transports' => [
                    'doctrine' => false,
                    'http' => [
                        'enabled' => true,
                        'endpoint' => 'http://example.com',
                    ],
                ],
            ],
        ];

        $extension->load($config, $container);

        $this->assertTrue($container->hasDefinition('rcsofttech_audit_trail.transport.http'));
        $this->assertEquals('rcsofttech_audit_trail.transport.http', (string) $container->getAlias(AuditTransportInterface::class));
    }

    public function testQueueTransportConfiguration(): void
    {
        if (!interface_exists(MessageBusInterface::class)) {
            $this->markTestSkipped('Messenger is not installed.');
        }

        $container = new ContainerBuilder();
        $extension = new AuditTrailExtension();

        $config = [
            'audit_trail' => [
                'transports' => [
                    'doctrine' => false,
                    'queue' => [
                        'enabled' => true,
                    ],
                ],
            ],
        ];

        $extension->load($config, $container);

        $this->assertTrue($container->hasDefinition('rcsofttech_audit_trail.transport.queue'));
        $this->assertEquals('rcsofttech_audit_trail.transport.queue', (string) $container->getAlias(AuditTransportInterface::class));
    }

    public function testChainTransportConfiguration(): void
    {
        if (!interface_exists(HttpClientInterface::class)) {
            $this->markTestSkipped('HttpClient is not installed.');
        }

        $container = new ContainerBuilder();
        $extension = new AuditTrailExtension();

        $config = [
            'audit_trail' => [
                'transports' => [
                    'doctrine' => true,
                    'http' => [
                        'enabled' => true,
                        'endpoint' => 'http://example.com',
                    ],
                ],
            ],
        ];

        $extension->load($config, $container);

        $this->assertTrue($container->hasDefinition('rcsofttech_audit_trail.transport.chain'));
        $this->assertEquals('rcsofttech_audit_trail.transport.chain', (string) $container->getAlias(AuditTransportInterface::class));
    }
}
