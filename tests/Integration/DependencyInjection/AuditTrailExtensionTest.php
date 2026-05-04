<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Tests\Integration\DependencyInjection;

use LogicException;
use OverflowException;
use PHPUnit\Framework\TestCase;
use Rcsofttech\AuditTrailBundle\Contract\AuditTransportInterface;
use Rcsofttech\AuditTrailBundle\DependencyInjection\AuditTrailExtension;
use Rcsofttech\AuditTrailBundle\Entity\AuditLog;
use Rcsofttech\AuditTrailBundle\Enum\AuditAction;
use Rcsofttech\AuditTrailBundle\Service\ScheduledAuditManager;
use Rcsofttech\AuditTrailBundle\Transport\NullAuditTransport;
use stdClass;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\ExtensionInterface;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class AuditTrailExtensionTest extends TestCase
{
    private function buildScheduledAuditManagerFromContainer(ContainerBuilder $container): ScheduledAuditManager
    {
        $container->getDefinition(ScheduledAuditManager::class)->setPublic(true);
        $container->compile();

        /** @var ScheduledAuditManager $manager */
        $manager = $container->get(ScheduledAuditManager::class);

        return $manager;
    }

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
        self::assertSame(50000, $container->getParameter('audit_trail.admin_export_limit'));
        self::assertSame(1000, $container->getParameter('audit_trail.queue_limits.scheduled_audits'));
        self::assertSame(1000, $container->getParameter('audit_trail.queue_limits.pending_audit_plans'));
        self::assertSame(1000, $container->getParameter('audit_trail.queue_limits.pending_deletions'));
        self::assertFalse($container->hasParameter('audit_trail.integrity.secret'));
        self::assertFalse($container->hasDefinition('rcsofttech_audit_trail.handler.persist_audit_log'));

        if (interface_exists(MessageBusInterface::class)) {
            self::assertTrue($container->hasDefinition('Rcsofttech\\AuditTrailBundle\\Serializer\\AuditLogMessageSerializer'));
        } else {
            self::assertFalse($container->hasDefinition('Rcsofttech\\AuditTrailBundle\\Serializer\\AuditLogMessageSerializer'));
        }
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

    public function testCustomAdminExportLimitIsStored(): void
    {
        $container = new ContainerBuilder();
        $extension = new AuditTrailExtension();

        $extension->load([[
            'admin_export_limit' => 2500,
        ]], $container);

        self::assertSame(2500, $container->getParameter('audit_trail.admin_export_limit'));
    }

    public function testCustomQueueLimitsAreStored(): void
    {
        $container = new ContainerBuilder();
        $extension = new AuditTrailExtension();

        $extension->load([[
            'queue_limits' => [
                'scheduled_audits' => 250,
                'pending_audit_plans' => 300,
                'pending_deletions' => 150,
            ],
        ]], $container);

        self::assertSame(250, $container->getParameter('audit_trail.queue_limits.scheduled_audits'));
        self::assertSame(300, $container->getParameter('audit_trail.queue_limits.pending_audit_plans'));
        self::assertSame(150, $container->getParameter('audit_trail.queue_limits.pending_deletions'));
    }

    public function testScheduledAuditManagerReceivesDefaultQueueLimitFromContainer(): void
    {
        $container = new ContainerBuilder();
        $extension = new AuditTrailExtension();

        $extension->load([], $container);

        $manager = $this->buildScheduledAuditManagerFromContainer($container);
        $entity = new stdClass();
        $log = new AuditLog(stdClass::class, '1', AuditAction::Create);

        for ($i = 0; $i < 1000; ++$i) {
            $manager->schedule($entity, $log, true);
        }

        $this->expectException(OverflowException::class);
        $manager->schedule($entity, $log, true);
    }

    public function testScheduledAuditManagerReceivesCustomQueueLimitFromContainer(): void
    {
        $container = new ContainerBuilder();
        $extension = new AuditTrailExtension();

        $extension->load([[
            'queue_limits' => [
                'scheduled_audits' => 2,
                'pending_audit_plans' => 300,
                'pending_deletions' => 150,
            ],
        ]], $container);

        $manager = $this->buildScheduledAuditManagerFromContainer($container);
        $entity = new stdClass();
        $log = new AuditLog(stdClass::class, '1', AuditAction::Create);

        $manager->schedule($entity, $log, true);
        $manager->schedule($entity, $log, true);

        $this->expectException(OverflowException::class);
        $manager->schedule($entity, $log, true);
    }

    public function testEasyAdminControllerIsNotRegisteredWhenBundleIsMissing(): void
    {
        $container = new ContainerBuilder();
        $extension = new AuditTrailExtension();

        $extension->load([], $container);

        self::assertFalse($container->hasDefinition('Rcsofttech\\AuditTrailBundle\\Controller\\Admin\\AuditLogCrudController'));
    }

    public function testValidTablePrefixAndSuffixAreStored(): void
    {
        $container = new ContainerBuilder();
        $extension = new AuditTrailExtension();

        $extension->load([[
            'table_prefix' => 'audit_2026',
            'table_suffix' => '_trail',
        ]], $container);

        self::assertSame('audit_2026', $container->getParameter('audit_trail.table_prefix'));
        self::assertSame('_trail', $container->getParameter('audit_trail.table_suffix'));
    }

    public function testInvalidTablePrefixIsRejected(): void
    {
        $container = new ContainerBuilder();
        $extension = new AuditTrailExtension();

        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage(
            'The table_prefix may contain only letters, numbers, and underscores, and must not start with a digit.'
        );

        $extension->load([[
            'table_prefix' => '123 bad-',
        ]], $container);
    }

    public function testNonStringTablePrefixIsRejectedByTypedNode(): void
    {
        $container = new ContainerBuilder();
        $extension = new AuditTrailExtension();

        $this->expectException(InvalidConfigurationException::class);

        $extension->load([[
            'table_prefix' => true,
        ]], $container);
    }

    public function testInvalidTableSuffixIsRejected(): void
    {
        $container = new ContainerBuilder();
        $extension = new AuditTrailExtension();

        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage(
            'The table_suffix may contain only letters, numbers, and underscores, and must not start with a digit.'
        );

        $extension->load([[
            'table_suffix' => 'bad-suffix',
        ]], $container);
    }

    public function testIntegritySecretMustMeetMinimumLength(): void
    {
        $container = new ContainerBuilder();
        $extension = new AuditTrailExtension();

        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('The integrity secret must be at least 32 characters long.');

        $extension->load([[
            'integrity' => [
                'enabled' => true,
                'secret' => 'too-short-secret',
            ],
        ]], $container);
    }

    public function testIntegritySecretAcceptsEnvPlaceholder(): void
    {
        $container = new ContainerBuilder();
        $extension = new AuditTrailExtension();

        $extension->load([[
            'integrity' => [
                'enabled' => true,
                'secret' => '%env(string:AUDIT_INTEGRITY_SECRET)%',
            ],
        ]], $container);

        self::assertSame(
            '%env(string:AUDIT_INTEGRITY_SECRET)%',
            $container->getDefinition('Rcsofttech\\AuditTrailBundle\\Service\\AuditIntegrityService')->getArgument('$secret'),
        );
    }

    public function testHttpTransportConfiguration(): void
    {
        if (!interface_exists(HttpClientInterface::class)) {
            self::markTestSkipped('HttpClient is not installed.');
        }

        $container = new ContainerBuilder();
        $container->setParameter('kernel.environment', 'dev');
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
        self::assertTrue($container->getParameter('audit_trail.fail_on_transport_error'));
        self::assertFalse($container->getParameter('audit_trail.fallback_to_database'));
    }

    public function testRemoteTransportDefaultsToStrictFailureHandling(): void
    {
        if (!interface_exists(HttpClientInterface::class)) {
            self::markTestSkipped('HttpClient is not installed.');
        }

        $container = new ContainerBuilder();
        $container->setParameter('kernel.environment', 'dev');
        $extension = new AuditTrailExtension();

        $extension->load([[
            'transports' => [
                'database' => ['enabled' => true],
                'http' => [
                    'enabled' => true,
                    'endpoint' => 'http://example.com',
                ],
            ],
        ]], $container);

        self::assertTrue($container->getParameter('audit_trail.fail_on_transport_error'));
        self::assertFalse($container->getParameter('audit_trail.fallback_to_database'));
    }

    public function testExplicitRemoteTransportFailureOverridesArePreserved(): void
    {
        if (!interface_exists(HttpClientInterface::class)) {
            self::markTestSkipped('HttpClient is not installed.');
        }

        $container = new ContainerBuilder();
        $container->setParameter('kernel.environment', 'dev');
        $extension = new AuditTrailExtension();

        $extension->load([[
            'fail_on_transport_error' => false,
            'fallback_to_database' => true,
            'transports' => [
                'database' => ['enabled' => true],
                'http' => [
                    'enabled' => true,
                    'endpoint' => 'http://example.com',
                ],
            ],
        ]], $container);

        self::assertFalse($container->getParameter('audit_trail.fail_on_transport_error'));
        self::assertTrue($container->getParameter('audit_trail.fallback_to_database'));
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

    public function testAsyncDatabaseTransportRegistersPersistHandlerWhenMessengerIsAvailable(): void
    {
        if (!interface_exists(MessageBusInterface::class)) {
            self::markTestSkipped('Messenger is not installed.');
        }

        $container = new ContainerBuilder();
        $extension = new AuditTrailExtension();

        $extension->load([[
            'transports' => [
                'database' => ['enabled' => true, 'async' => true],
                'http' => ['enabled' => false],
                'queue' => ['enabled' => false],
            ],
        ]], $container);

        self::assertTrue($container->hasDefinition('rcsofttech_audit_trail.transport.async_database'));
        self::assertTrue($container->hasDefinition('rcsofttech_audit_trail.handler.persist_audit_log'));
    }

    public function testChainTransportConfiguration(): void
    {
        if (!interface_exists(HttpClientInterface::class)) {
            self::markTestSkipped('HttpClient is not installed.');
        }

        $container = new ContainerBuilder();
        $container->setParameter('kernel.environment', 'dev');
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

    public function testInsecureHttpTransportIsRejectedOutsideDev(): void
    {
        if (!interface_exists(HttpClientInterface::class)) {
            self::markTestSkipped('HttpClient is not installed.');
        }

        $container = new ContainerBuilder();
        $container->setParameter('kernel.environment', 'prod');
        $extension = new AuditTrailExtension();

        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('Insecure audit HTTP endpoints are only allowed in the "dev" environment.');

        $extension->load([[
            'transports' => [
                'database' => ['enabled' => false],
                'http' => [
                    'enabled' => true,
                    'endpoint' => 'http://example.com',
                ],
            ],
        ]], $container);
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

    public function testCachePoolAliasIsRegisteredWhenConfigured(): void
    {
        $container = new ContainerBuilder();
        $extension = new AuditTrailExtension();

        $extension->load([[
            'cache_pool' => 'cache.app',
        ]], $container);

        self::assertTrue($container->hasAlias('rcsofttech_audit_trail.cache'));
        self::assertSame('cache.app', (string) $container->getAlias('rcsofttech_audit_trail.cache'));
    }

    public function testPrependDoesNothingWhenDoctrineExtensionIsMissing(): void
    {
        $container = new ContainerBuilder();
        $extension = new AuditTrailExtension();

        $extension->prepend($container);

        self::assertSame([], $container->getExtensionConfig('doctrine'));
    }

    public function testPrependRegistersDoctrineMappingWhenDoctrineExtensionExists(): void
    {
        $container = new ContainerBuilder();
        $container->registerExtension(new class implements ExtensionInterface {
            public function getAlias(): string
            {
                return 'doctrine';
            }

            public function getNamespace(): string
            {
                return '';
            }

            public function getXsdValidationBasePath(): false
            {
                return false;
            }

            public function load(array $configs, ContainerBuilder $container): void
            {
            }
        });

        $extension = new AuditTrailExtension();
        $extension->prepend($container);

        $configs = $container->getExtensionConfig('doctrine');
        self::assertCount(1, $configs);
        self::assertSame(
            'Rcsofttech\\AuditTrailBundle\\Entity',
            $configs[0]['orm']['mappings']['RcsofttechAuditTrailBundle']['prefix'] ?? null
        );
    }

    public function testGetAliasReturnsAuditTrail(): void
    {
        self::assertSame('audit_trail', new AuditTrailExtension()->getAlias());
    }
}
