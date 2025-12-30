<?php

namespace Rcsofttech\AuditTrailBundle\Tests\Unit\Service;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Rcsofttech\AuditTrailBundle\Attribute\Auditable;
use Rcsofttech\AuditTrailBundle\Contract\UserResolverInterface;
use Rcsofttech\AuditTrailBundle\Entity\AuditLog;
use Rcsofttech\AuditTrailBundle\Service\AuditService;
use Rcsofttech\AuditTrailBundle\Service\TransactionIdGenerator;
use Rcsofttech\AuditTrailBundle\Service\EntityDataExtractor;
use Rcsofttech\AuditTrailBundle\Service\MetadataCache;
use PHPUnit\Framework\MockObject\Stub;

class AuditServiceTest extends TestCase
{
    /** @var EntityManagerInterface&Stub */
    private $entityManager;

    /** @var UserResolverInterface&Stub */
    private $userResolver;

    /** @var ClockInterface&Stub */
    private $clock;

    /** @var LoggerInterface&Stub */
    private $logger;

    /** @var TransactionIdGenerator&Stub */
    private $transactionIdGenerator;

    /** @var EntityDataExtractor&Stub */
    private $dataExtractor;

    /** @var MetadataCache&Stub */
    private $metadataCache;

    private AuditService $service;

    protected function setUp(): void
    {
        $this->entityManager = self::createStub(EntityManagerInterface::class);
        $this->userResolver = self::createStub(UserResolverInterface::class);
        $this->clock = self::createStub(ClockInterface::class);
        $this->logger = self::createStub(LoggerInterface::class);
        $this->transactionIdGenerator = self::createStub(TransactionIdGenerator::class);
        $this->transactionIdGenerator->method('getTransactionId')->willReturn('test-transaction-id');
        $this->dataExtractor = self::createStub(EntityDataExtractor::class);
        $this->metadataCache = self::createStub(MetadataCache::class);

        $this->service = new AuditService(
            $this->entityManager,
            $this->userResolver,
            $this->clock,
            $this->transactionIdGenerator,
            $this->dataExtractor,
            $this->metadataCache,
            ['ignoredField'],
            $this->logger
        );
    }

    public function testShouldAuditReturnsTrueForAuditableEntity(): void
    {
        $this->metadataCache->method('getAuditableAttribute')->willReturn(new Auditable(enabled: true));
        $entity = new TestEntity();
        self::assertTrue($this->service->shouldAudit($entity));
    }

    public function testShouldAuditReturnsFalseForIgnoredEntity(): void
    {
        $service = new AuditService(
            $this->entityManager,
            $this->userResolver,
            $this->clock,
            $this->transactionIdGenerator,
            $this->dataExtractor,
            $this->metadataCache,
            [TestEntity::class],
            $this->logger
        );

        $entity = new TestEntity();
        self::assertFalse($service->shouldAudit($entity));
    }

    public function testGetEntityDataExtractsFields(): void
    {
        $entity = new TestEntity();
        $dataExtractor = $this->createMock(EntityDataExtractor::class);
        $dataExtractor->expects($this->once())
            ->method('extract')
            ->with($entity, [])
            ->willReturn(['name' => 'Test Name']);

        $service = new AuditService(
            $this->entityManager,
            $this->userResolver,
            $this->clock,
            $this->transactionIdGenerator,
            $dataExtractor,
            $this->metadataCache,
            ['ignoredField'],
            $this->logger
        );

        $data = $service->getEntityData($entity);

        self::assertEquals(['name' => 'Test Name'], $data);
    }

    public function testCreateAuditLog(): void
    {
        $entity = new TestEntity();

        // Mock metadata for getEntityId
        $metadata = self::createStub(ClassMetadata::class);
        $metadata->method('getIdentifierValues')->willReturn(['id' => 123]);
        $this->entityManager->method('getClassMetadata')->willReturn($metadata);

        $this->userResolver->method('getUserId')->willReturn(123);
        $this->userResolver->method('getUsername')->willReturn('testuser');

        $now = new \DateTimeImmutable('2024-01-01 12:00:00');
        $this->clock->method('now')->willReturn($now);

        $log = $this->service->createAuditLog(
            $entity,
            AuditLog::ACTION_UPDATE,
            ['name' => 'Old'],
            ['name' => 'New']
        );

        self::assertEquals(TestEntity::class, $log->getEntityClass());
        self::assertEquals(AuditLog::ACTION_UPDATE, $log->getAction());
        self::assertEquals(['name' => 'Old'], $log->getOldValues());
        self::assertEquals(['name' => 'New'], $log->getNewValues());
        self::assertEquals(['name'], $log->getChangedFields());
        self::assertEquals(123, $log->getUserId());
        self::assertEquals('testuser', $log->getUsername());
        self::assertEquals($now, $log->getCreatedAt());
        self::assertEquals('test-transaction-id', $log->getTransactionHash());
    }

    public function testCompositeKeySerialization(): void
    {
        $entity = new TestEntity();
        $metadata = self::createStub(ClassMetadata::class);
        $metadata->method('getIdentifierValues')->willReturn(['id1' => 'uuid-1', 'id2' => 'uuid-2']);
        $this->entityManager->method('getClassMetadata')->willReturn($metadata);

        $id = $this->service->getEntityId($entity);

        self::assertEquals('["uuid-1","uuid-2"]', $id);
    }

    public function testSingleKeySerialization(): void
    {
        $entity = new TestEntity();
        $metadata = self::createStub(ClassMetadata::class);
        $metadata->method('getIdentifierValues')->willReturn(['id' => 41]);
        $this->entityManager->method('getClassMetadata')->willReturn($metadata);

        $id = $this->service->getEntityId($entity);

        self::assertEquals('41', $id);
    }

    public function testFloatComparisonWithEpsilon(): void
    {
        $reflection = new \ReflectionClass(AuditService::class);
        $method = $reflection->getMethod('valuesAreDifferent');
        $method->setAccessible(true);

        self::assertFalse($method->invoke($this->service, 1.0000000001, 1.0000000002));
        self::assertTrue($method->invoke($this->service, 1.0, 1.000001));
    }
}
