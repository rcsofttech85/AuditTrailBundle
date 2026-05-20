<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Tests\Functional;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Rcsofttech\AuditTrailBundle\Contract\AuditReverterInterface;
use Rcsofttech\AuditTrailBundle\Entity\AuditLog;
use Rcsofttech\AuditTrailBundle\Enum\AuditAction;
use Rcsofttech\AuditTrailBundle\Enum\AuditPhase;
use Rcsofttech\AuditTrailBundle\Tests\Functional\Entity\TestEntity;
use Rcsofttech\AuditTrailBundle\Tests\TestDatabaseUrlResolver;
use RuntimeException;

use function assert;
use function is_file;
use function sprintf;
use function sys_get_temp_dir;
use function tempnam;
use function unlink;

/**
 * Tests transaction safety behavior for rollback, deferred persistence, and revert recovery.
 *
 * These tests boot fresh kernels when they need to cross failure boundaries so
 * each assertion observes a clean Doctrine state after rollback or committed
 * post-commit transport failure paths.
 */
final class TransactionSafetyTest extends AbstractFunctionalTestCase
{
    /** @var list<string> */
    private array $databasePaths = [];

    protected function setUp(): void
    {
        parent::setUp();
        TestKernel::$useThrowingTransport = true;
    }

    protected function tearDown(): void
    {
        foreach ($this->databasePaths as $databasePath) {
            if (is_file($databasePath)) {
                unlink($databasePath);
            }
        }

        parent::tearDown();
    }

    /** @param array<string, mixed> $options */
    private function getFreshEntityManager(array $options): EntityManagerInterface
    {
        self::bootKernel($options);

        return $this->getEntityManager();
    }

    private function flushAndCaptureFailure(EntityManagerInterface $em): RuntimeException
    {
        try {
            $em->flush();
            self::fail('Expected flush to throw a RuntimeException.');
        } catch (RuntimeException $exception) {
            return $exception;
        }
    }

    private function createDatabasePath(): string
    {
        $databasePath = tempnam(sys_get_temp_dir(), 'audit_revert_');
        if ($databasePath === false) {
            self::fail('Failed to create a temporary SQLite database path.');
        }

        $this->databasePaths[] = $databasePath;

        return $databasePath;
    }

    /**
     * @param array<string, mixed> $auditConfig
     *
     * @return array<string, mixed>
     */
    private function buildStandaloneDatabaseOptions(array $auditConfig): array
    {
        if (TestDatabaseUrlResolver::resolve() !== null) {
            self::markTestSkipped(
                'This scenario relies on a standalone SQLite database outside DAMA static transaction management.',
            );
        }

        $databasePath = $this->createDatabasePath();

        return [
            'doctrine_config' => [
                'dbal' => [
                    'url' => sprintf('sqlite:///%s', $databasePath),
                ],
            ],
            'audit_config' => $auditConfig,
        ];
    }

    private function createSchema(EntityManagerInterface $em): void
    {
        $metadata = $em->getMetadataFactory()->getAllMetadata();
        $schemaTool = new SchemaTool($em);
        $schemaTool->dropSchema($metadata);
        $schemaTool->createSchema($metadata);
    }

    /**
     * @param array<string, mixed> $options
     * @param list<string>|null    $supportedPhases
     */
    private function bootConfiguredKernel(array $options, bool $useThrowingTransport, ?array $supportedPhases = null): EntityManagerInterface
    {
        self::ensureKernelShutdown();
        TestKernel::$useThrowingTransport = $useThrowingTransport;
        TestKernel::$throwingTransportSupportedPhases = $supportedPhases;

        self::bootKernel($options);

        return $this->getEntityManager();
    }

    public function testAtomicModeRollsBackDataOnTransportFailure(): void
    {
        $options = [
            'audit_config' => [
                'defer_transport_until_commit' => false,
                'fail_on_transport_error' => true,
                'transports' => ['database' => ['enabled' => true]],
            ],
        ];

        self::bootKernel($options);

        $em = $this->getEntityManager();

        $entity = new TestEntity('Atomic Test');
        $em->persist($entity);

        $exception = $this->flushAndCaptureFailure($em);
        self::assertSame('Transport failed intentionally.', $exception->getMessage());

        $em = $this->getFreshEntityManager($options);
        $savedEntity = $em->getRepository(TestEntity::class)->findOneBy(['name' => 'Atomic Test']);
        self::assertNull($savedEntity, 'Entity should NOT be saved in Atomic mode if transport fails.');
    }

    public function testDeferredModePersistsDataEvenIfTransportFails(): void
    {
        $options = [
            'audit_config' => [
                'defer_transport_until_commit' => true,
                'transports' => ['database' => ['enabled' => true]],
            ],
        ];

        self::bootKernel($options);

        $em = $this->getEntityManager();

        $entity = new TestEntity('Deferred Test');
        $em->persist($entity);
        $em->flush();

        $em->clear();
        $savedEntity = $em->getRepository(TestEntity::class)->findOneBy(['name' => 'Deferred Test']);
        self::assertNotNull($savedEntity, 'Entity SHOULD be saved in Deferred mode even if transport fails.');
    }

    public function testImmediateModeWithFallbackPersistsDataWithoutUnsafeNestedFlushFailure(): void
    {
        $options = [
            'audit_config' => [
                'defer_transport_until_commit' => false,
                'fail_on_transport_error' => false,
                'fallback_to_database' => true,
                'transports' => ['database' => ['enabled' => true]],
            ],
        ];

        self::bootKernel($options);

        $em = $this->getEntityManager();

        $entity = new TestEntity('Immediate Fallback Test');
        $em->persist($entity);
        $em->flush();
        $em->clear();

        $savedEntity = $em->getRepository(TestEntity::class)->findOneBy(['name' => 'Immediate Fallback Test']);
        self::assertNotNull(
            $savedEntity,
            'Entity should be saved when immediate transport fails but database fallback is enabled.'
        );

        $auditLogs = $em->getRepository(AuditLog::class)->findBy([
            'entityClass' => TestEntity::class,
            'entityId' => (string) $savedEntity->getId(),
        ]);
        self::assertNotEmpty($auditLogs, 'Fallback should still persist an audit log in immediate mode.');
    }

    public function testDeferredModeWithFailOnErrorThrowsButPersistsData(): void
    {
        $options = [
            'audit_config' => [
                'defer_transport_until_commit' => true,
                'fail_on_transport_error' => true,
                'transports' => ['database' => ['enabled' => true]],
            ],
        ];

        self::bootKernel($options);

        $em = $this->getEntityManager();

        $entity = new TestEntity('Deferred Fail Test');
        $em->persist($entity);

        $exception = $this->flushAndCaptureFailure($em);
        self::assertSame('Transport failed intentionally.', $exception->getMessage());

        $em->clear();
        $savedEntity = $em->getRepository(TestEntity::class)->findOneBy(['name' => 'Deferred Fail Test']);
        self::assertNotNull(
            $savedEntity,
            'Entity SHOULD be saved in Deferred mode even if transport fails and exception is thrown.'
        );
    }

    public function testStrictRevertRollsBackEntityWhenRevertAuditFailsInTransaction(): void
    {
        $options = $this->buildStandaloneDatabaseOptions([
            'defer_transport_until_commit' => false,
            'fail_on_transport_error' => true,
            'transports' => ['database' => ['enabled' => true]],
        ]);

        $em = $this->bootConfiguredKernel($options, false);
        $this->createSchema($em);

        $entity = new TestEntity('Original Revert Value');
        $em->persist($entity);
        $em->flush();

        $entityId = $entity->getId();
        self::assertNotNull($entityId);

        $entity->setName('Updated Revert Value');
        $em->flush();
        $em->clear();

        $em = $this->bootConfiguredKernel($options, true, [AuditPhase::OnFlush->value]);
        $updateLog = $em->getRepository(AuditLog::class)->findOneBy([
            'entityClass' => TestEntity::class,
            'entityId' => (string) $entityId,
            'action' => AuditAction::Update,
        ]);
        self::assertInstanceOf(AuditLog::class, $updateLog);

        $reverter = self::getContainer()->get(AuditReverterInterface::class);
        assert($reverter instanceof AuditReverterInterface);

        try {
            $reverter->revert($updateLog);
            self::fail('Expected revert to fail when the in-transaction transport throws.');
        } catch (RuntimeException $exception) {
            self::assertSame('Transport failed intentionally.', $exception->getMessage());
        }

        $em = $this->bootConfiguredKernel($options, false);
        $savedEntity = $em->getRepository(TestEntity::class)->find($entityId);
        self::assertInstanceOf(TestEntity::class, $savedEntity);
        self::assertSame('Updated Revert Value', $savedEntity->getName());

        $revertLogs = $em->getRepository(AuditLog::class)->findBy([
            'entityClass' => TestEntity::class,
            'entityId' => (string) $entityId,
            'action' => AuditAction::Revert,
        ]);
        self::assertSame([], $revertLogs, 'Failed in-transaction revert audits must not leak a committed revert log.');
    }

    public function testCommittedButUndeliveredRevertCannotBeAppliedTwice(): void
    {
        $options = $this->buildStandaloneDatabaseOptions([
            'defer_transport_until_commit' => false,
            'fail_on_transport_error' => true,
            'transports' => ['database' => ['enabled' => true]],
        ]);

        $em = $this->bootConfiguredKernel($options, false);
        $this->createSchema($em);

        $entity = new TestEntity('Original Replay Value');
        $em->persist($entity);
        $em->flush();

        $entityId = $entity->getId();
        self::assertNotNull($entityId);

        $entity->setName('Updated Replay Value');
        $em->flush();
        $em->clear();

        $em = $this->bootConfiguredKernel($options, true, [AuditPhase::PostFlush->value]);
        $updateLog = $em->getRepository(AuditLog::class)->findOneBy([
            'entityClass' => TestEntity::class,
            'entityId' => (string) $entityId,
            'action' => AuditAction::Update,
        ]);
        self::assertInstanceOf(AuditLog::class, $updateLog);

        $reverter = self::getContainer()->get(AuditReverterInterface::class);
        assert($reverter instanceof AuditReverterInterface);

        try {
            $reverter->revert($updateLog);
            self::fail('Expected deferred revert audit delivery to fail after commit.');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('Revert committed', $exception->getMessage());
        }

        $em = $this->bootConfiguredKernel($options, false);
        $savedEntity = $em->getRepository(TestEntity::class)->find($entityId);
        self::assertInstanceOf(TestEntity::class, $savedEntity);
        self::assertSame('Original Replay Value', $savedEntity->getName());
        self::assertCount(0, $em->getRepository(AuditLog::class)->findBy([
            'entityClass' => TestEntity::class,
            'entityId' => (string) $entityId,
            'action' => AuditAction::Revert,
        ]));

        $updateLog = $em->getRepository(AuditLog::class)->findOneBy([
            'entityClass' => TestEntity::class,
            'entityId' => (string) $entityId,
            'action' => AuditAction::Update,
        ]);
        self::assertInstanceOf(AuditLog::class, $updateLog);

        $reverter = self::getContainer()->get(AuditReverterInterface::class);
        assert($reverter instanceof AuditReverterInterface);

        try {
            $reverter->revert($updateLog);
            self::fail('Expected the second revert attempt to be rejected as a no-op replay.');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('already matches the target state', $exception->getMessage());
        }

        $em = $this->bootConfiguredKernel($options, false);
        self::assertCount(0, $em->getRepository(AuditLog::class)->findBy([
            'entityClass' => TestEntity::class,
            'entityId' => (string) $entityId,
            'action' => AuditAction::Revert,
        ]));
    }
}
