<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Tests\Unit\Query;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Rcsofttech\AuditTrailBundle\Query\AuditReader;
use Rcsofttech\AuditTrailBundle\Repository\AuditLogRepository;

#[AllowMockObjectsWithoutExpectations]
class AuditReaderTest extends TestCase
{
    private AuditLogRepository&MockObject $repository;
    private EntityManagerInterface&MockObject $entityManager;
    private AuditReader $reader;

    protected function setUp(): void
    {
        $this->repository = $this->createMock(AuditLogRepository::class);
        $this->entityManager = $this->createMock(EntityManagerInterface::class);
        $this->reader = new AuditReader($this->repository, $this->entityManager);
    }

    public function testCreateQuery(): void
    {
        $this->reader->createQuery();
        $this->expectNotToPerformAssertions();
    }

    public function testForEntity(): void
    {
        $this->reader->forEntity('App\Entity\User', '123');
        $this->expectNotToPerformAssertions();
    }

    public function testByUser(): void
    {
        $this->reader->byUser(1);
        $this->expectNotToPerformAssertions();
    }

    public function testByTransaction(): void
    {
        $this->reader->byTransaction('hash');
        $this->expectNotToPerformAssertions();
    }

    public function testGetHistoryForWithMetadata(): void
    {
        $entity = new \stdClass();
        $metadata = $this->createMock(ClassMetadata::class);
        $metadata->method('getIdentifierValues')->with($entity)->willReturn(['id' => 123]);

        $this->entityManager->method('getClassMetadata')->with(\stdClass::class)->willReturn($metadata);

        $this->repository->expects($this->once())
            ->method('findWithFilters')
            ->with(self::callback(fn ($f) => 'stdClass' === $f['entityClass'] && '123' === $f['entityId']), 30)
            ->willReturn([]);

        $this->reader->getHistoryFor($entity);
    }

    public function testGetHistoryForWithCompositeId(): void
    {
        $entity = new \stdClass();
        $metadata = $this->createMock(ClassMetadata::class);
        $metadata->method('getIdentifierValues')->with($entity)->willReturn(['id1' => 1, 'id2' => 2]);

        $this->entityManager->method('getClassMetadata')->with(\stdClass::class)->willReturn($metadata);

        $this->repository->expects($this->once())
            ->method('findWithFilters')
            ->with(self::callback(fn ($f) => 'stdClass' === $f['entityClass'] && '["1","2"]' === $f['entityId']), 30)
            ->willReturn([]);

        $this->reader->getHistoryFor($entity);
    }

    public function testGetHistoryForFallbackToGetId(): void
    {
        $entity = new class () {
            public function getId(): int
            {
                return 456;
            }
        };

        // Simulate metadata failure
        $this->entityManager->method('getClassMetadata')->willThrowException(new \Exception());

        $this->repository->expects($this->once())
            ->method('findWithFilters')
            ->with(self::callback(fn ($f) => '456' === $f['entityId']), 30)
            ->willReturn([]);

        $this->reader->getHistoryFor($entity);
    }

    public function testGetHistoryForNoId(): void
    {
        $entity = new \stdClass();
        $this->entityManager->method('getClassMetadata')->willThrowException(new \Exception());

        $this->repository->expects($this->never())->method('findWithFilters');

        $collection = $this->reader->getHistoryFor($entity);
        self::assertCount(0, $collection);
    }

    public function testGetTimelineFor(): void
    {
        $entity = new \stdClass();
        $metadata = $this->createMock(ClassMetadata::class);
        $metadata->method('getIdentifierValues')->with($entity)->willReturn(['id' => 123]);
        $this->entityManager->method('getClassMetadata')->willReturn($metadata);

        $log = new \Rcsofttech\AuditTrailBundle\Entity\AuditLog();
        $log->setTransactionHash('tx1');

        $this->repository->method('findWithFilters')->willReturn([$log]);

        $timeline = $this->reader->getTimelineFor($entity);
        self::assertArrayHasKey('tx1', $timeline);
        self::assertCount(1, $timeline['tx1']);
    }

    public function testGetLatestFor(): void
    {
        $entity = new \stdClass();
        $metadata = $this->createMock(ClassMetadata::class);
        $metadata->method('getIdentifierValues')->with($entity)->willReturn(['id' => 123]);
        $this->entityManager->method('getClassMetadata')->willReturn($metadata);

        $log = new \Rcsofttech\AuditTrailBundle\Entity\AuditLog();
        $this->repository->method('findWithFilters')->willReturn([$log]);

        $result = $this->reader->getLatestFor($entity);
        self::assertNotNull($result);
    }

    public function testHasHistoryFor(): void
    {
        $entity = new \stdClass();
        $metadata = $this->createMock(ClassMetadata::class);
        $metadata->method('getIdentifierValues')->with($entity)->willReturn(['id' => 123]);
        $this->entityManager->method('getClassMetadata')->willReturn($metadata);

        $this->repository->method('findWithFilters')->willReturn([new \Rcsofttech\AuditTrailBundle\Entity\AuditLog()]);

        self::assertTrue($this->reader->hasHistoryFor($entity));
    }
}
