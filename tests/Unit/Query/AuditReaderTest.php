<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Tests\Unit\Query;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Rcsofttech\AuditTrailBundle\Entity\AuditLog;
use Rcsofttech\AuditTrailBundle\Query\AuditEntryCollection;
use Rcsofttech\AuditTrailBundle\Query\AuditQuery;
use Rcsofttech\AuditTrailBundle\Query\AuditReader;
use Rcsofttech\AuditTrailBundle\Repository\AuditLogRepository;

#[CoversClass(AuditReader::class)]
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

    public function testCreateQueryReturnsAuditQuery(): void
    {
        $query = $this->reader->createQuery();

        // Verify the query has expected methods (proves it's an AuditQuery)
        self::assertSame(AuditQuery::class, $query::class);
    }

    public function testForEntityReturnsPreFilteredQuery(): void
    {
        $this->repository
            ->expects($this->once())
            ->method('findWithFilters')
            ->with(
                self::callback(function (array $filters) {
                    return 'App\\Entity\\User' === $filters['entityClass']
                        && '123' === $filters['entityId'];
                }),
                self::anything()
            )
            ->willReturn([]);

        $this->reader->forEntity('App\\Entity\\User', '123')->getResults();
    }

    public function testByUserReturnsPreFilteredQuery(): void
    {
        $this->repository
            ->expects($this->once())
            ->method('findWithFilters')
            ->with(
                self::callback(function (array $filters) {
                    return 42 === $filters['userId'];
                }),
                self::anything()
            )
            ->willReturn([]);

        $this->reader->byUser(42)->getResults();
    }

    public function testByTransactionReturnsPreFilteredQuery(): void
    {
        $this->repository
            ->expects($this->once())
            ->method('findWithFilters')
            ->with(
                self::callback(function (array $filters) {
                    return 'abc123' === $filters['transactionHash'];
                }),
                self::anything()
            )
            ->willReturn([]);

        $this->reader->byTransaction('abc123')->getResults();
    }

    public function testGetHistoryForExtractsEntityIdAndQueries(): void
    {
        $entity = new class () {
            public function getId(): int
            {
                return 42;
            }
        };

        $metadata = $this->createMock(ClassMetadata::class);
        $metadata->expects($this->once())
            ->method('getIdentifierValues')
            ->with($entity)
            ->willReturn(['id' => 42]);

        $this->entityManager
            ->expects($this->once())
            ->method('getClassMetadata')
            ->willReturn($metadata);

        $this->repository
            ->expects($this->once())
            ->method('findWithFilters')
            ->with(
                self::callback(function (array $filters) use ($entity) {
                    return $entity::class === $filters['entityClass']
                        && '42' === $filters['entityId'];
                }),
                self::anything()
            )
            ->willReturn([]);

        $result = $this->reader->getHistoryFor($entity);

        self::assertSame(AuditEntryCollection::class, $result::class);
    }

    public function testGetHistoryForReturnsEmptyCollectionWhenNoId(): void
    {
        $entity = new class () {
            public function getId(): mixed
            {
                return null;
            }
        };

        $metadata = $this->createMock(ClassMetadata::class);
        $metadata->expects($this->once())
            ->method('getIdentifierValues')
            ->with($entity)
            ->willReturn([]);

        $this->entityManager
            ->expects($this->once())
            ->method('getClassMetadata')
            ->willReturn($metadata);

        $result = $this->reader->getHistoryFor($entity);

        self::assertSame(AuditEntryCollection::class, $result::class);
        self::assertTrue($result->isEmpty());
    }

    public function testGetLatestForReturnsLatestEntry(): void
    {
        $entity = new class () {
            public function getId(): int
            {
                return 42;
            }
        };

        $metadata = $this->createMock(ClassMetadata::class);
        $metadata->expects($this->once())
            ->method('getIdentifierValues')
            ->with($entity)
            ->willReturn(['id' => 42]);

        $this->entityManager
            ->expects($this->once())
            ->method('getClassMetadata')
            ->willReturn($metadata);

        $log = new AuditLog();
        $log->setEntityClass($entity::class);
        $log->setEntityId('42');
        $log->setAction(AuditLog::ACTION_UPDATE);

        $this->repository
            ->expects($this->once())
            ->method('findWithFilters')
            ->willReturn([$log]);

        $result = $this->reader->getLatestFor($entity);

        self::assertNotNull($result);
        self::assertTrue($result->isUpdate());
    }

    public function testHasHistoryForReturnsTrue(): void
    {
        $entity = new class () {
            public function getId(): int
            {
                return 42;
            }
        };

        $metadata = $this->createMock(ClassMetadata::class);
        $metadata->expects($this->once())
            ->method('getIdentifierValues')
            ->with($entity)
            ->willReturn(['id' => 42]);

        $this->entityManager
            ->expects($this->once())
            ->method('getClassMetadata')
            ->willReturn($metadata);

        $log = new AuditLog();
        $log->setEntityClass($entity::class);
        $log->setEntityId('42');
        $log->setAction(AuditLog::ACTION_CREATE);

        $this->repository
            ->expects($this->once())
            ->method('findWithFilters')
            ->willReturn([$log]);

        self::assertTrue($this->reader->hasHistoryFor($entity));
    }

    public function testHasHistoryForReturnsFalse(): void
    {
        $entity = new class () {
            public function getId(): int
            {
                return 42;
            }
        };

        $metadata = $this->createMock(ClassMetadata::class);
        $metadata->expects($this->once())
            ->method('getIdentifierValues')
            ->with($entity)
            ->willReturn(['id' => 42]);

        $this->entityManager
            ->expects($this->once())
            ->method('getClassMetadata')
            ->willReturn($metadata);

        $this->repository
            ->expects($this->once())
            ->method('findWithFilters')
            ->willReturn([]);

        self::assertFalse($this->reader->hasHistoryFor($entity));
    }

    public function testGetTimelineForGroupsByTransaction(): void
    {
        $entity = new class () {
            public function getId(): int
            {
                return 42;
            }
        };

        $metadata = $this->createMock(ClassMetadata::class);
        $metadata->expects($this->once())
            ->method('getIdentifierValues')
            ->with($entity)
            ->willReturn(['id' => 42]);

        $this->entityManager
            ->expects($this->once())
            ->method('getClassMetadata')
            ->willReturn($metadata);

        $log1 = new AuditLog();
        $log1->setEntityClass($entity::class);
        $log1->setEntityId('42');
        $log1->setAction(AuditLog::ACTION_CREATE);
        $log1->setTransactionHash('tx1');

        $log2 = new AuditLog();
        $log2->setEntityClass($entity::class);
        $log2->setEntityId('42');
        $log2->setAction(AuditLog::ACTION_UPDATE);
        $log2->setTransactionHash('tx1');

        $log3 = new AuditLog();
        $log3->setEntityClass($entity::class);
        $log3->setEntityId('42');
        $log3->setAction(AuditLog::ACTION_UPDATE);
        $log3->setTransactionHash('tx2');

        $this->repository
            ->expects($this->once())
            ->method('findWithFilters')
            ->willReturn([$log1, $log2, $log3]);

        $timeline = $this->reader->getTimelineFor($entity);

        self::assertArrayHasKey('tx1', $timeline);
        self::assertArrayHasKey('tx2', $timeline);
        self::assertCount(2, $timeline['tx1']);
        self::assertCount(1, $timeline['tx2']);
    }
}
