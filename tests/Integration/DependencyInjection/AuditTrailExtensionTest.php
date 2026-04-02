<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Tests\Integration\DependencyInjection;

use LogicException;
use PHPUnit\Framework\TestCase;
use Rcsofttech\AuditTrailBundle\Contract\AuditTransportInterface;
use Rcsofttech\AuditTrailBundle\DependencyInjection\AuditTrailExtension;
use Rcsofttech\AuditTrailBundle\Transport\NullAuditTransport;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class AuditTrailExtensionTest extends TestCase
{
    public function testDefaultConfigurationLoadsDoctrineTransport(): void
    {
        $container = new ContainerBuilder();
        $extension = new AuditTrailExtension();

        $config = [];
        $extension->load($config, $container);

        self::assertTrue($container->hasDefinition('rcsofttech_audit_trail.transport.database'));
        self::assertTrue($container->hasAlias(AuditTransportInterface::class));
        self::assertSame(
            'rcsofttech_audit_trail.transport.database',
            (string) $container->getAlias(AuditTransportInterface::class)
        );
        self::assertSame('ROLE_ADMIN', $container->getParameter('audit_trail.admin_permission'));
    }

    public function testCustomAdminPermissionIsStored(): void
    {
        $container = new ContainerBuilder();
        $extension = new AuditTrailExtension();

        $extension->load([[
            'admin_permission' => 'ROLE_AUDIT_ADMIN',
        ]], $container);

        self::assertSame('ROLE_AUDIT_ADMIN', $container->getParameter('audit_trail.admin_permission'));
    }

    public function testHttpTransportConfiguration(): void
    {
        if (!interface_exists(HttpClientInterface::class)) {
            self::markTestSkipped('HttpClient is not installed.');
        }

        $container = new ContainerBuilder();
        $extension = new AuditTrailExtension();

        $config = [
            'audit_trail' => [
                'transports' => [
                    'database' => ['enabled' => false],
                    'http' => [
                        'enabled' => true,
                        'endpoint' => 'http://example.com',
                    ],
                ],
            ],
        ];

        $extension->load($config, $container);

        self::assertTrue($container->hasDefinition('rcsofttech_audit_trail.transport.http'));
        self::assertSame(
            'rcsofttech_audit_trail.transport.http',
            (string) $container->getAlias(AuditTransportInterface::class)
        );
    }

    public function testQueueTransportConfiguration(): void
    {
        if (!interface_exists(MessageBusInterface::class)) {
            self::markTestSkipped('Messenger is not installed.');
        }

        $container = new ContainerBuilder();
        $extension = new AuditTrailExtension();

        $config = [
            'audit_trail' => [
                'transports' => [
                    'database' => ['enabled' => false],
                    'queue' => [
                        'enabled' => true,
                    ],
                ],
            ],
        ];

        $extension->load($config, $container);

        self::assertTrue($container->hasDefinition('rcsofttech_audit_trail.transport.queue'));
        self::assertSame(
            'rcsofttech_audit_trail.transport.queue',
            (string) $container->getAlias(AuditTransportInterface::class)
        );
    }

    public function testChainTransportConfiguration(): void
    {
        if (!interface_exists(HttpClientInterface::class)) {
            self::markTestSkipped('HttpClient is not installed.');
        }

        $container = new ContainerBuilder();
        $extension = new AuditTrailExtension();

        $config = [
            'audit_trail' => [
                'transports' => [
                    'database' => ['enabled' => true],
                    'http' => [
                        'enabled' => true,
                        'endpoint' => 'http://example.com',
                    ],
                ],
            ],
        ];

        $extension->load($config, $container);

        self::assertTrue($container->hasDefinition('rcsofttech_audit_trail.transport.chain'));
        self::assertSame(
            'rcsofttech_audit_trail.transport.chain',
            (string) $container->getAlias(AuditTransportInterface::class)
        );
    }

    public function testTablePrefixSubscriberRegistration(): void
    {
        $container = new ContainerBuilder();
        $extension = new AuditTrailExtension();

        $config = [];
        $extension->load($config, $container);

        self::assertTrue($container->hasDefinition(
            'Rcsofttech\AuditTrailBundle\EventSubscriber\TablePrefixSubscriber'
        ));
        $definition = $container->getDefinition('Rcsofttech\AuditTrailBundle\EventSubscriber\TablePrefixSubscriber');
        self::assertTrue($definition->isAutoconfigured());
    }

    public function testDisabledHttpTransportDoesNotRequireEndpoint(): void
    {
        $container = new ContainerBuilder();
        $extension = new AuditTrailExtension();

        $config = [
            'transports' => [
                'database' => ['enabled' => true],
                'http' => ['enabled' => false],
            ],
        ];

        $extension->load([$config], $container);

        self::assertTrue($container->hasDefinition('rcsofttech_audit_trail.transport.database'));
        self::assertFalse($container->hasDefinition('rcsofttech_audit_trail.transport.http'));
        self::assertSame(
            'rcsofttech_audit_trail.transport.database',
            (string) $container->getAlias(AuditTransportInterface::class)
        );
    }

    public function testEnabledBundleRequiresAtLeastOneTransport(): void
    {
        $container = new ContainerBuilder();
        $extension = new AuditTrailExtension();

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('At least one audit transport must be enabled');

        $extension->load([[
            'transports' => [
                'database' => ['enabled' => false],
                'http' => ['enabled' => false],
                'queue' => ['enabled' => false],
            ],
        ]], $container);
    }

    public function testDisabledBundleCanUseNullTransportWhenAllTransportsAreDisabled(): void
    {
        $container = new ContainerBuilder();
        $extension = new AuditTrailExtension();

        $extension->load([[
            'enabled' => false,
            'transports' => [
                'database' => ['enabled' => false],
                'http' => ['enabled' => false],
                'queue' => ['enabled' => false],
            ],
        ]], $container);

        self::assertTrue($container->hasDefinition('rcsofttech_audit_trail.transport.null'));
        self::assertTrue($container->hasAlias(AuditTransportInterface::class));
        self::assertSame(
            'rcsofttech_audit_trail.transport.null',
            (string) $container->getAlias(AuditTransportInterface::class)
        );
        self::assertSame(NullAuditTransport::class, $container->getDefinition('rcsofttech_audit_trail.transport.null')->getClass());
    }
}
