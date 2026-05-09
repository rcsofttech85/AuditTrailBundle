<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Tests\Unit\Service;

use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\Persistence\ManagerRegistry;
use Exception;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Rcsofttech\AuditTrailBundle\Contract\AuditMetadataManagerInterface;
use Rcsofttech\AuditTrailBundle\Contract\AuditVoterInterface;
use Rcsofttech\AuditTrailBundle\Contract\ContextResolverInterface;
use Rcsofttech\AuditTrailBundle\Contract\EntityDataExtractorInterface;
use Rcsofttech\AuditTrailBundle\Contract\EntityIdResolverInterface;
use Rcsofttech\AuditTrailBundle\Enum\AuditAction;
use Rcsofttech\AuditTrailBundle\Service\AuditContextNormalizer;
use Rcsofttech\AuditTrailBundle\Service\AuditLogFactory;
use Rcsofttech\AuditTrailBundle\Service\AuditService;
use Rcsofttech\AuditTrailBundle\Service\ContextSanitizer;
use Rcsofttech\AuditTrailBundle\Service\EntityClassResolver;
use Rcsofttech\AuditTrailBundle\Service\EntityManagerResolver;
use Rcsofttech\AuditTrailBundle\Service\TransactionIdGenerator;
use Rcsofttech\AuditTrailBundle\Tests\Unit\AbstractAuditTestCase;
use RuntimeException;
use stdClass;
use Symfony\Component\Uid\Factory\UuidFactory;

use function fclose;
use function tmpfile;

final class AuditServiceTest extends AbstractAuditTestCase
{
    /** @var EntityManagerInterface&\PHPUnit\Framework\MockObject\Stub */
    private EntityManagerInterface $entityManager;

    /** @var ClockInterface&\PHPUnit\Framework\MockObject\Stub */
    private ClockInterface $clock;

    /** @var (LoggerInterface&\PHPUnit\Framework\MockObject\Stub)|(LoggerInterface&MockObject) */
    private LoggerInterface $logger;

    private TransactionIdGenerator $transactionIdGenerator;

    /** @var (EntityDataExtractorInterface&\PHPUnit\Framework\MockObject\Stub)|(EntityDataExtractorInterface&MockObject) */
    private EntityDataExtractorInterface $dataExtractor;

    /** @var (AuditMetadataManagerInterface&\PHPUnit\Framework\MockObject\Stub)|(AuditMetadataManagerInterface&MockObject) */
    private AuditMetadataManagerInterface $metadataManager;

    /** @var (ContextResolverInterface&\PHPUnit\Framework\MockObject\Stub)|(ContextResolverInterface&MockObject) */
    private ContextResolverInterface $contextResolver;

    /** @var EntityIdResolverInterface&\PHPUnit\Framework\MockObject\Stub */
    private EntityIdResolverInterface $idResolver;

    private EntityClassResolver $entityClassResolver;

    private AuditService $service;

    protected function setUp(): void
    {
        $this->entityManager = self::createStub(EntityManagerInterface::class);
        $this->clock = self::createStub(ClockInterface::class);
        $this->logger = self::createStub(LoggerInterface::class);
        $this->transactionIdGenerator = new TransactionIdGenerator(new UuidFactory());
        $this->dataExtractor = self::createStub(EntityDataExtractorInterface::class);
        $this->metadataManager = self::createStub(AuditMetadataManagerInterface::class);
        $this->contextResolver = self::createStub(ContextResolverInterface::class);
        $this->idResolver = self::createStub(EntityIdResolverInterface::class);
        $this->entityManager->method('getClassMetadata')->willThrowException(new RuntimeException('No metadata configured for this test stub.'));
        $this->entityClassResolver = new EntityClassResolver();

        $this->clock->method('now')->willReturn(new DateTimeImmutable('2023-01-01 12:00:00'));

        $this->service = new AuditService(
            $this->entityManager,
            $this->dataExtractor,
            $this->metadataManager,
            $this->createAuditLogFactory(),
        );
    }

    /** @return LoggerInterface&MockObject */
    private function useLoggerMock(): LoggerInterface
    {
        $logger = self::createMock(LoggerInterface::class);
        $this->logger = $logger;

        return $logger;
    }

    /** @return EntityDataExtractorInterface&MockObject */
    private function useDataExtractorMock(): EntityDataExtractorInterface
    {
        $dataExtractor = self::createMock(EntityDataExtractorInterface::class);
        $this->dataExtractor = $dataExtractor;

        return $dataExtractor;
    }

    /** @return ContextResolverInterface&\PHPUnit\Framework\MockObject\Stub */
    private function useContextResolverStub(): ContextResolverInterface
    {
        $contextResolver = self::createStub(ContextResolverInterface::class);
        $this->contextResolver = $contextResolver;

        return $contextResolver;
    }

    private function rebuildService(?LoggerInterface $logger = null): AuditService
    {
        $this->service = new AuditService(
            $this->entityManager,
            $this->dataExtractor,
            $this->metadataManager,
            $this->createAuditLogFactory($logger),
        );

        return $this->service;
    }

    private function createAuditLogFactory(
        ?LoggerInterface $logger = null,
        ?EntityClassResolver $entityClassResolver = null,
    ): AuditLogFactory {
        return new AuditLogFactory(
            $this->clock,
            $this->transactionIdGenerator,
            $this->contextResolver,
            $this->idResolver,
            new ContextSanitizer(),
            new AuditContextNormalizer(new ContextSanitizer(), null, $logger ?? $this->logger),
            $logger ?? $this->logger,
            entityClassResolver: $entityClassResolver ?? $this->entityClassResolver,
        );
    }

    private function createResolver(string $class, EntityManagerInterface $entityManager): EntityManagerResolver
    {
        $registry = self::createStub(ManagerRegistry::class);
        $registry->method('getManagerForClass')->willReturnCallback(
            static fn (string $resolvedClass): ?EntityManagerInterface => $resolvedClass === $class ? $entityManager : null
        );

        return new EntityManagerResolver($registry);
    }

    public function testShouldAuditWhenEntityIsNotIgnored(): void
    {
        $this->metadataManager->method('isEntityIgnored')->willReturn(false);

        self::assertTrue($this->service->shouldAudit(new stdClass()));
    }

    public function testShouldAuditWithVoters(): void
    {
        $this->metadataManager->method('isEntityIgnored')->willReturn(false);

        $voter1 = self::createStub(AuditVoterInterface::class);
        $voter1->method('vote')->willReturn(true);

        $voter2 = self::createStub(AuditVoterInterface::class);
        $voter2->method('vote')->willReturn(false);

        $service = new AuditService(
            $this->entityManager,
            $this->dataExtractor,
            $this->metadataManager,
            $this->createAuditLogFactory(null),
            [$voter1, $voter2],
        );

        self::assertFalse($service->shouldAudit(new stdClass()));
    }

    public function testShouldAuditIgnored(): void
    {
        $this->metadataManager->method('isEntityIgnored')->willReturn(true);
        self::assertFalse($this->service->shouldAudit(new stdClass()));
    }

    public function testShouldAuditNormalizesProxyEntityClassBeforeIgnoredCheck(): void
    {
        $entity = new class extends stdClass {
        };
        $secondaryEntityManager = $this->createMock(EntityManagerInterface::class);
        $secondaryEntityManager->expects($this->once())
            ->method('getClassMetadata')
            ->with($entity::class)
            ->willReturn(new ClassMetadata(stdClass::class));

        $metadataManager = $this->createMock(AuditMetadataManagerInterface::class);
        $metadataManager->expects($this->once())
            ->method('isEntityIgnored')
            ->with(stdClass::class)
            ->willReturn(true);

        $resolver = $this->createResolver(stdClass::class, $secondaryEntityManager);
        $service = new AuditService(
            $this->entityManager,
            $this->dataExtractor,
            $metadataManager,
            $this->createAuditLogFactory(entityClassResolver: new EntityClassResolver($resolver)),
            [],
            $resolver,
            new EntityClassResolver($resolver),
        );

        self::assertFalse($service->shouldAudit($entity));
    }

    public function testGetEntityData(): void
    {
        $entity = new stdClass();
        $dataExtractor = $this->useDataExtractorMock();
        $dataExtractor->expects($this->once())
            ->method('extract')
            ->with($entity, [], $this->entityManager)
            ->willReturn(['data']);
        $service = $this->rebuildService();
        self::assertEquals(['data'], $service->getEntityData($entity));
    }

    public function testGetEntityDataUsesResolvedEntityManagerWhenAvailable(): void
    {
        $entity = new stdClass();
        $secondaryEntityManager = self::createStub(EntityManagerInterface::class);
        $secondaryEntityManager->method('getClassMetadata')->willThrowException(new RuntimeException('No metadata configured for this test stub.'));
        $dataExtractor = $this->useDataExtractorMock();
        $dataExtractor->expects($this->once())
            ->method('extract')
            ->with($entity, [], $secondaryEntityManager)
            ->willReturn(['payload' => 'data']);

        $service = new AuditService(
            $this->entityManager,
            $dataExtractor,
            $this->metadataManager,
            $this->createAuditLogFactory(),
            [],
            $this->createResolver($entity::class, $secondaryEntityManager),
        );

        self::assertSame(['payload' => 'data'], $service->getEntityData($entity));
    }

    public function testCreateAuditLog(): void
    {
        $entity = new stdClass();
        $this->idResolver->method('resolveFromEntity')->willReturn('1');

        $this->contextResolver->method('resolve')->willReturn([
            'userId' => '1',
            'username' => 'user1',
            'ipAddress' => '127.0.0.1',
            'userAgent' => 'Agent',
            'context' => ['foo' => 'bar'],
        ]);

        $log = $this->service->createAuditLog($entity, AuditAction::Update, ['a' => 1], ['a' => 2]);

        self::assertSame('1', $log->entityId);
        self::assertSame(['a'], $log->changedFields);
        self::assertSame('1', $log->userId);
        self::assertSame(['foo' => 'bar'], $log->context);
    }

    public function testCreateAuditLogUsesResolvedEntityManagerWhenAvailable(): void
    {
        $entity = new stdClass();
        $secondaryEntityManager = self::createStub(EntityManagerInterface::class);
        $secondaryEntityManager->method('getClassMetadata')->willThrowException(new RuntimeException('No metadata configured for this test stub.'));
        $this->idResolver = self::createMock(EntityIdResolverInterface::class);
        $this->idResolver->expects($this->once())
            ->method('resolveFromEntity')
            ->with($entity, $secondaryEntityManager)
            ->willReturn('1');

        $this->contextResolver->method('resolve')->willReturn([
            'userId' => null,
            'username' => null,
            'ipAddress' => null,
            'userAgent' => null,
            'context' => [],
        ]);

        $service = new AuditService(
            $this->entityManager,
            $this->dataExtractor,
            $this->metadataManager,
            $this->createAuditLogFactory(),
            [],
            $this->createResolver($entity::class, $secondaryEntityManager),
        );

        $log = $service->createAuditLog($entity, AuditAction::Create);

        self::assertSame('1', $log->entityId);
    }

    public function testCreateAuditLogSetsChangedFieldsForSoftDelete(): void
    {
        $entity = new stdClass();
        $this->idResolver->method('resolveFromEntity')->willReturn('1');

        $this->contextResolver->method('resolve')->willReturn([
            'userId' => '1',
            'username' => 'user1',
            'ipAddress' => '127.0.0.1',
            'userAgent' => 'Agent',
            'context' => [],
        ]);

        $log = $this->service->createAuditLog(
            $entity,
            AuditAction::SoftDelete,
            ['deletedAt' => null],
            ['deletedAt' => '2026-04-05T08:46:03+00:00'],
        );

        self::assertSame(['deletedAt'], $log->changedFields);
    }

    public function testCreateAuditLogWithContext(): void
    {
        $entity = new stdClass();
        $this->idResolver->method('resolveFromEntity')->willReturn('1');

        $this->contextResolver->method('resolve')->willReturn([
            'userId' => '1',
            'username' => 'user1',
            'ipAddress' => '127.0.0.1',
            'userAgent' => 'Agent',
            'context' => [
                'foo' => 'bar',
                'impersonation' => [
                    'impersonator_id' => '99',
                    'impersonator_username' => 'admin',
                ],
            ],
        ]);

        $log = $this->service->createAuditLog($entity, AuditAction::Update, ['a' => 1], ['a' => 2]);

        $context = $log->context;
        self::assertArrayHasKey('impersonation', $context);
        $impersonation = $context['impersonation'];
        self::assertIsArray($impersonation);
        self::assertSame('99', $impersonation['impersonator_id'] ?? null);
        self::assertSame('admin', $impersonation['impersonator_username'] ?? null);
    }

    public function testCreateAuditLogSanitizesMalformedUtf8InValues(): void
    {
        $entity = new stdClass();
        $this->idResolver->method('resolveFromEntity')->willReturn('1');

        $this->contextResolver->method('resolve')->willReturn([
            'userId' => '1',
            'username' => 'user1',
            'ipAddress' => '127.0.0.1',
            'userAgent' => 'Agent',
            'context' => [],
        ]);

        $invalid = "\xB1\x31";
        $log = $this->service->createAuditLog(
            $entity,
            AuditAction::Update,
            ['title' => $invalid],
            ['title' => $invalid, 'nested' => ['value' => $invalid]],
        );

        self::assertSame('[invalid utf-8]', $log->oldValues['title'] ?? null);
        self::assertSame('[invalid utf-8]', $log->newValues['title'] ?? null);
        $nested = $log->newValues['nested'] ?? null;
        self::assertIsArray($nested);
        self::assertSame('[invalid utf-8]', $nested['value'] ?? null);
    }

    public function testCreateAuditLogSanitizesMalformedUtf8InActorMetadata(): void
    {
        $entity = new stdClass();
        $this->idResolver->method('resolveFromEntity')->willReturn('1');

        $invalid = "\xB1\x31";
        $this->contextResolver->method('resolve')->willReturn([
            'userId' => $invalid,
            'username' => $invalid,
            'ipAddress' => null,
            'userAgent' => $invalid,
            'context' => [],
        ]);

        $log = $this->service->createAuditLog($entity, AuditAction::Update, ['a' => 1], ['a' => 2]);

        self::assertSame('[invalid utf-8]', $log->userId);
        self::assertSame('[invalid utf-8]', $log->username);
        self::assertSame('[invalid utf-8]', $log->userAgent);
    }

    public function testCreateAuditLogWithContributors(): void
    {
        $entity = new stdClass();
        $this->idResolver->method('resolveFromEntity')->willReturn('1');

        $this->contextResolver->method('resolve')->willReturn([
            'userId' => '1',
            'username' => 'user1',
            'ipAddress' => '127.0.0.1',
            'userAgent' => 'Agent',
            'context' => ['custom_info' => 'custom_value'],
        ]);

        $log = $this->service->createAuditLog($entity, AuditAction::Update, ['a' => 1], ['a' => 2]);

        $context = $log->context;
        self::assertArrayHasKey('custom_info', $context);
        self::assertSame('custom_value', $context['custom_info']);
    }

    public function testCreateAuditLogWithCustomContext(): void
    {
        $entity = new stdClass();
        $this->idResolver->method('resolveFromEntity')->willReturn('1');

        $this->contextResolver->method('resolve')->willReturn([
            'userId' => '1',
            'username' => 'user1',
            'ipAddress' => '127.0.0.1',
            'userAgent' => 'Agent',
            'context' => ['manual_info' => 'manual_value'],
        ]);

        $log = $this->service->createAuditLog(
            $entity,
            AuditAction::Update,
            ['a' => 1],
            ['a' => 2],
            ['manual_info' => 'manual_value']
        );

        $context = $log->context;
        self::assertArrayHasKey('manual_info', $context);
        self::assertSame('manual_value', $context['manual_info']);
    }

    public function testCreateAuditLogPendingIdDelete(): void
    {
        $entity = new stdClass();
        $this->idResolver->method('resolveFromEntity')->willReturn(null);
        $this->idResolver->method('resolveFromValues')->willReturn('123');

        $this->contextResolver->method('resolve')->willReturn([
            'userId' => '1',
            'username' => 'user1',
            'ipAddress' => '127.0.0.1',
            'userAgent' => 'Agent',
            'context' => [],
        ]);

        $log = $this->service->createAuditLog(
            $entity,
            AuditAction::Delete,
            ['id' => 123, 'data' => 'val'],
            null
        );

        self::assertSame('123', $log->entityId);
    }

    public function testCreateAuditLogPendingIdDeleteComposite(): void
    {
        $entity = new stdClass();
        $this->idResolver->method('resolveFromEntity')->willReturn(null);
        $this->idResolver->method('resolveFromValues')->willReturn('["1","2"]');

        $this->contextResolver->method('resolve')->willReturn([
            'userId' => '1',
            'username' => 'user1',
            'ipAddress' => '127.0.0.1',
            'userAgent' => 'Agent',
            'context' => [],
        ]);

        $log = $this->service->createAuditLog(
            $entity,
            AuditAction::Delete,
            ['id1' => 1, 'id2' => 2],
            null
        );

        self::assertSame('["1","2"]', $log->entityId);
    }

    public function testEnrichUserContextExceptionEmittedWhenResolverFails(): void
    {
        $entity = new stdClass();
        $this->idResolver->method('resolveFromEntity')->willReturn('1');

        $contextResolver = $this->useContextResolverStub();
        $contextResolver->method('resolve')->willThrowException(new Exception('User error'));
        // AuditService should catch and log errors from context resolver
        $logger = $this->useLoggerMock();
        $logger->expects($this->once())->method('warning');

        $service = $this->rebuildService($logger);
        $service->createAuditLog($entity, AuditAction::Create);
    }

    public function testCreateAuditLogSanitizesNonJsonSafeContext(): void
    {
        $entity = new stdClass();
        $resource = tmpfile();
        self::assertIsResource($resource);

        try {
            $this->idResolver->method('resolveFromEntity')->willReturn('1');
            $this->contextResolver->method('resolve')->willReturn([
                'userId' => '1',
                'username' => 'user1',
                'ipAddress' => '127.0.0.1',
                'userAgent' => 'Agent',
                'context' => ['stream' => $resource],
            ]);

            $log = $this->service->createAuditLog($entity, AuditAction::Create);

            self::assertSame('[resource:stream]', $log->context['stream'] ?? null);
        } finally {
            fclose($resource);
        }
    }

    public function testCreateAuditLogMarksContextAsNormalized(): void
    {
        $entity = new stdClass();
        $this->idResolver->method('resolveFromEntity')->willReturn('1');
        $this->contextResolver->method('resolve')->willReturn([
            'userId' => '1',
            'username' => 'user1',
            'ipAddress' => '127.0.0.1',
            'userAgent' => 'Agent',
            'context' => ['foo' => 'bar'],
        ]);

        $log = $this->service->createAuditLog($entity, AuditAction::Create);

        self::assertTrue($log->isContextNormalized());
    }

    public function testGetSensitiveFields(): void
    {
        $this->metadataManager->method('getSensitiveFields')->willReturn(['field' => 'mask']);
        self::assertSame(['field' => 'mask'], $this->service->getSensitiveFields(new stdClass()));
    }

    public function testPassesVotersWithNoVoters(): void
    {
        self::assertTrue($this->service->passesVoters(new stdClass(), AuditAction::Access));
    }

    public function testPassesVotersWithApprovingVoter(): void
    {
        $voter = self::createMock(AuditVoterInterface::class);
        $voter->expects($this->once())->method('vote')
            ->with(self::isInstanceOf(stdClass::class), AuditAction::Access, [])
            ->willReturn(true);

        $service = new AuditService(
            $this->entityManager,
            $this->dataExtractor,
            $this->metadataManager,
            $this->createAuditLogFactory(null),
            [$voter],
        );

        self::assertTrue($service->passesVoters(new stdClass(), AuditAction::Access));
    }

    public function testPassesVotersWithVetoingVoter(): void
    {
        $voter = self::createStub(AuditVoterInterface::class);
        $voter->method('vote')->willReturn(false);

        $service = new AuditService(
            $this->entityManager,
            $this->dataExtractor,
            $this->metadataManager,
            $this->createAuditLogFactory(null),
            [$voter],
        );

        self::assertFalse($service->passesVoters(new stdClass(), AuditAction::Access));
    }
}
