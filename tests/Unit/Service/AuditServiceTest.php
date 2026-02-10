<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Tests\Unit\Service;

use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use Exception;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Rcsofttech\AuditTrailBundle\Attribute\Auditable;
use Rcsofttech\AuditTrailBundle\Contract\AuditContextContributorInterface;
use Rcsofttech\AuditTrailBundle\Contract\AuditLogInterface;
use Rcsofttech\AuditTrailBundle\Contract\AuditVoterInterface;
use Rcsofttech\AuditTrailBundle\Contract\UserResolverInterface;
use Rcsofttech\AuditTrailBundle\Service\AuditService;
use Rcsofttech\AuditTrailBundle\Service\EntityDataExtractor;
use Rcsofttech\AuditTrailBundle\Service\MetadataCache;
use Rcsofttech\AuditTrailBundle\Service\TransactionIdGenerator;
use stdClass;

#[AllowMockObjectsWithoutExpectations]
class AuditServiceTest extends TestCase
{
    private EntityManagerInterface&MockObject $entityManager;

    private UserResolverInterface&MockObject $userResolver;

    private ClockInterface&MockObject $clock;

    private LoggerInterface&MockObject $logger;

    private TransactionIdGenerator&MockObject $transactionIdGenerator;

    private EntityDataExtractor&MockObject $dataExtractor;

    private MetadataCache&MockObject $metadataCache;

    private AuditService $service;

    protected function setUp(): void
    {
        $this->entityManager = $this->createMock(EntityManagerInterface::class);
        $this->userResolver = $this->createMock(UserResolverInterface::class);
        $this->clock = $this->createMock(ClockInterface::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->transactionIdGenerator = $this->createMock(TransactionIdGenerator::class);
        $this->dataExtractor = $this->createMock(EntityDataExtractor::class);
        $this->metadataCache = $this->createMock(MetadataCache::class);

        $this->transactionIdGenerator->method('getTransactionId')->willReturn('tx1');
        $this->clock->method('now')->willReturn(new DateTimeImmutable('2023-01-01 12:00:00'));

        $this->service = new AuditService(
            $this->entityManager,
            $this->userResolver,
            $this->clock,
            $this->transactionIdGenerator,
            $this->dataExtractor,
            $this->metadataCache,
            ['IgnoredEntity'],
            [], // ignoredProperties
            $this->logger,
            'UTC',
            []
        );
    }

    public function testShouldAudit(): void
    {
        $this->metadataCache->method('getAuditableAttribute')->willReturn(new Auditable(enabled: true));
        self::assertTrue($this->service->shouldAudit(new stdClass()));

        $this->metadataCache = $this->createMock(MetadataCache::class);
        $this->metadataCache->method('getAuditableAttribute')->willReturn(null);
        $service = new AuditService(
            $this->entityManager,
            $this->userResolver,
            $this->clock,
            $this->transactionIdGenerator,
            $this->dataExtractor,
            $this->metadataCache,
            [],
            [], // ignoredProperties
            null,
            'UTC',
            []
        );
        self::assertFalse($service->shouldAudit(new stdClass()));
    }

    public function testShouldAuditWithVoters(): void
    {
        $this->metadataCache->method('getAuditableAttribute')->willReturn(new Auditable(enabled: true));

        $voter1 = $this->createMock(AuditVoterInterface::class);
        $voter1->method('vote')->willReturn(true);

        $voter2 = $this->createMock(AuditVoterInterface::class);
        $voter2->method('vote')->willReturn(false);

        $service = new AuditService(
            $this->entityManager,
            $this->userResolver,
            $this->clock,
            $this->transactionIdGenerator,
            $this->dataExtractor,
            $this->metadataCache,
            [],
            [], // ignoredProperties
            null,
            'UTC',
            [$voter1, $voter2]
        );

        self::assertFalse($service->shouldAudit(new stdClass()));
    }

    public function testShouldAuditIgnored(): void
    {
        $service = new AuditService(
            $this->entityManager,
            $this->userResolver,
            $this->clock,
            $this->transactionIdGenerator,
            $this->dataExtractor,
            $this->metadataCache,
            [stdClass::class],
            [] // ignoredProperties
        );
        self::assertFalse($service->shouldAudit(new stdClass()));
    }

    public function testGetEntityData(): void
    {
        $entity = new stdClass();
        $this->dataExtractor->expects($this->once())->method('extract')->with($entity, [])->willReturn(['data']);
        self::assertEquals(['data'], $this->service->getEntityData($entity));
    }

    public function testCreateAuditLog(): void
    {
        $entity = new stdClass();
        $metadata = $this->createMock(ClassMetadata::class);
        $metadata->method('getIdentifierValues')->willReturn(['id' => 1]);
        $this->entityManager->method('getClassMetadata')->willReturn($metadata);

        $this->userResolver->method('getUserId')->willReturn('1');

        $log = $this->service->createAuditLog($entity, AuditLogInterface::ACTION_UPDATE, ['a' => 1], ['a' => 2]);

        self::assertEquals(1, $log->getEntityId());
        self::assertEquals(['a'], $log->getChangedFields());
        self::assertEquals(1, $log->getUserId());
    }

    public function testCreateAuditLogWithContext(): void
    {
        $entity = new stdClass();
        $metadata = $this->createMock(ClassMetadata::class);
        $metadata->method('getIdentifierValues')->willReturn(['id' => 1]);
        $this->entityManager->method('getClassMetadata')->willReturn($metadata);

        $this->userResolver->method('getUserId')->willReturn('1');
        $this->userResolver->method('getImpersonatorId')->willReturn('99');
        $this->userResolver->method('getImpersonatorUsername')->willReturn('admin');

        $log = $this->service->createAuditLog($entity, AuditLogInterface::ACTION_UPDATE, ['a' => 1], ['a' => 2]);

        $context = $log->getContext();
        self::assertArrayHasKey('impersonation', $context);
        self::assertEquals(99, $context['impersonation']['impersonator_id']);
        self::assertEquals('admin', $context['impersonation']['impersonator_username']);
    }

    public function testCreateAuditLogWithContributors(): void
    {
        $entity = new stdClass();
        $metadata = $this->createMock(ClassMetadata::class);
        $metadata->method('getIdentifierValues')->willReturn(['id' => 1]);
        $this->entityManager->method('getClassMetadata')->willReturn($metadata);

        $contributor = $this->createMock(AuditContextContributorInterface::class);
        $contributor->expects($this->once())
            ->method('contribute')
            ->with($entity, AuditLogInterface::ACTION_UPDATE, ['a' => 2])
            ->willReturn(['custom_key' => 'custom_value']);

        $service = new AuditService(
            $this->entityManager,
            $this->userResolver,
            $this->clock,
            $this->transactionIdGenerator,
            $this->dataExtractor,
            $this->metadataCache,
            [],
            [], // ignoredProperties
            null,
            'UTC',
            [],
            [$contributor]
        );

        $log = $service->createAuditLog($entity, AuditLogInterface::ACTION_UPDATE, ['a' => 1], ['a' => 2]);

        $context = $log->getContext();
        self::assertArrayHasKey('custom_key', $context);
        self::assertEquals('custom_value', $context['custom_key']);
    }

    public function testCreateAuditLogWithCustomContext(): void
    {
        $entity = new stdClass();
        $metadata = $this->createMock(ClassMetadata::class);
        $metadata->method('getIdentifierValues')->willReturn(['id' => 1]);
        $this->entityManager->method('getClassMetadata')->willReturn($metadata);

        $log = $this->service->createAuditLog(
            $entity,
            AuditLogInterface::ACTION_UPDATE,
            ['a' => 1],
            ['a' => 2],
            ['manual_key' => 'manual_value']
        );

        $context = $log->getContext();
        self::assertArrayHasKey('manual_key', $context);
        self::assertEquals('manual_value', $context['manual_key']);
    }

    public function testCreateAuditLogPendingIdDelete(): void
    {
        $entity = new stdClass();
        $metadata = $this->createMock(ClassMetadata::class);
        $metadata->method('getIdentifierValues')->willReturn([]); // No ID yet (simulated)
        $metadata->method('getIdentifierFieldNames')->willReturn(['id']);
        $this->entityManager->method('getClassMetadata')->willReturn($metadata);

        $log = $this->service->createAuditLog(
            $entity,
            AuditLogInterface::ACTION_DELETE,
            ['id' => 123, 'data' => 'val'],
            null
        );

        self::assertEquals('123', $log->getEntityId());
    }

    public function testCreateAuditLogPendingIdDeleteComposite(): void
    {
        $entity = new stdClass();
        $metadata = $this->createMock(ClassMetadata::class);
        $metadata->method('getIdentifierValues')->willReturn([]);
        $metadata->method('getIdentifierFieldNames')->willReturn(['id1', 'id2']);
        $this->entityManager->method('getClassMetadata')->willReturn($metadata);

        $log = $this->service->createAuditLog(
            $entity,
            AuditLogInterface::ACTION_DELETE,
            ['id1' => 1, 'id2' => 2],
            null
        );

        self::assertEquals('["1","2"]', $log->getEntityId());
    }

    public function testEnrichUserContextException(): void
    {
        $entity = new stdClass();
        $metadata = $this->createMock(ClassMetadata::class);
        $metadata->method('getIdentifierValues')->willReturn(['id' => 1]);
        $this->entityManager->method('getClassMetadata')->willReturn($metadata);

        $this->userResolver->method('getUserId')->willThrowException(new Exception('User error'));
        $this->logger->expects($this->once())->method('error');

        $this->service->createAuditLog($entity, 'create');
    }

    public function testGetSensitiveFields(): void
    {
        $this->metadataCache->method('getSensitiveFields')->willReturn(['field' => 'mask']);
        self::assertEquals(['field' => 'mask'], $this->service->getSensitiveFields(new stdClass()));
    }
}
