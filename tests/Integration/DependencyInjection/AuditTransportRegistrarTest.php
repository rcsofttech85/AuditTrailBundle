<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Tests\Integration\DependencyInjection;

use LogicException;
use PHPUnit\Framework\TestCase;
use Rcsofttech\AuditTrailBundle\Contract\AuditTransportInterface;
use Rcsofttech\AuditTrailBundle\DependencyInjection\AuditTransportRegistrar;
use Rcsofttech\AuditTrailBundle\Factory\AuditLogMessageFactory;
use Rcsofttech\AuditTrailBundle\MessageHandler\PersistAuditLogHandler;
use Rcsofttech\AuditTrailBundle\Serializer\AuditLogMessageSerializer;
use Rcsofttech\AuditTrailBundle\Transport\ChainAuditTransport;
use Rcsofttech\AuditTrailBundle\Transport\DoctrineAuditTransport;
use Rcsofttech\AuditTrailBundle\Transport\NullAuditTransport;
use Rcsofttech\AuditTrailBundle\Transport\QueueAuditTransport;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;

final class AuditTransportRegistrarTest extends TestCase
{
    public function testRegisterUsesNullTransportWhenBundleIsDisabledWithoutTransports(): void
    {
        $container = new ContainerBuilder();

        new AuditTransportRegistrar()->register($container, $this->config(enabled: false));

        self::assertSame('rcsofttech_audit_trail.transport.null', (string) $container->getAlias(AuditTransportInterface::class));
        self::assertTrue($container->hasDefinition('rcsofttech_audit_trail.transport.null'));
        self::assertSame(NullAuditTransport::class, $container->getDefinition('rcsofttech_audit_trail.transport.null')->getClass());
    }

    public function testRegisterThrowsWhenEnabledWithoutAnyTransports(): void
    {
        $container = new ContainerBuilder();

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('At least one audit transport must be enabled when the bundle is enabled.');

        new AuditTransportRegistrar()->register($container, $this->config(enabled: true));
    }

    public function testRegisterUsesSingleDatabaseTransportAlias(): void
    {
        $container = new ContainerBuilder();

        new AuditTransportRegistrar()->register($container, $this->config(databaseEnabled: true));

        self::assertSame('rcsofttech_audit_trail.transport.database', (string) $container->getAlias(AuditTransportInterface::class));
        self::assertSame(DoctrineAuditTransport::class, $container->getDefinition('rcsofttech_audit_trail.transport.database')->getClass());
    }

    public function testRegisterBuildsChainTransportForMultipleEnabledTransports(): void
    {
        $container = new ContainerBuilder();

        new AuditTransportRegistrar()->register($container, $this->config(
            databaseEnabled: true,
            queueEnabled: true,
            queueBus: 'messenger.bus.audit',
        ));

        self::assertSame('rcsofttech_audit_trail.transport.chain', (string) $container->getAlias(AuditTransportInterface::class));
        self::assertSame(ChainAuditTransport::class, $container->getDefinition('rcsofttech_audit_trail.transport.chain')->getClass());

        $queueDefinition = $container->getDefinition('rcsofttech_audit_trail.transport.queue');
        self::assertSame(QueueAuditTransport::class, $queueDefinition->getClass());
        self::assertEquals(new Reference('messenger.bus.audit'), $queueDefinition->getArgument('$bus'));
    }

    public function testRegisterAsyncDatabaseTransportAddsHandlerAndMessengerSupport(): void
    {
        $container = new ContainerBuilder();

        new AuditTransportRegistrar()->register($container, $this->config(databaseEnabled: true, databaseAsync: true));

        self::assertTrue($container->hasDefinition('rcsofttech_audit_trail.handler.persist_audit_log'));
        self::assertSame(PersistAuditLogHandler::class, $container->getDefinition('rcsofttech_audit_trail.handler.persist_audit_log')->getClass());
        self::assertTrue($container->hasDefinition(AuditLogMessageFactory::class));
        self::assertTrue($container->hasDefinition(AuditLogMessageSerializer::class));
    }

    /**
     * @return array{
     *   enabled: bool,
     *   transports: array{
     *     database: array{enabled: bool, async: bool},
     *     http: array{enabled: bool, endpoint: string, headers: array<string, string>, timeout: int},
     *     queue: array{enabled: bool, api_key: ?string, bus: ?string}
     *   }
     * }
     */
    private function config(
        bool $enabled = true,
        bool $databaseEnabled = false,
        bool $databaseAsync = false,
        bool $queueEnabled = false,
        ?string $queueBus = null,
    ): array {
        return [
            'enabled' => $enabled,
            'transports' => [
                'database' => ['enabled' => $databaseEnabled, 'async' => $databaseAsync],
                'http' => ['enabled' => false, 'endpoint' => 'http://example.com', 'headers' => [], 'timeout' => 10],
                'queue' => ['enabled' => $queueEnabled, 'api_key' => null, 'bus' => $queueBus],
            ],
        ];
    }
}
