<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Tests\Unit\Repository;

use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Query;
use Doctrine\ORM\QueryBuilder;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Rcsofttech\AuditTrailBundle\Entity\AuditLog;
use Rcsofttech\AuditTrailBundle\Repository\AuditLogRepository;
use ReflectionClass;
use Symfony\Bridge\Doctrine\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

#[AllowMockObjectsWithoutExpectations]
class AuditLogRepositoryTest extends TestCase
{
    private AuditLogRepository $repository;

    private EntityManagerInterface&MockObject $entityManager;

    /** @var ClassMetadata<AuditLog>&MockObject */
    private ClassMetadata&MockObject $classMetadata;

    private ManagerRegistry&MockObject $registry;

    private QueryBuilder&MockObject $qb;

    /** @var Query<mixed>&MockObject */
    private Query&MockObject $query;

    protected function setUp(): void
    {
        $this->entityManager = $this->createMock(EntityManagerInterface::class);
        $this->classMetadata = $this->createMock(ClassMetadata::class);
        $this->registry = $this->createMock(ManagerRegistry::class);
        $this->qb = $this->createMock(QueryBuilder::class);
        $this->query = $this->createMock(Query::class);

        $this->registry->method('getManagerForClass')->willReturn($this->entityManager);
        $this->entityManager->method('getClassMetadata')->willReturn($this->classMetadata);
        $this->classMetadata->name = AuditLog::class;

        // Mock QB creation
        $this->entityManager->method('createQueryBuilder')->willReturn($this->qb);

        // Common QB chain for ServiceEntityRepository::createQueryBuilder
        $this->qb->method('select')->willReturnSelf();
        $this->qb->method('from')->willReturnSelf();

        $this->repository = new AuditLogRepository($this->registry);
    }

    private function setupQueryBuilderDefaults(bool $mockGetResult = true): void
    {
        $this->qb->method('where')->willReturnSelf();
        $this->qb->method('andWhere')->willReturnSelf();
        $this->qb->method('setParameter')->willReturnSelf();
        $this->qb->method('orderBy')->willReturnSelf();
        $this->qb->method('setMaxResults')->willReturnSelf();
        $this->qb->method('getQuery')->willReturn($this->query);

        if ($mockGetResult) {
            $this->query->method('getResult')->willReturn([]);
        }
    }

    public function testFindByEntity(): void
    {
        $this->setupQueryBuilderDefaults();

        $this->qb->expects($this->once())->method('where')->with('a.entityClass = :class')->willReturnSelf();
        $this->qb->expects($this->once())->method('andWhere')->with('a.entityId = :id')->willReturnSelf();
        $this->qb->expects($this->exactly(2))->method('setParameter')->willReturnSelf();

        $this->repository->findByEntity('Class', '1');
    }

    public function testFindByTransactionHash(): void
    {
        $this->setupQueryBuilderDefaults();

        $this->qb->expects($this->once())->method('where')->with('a.transactionHash = :hash')->willReturnSelf();
        $this->qb->expects($this->once())->method('setParameter')->with('hash', 'tx1')->willReturnSelf();

        $this->repository->findByTransactionHash('tx1');
    }

    public function testFindByUser(): void
    {
        $this->setupQueryBuilderDefaults();

        $this->qb->expects($this->once())->method('where')->with('a.userId = :userId')->willReturnSelf();
        $this->qb->expects($this->once())->method('setParameter')->with('userId', 1)->willReturnSelf();
        $this->qb->expects($this->once())->method('setMaxResults')->with(10)->willReturnSelf();

        $this->repository->findByUser('1', 10);
    }

    public function testDeleteOldLogs(): void
    {
        $this->setupQueryBuilderDefaults();

        $this->qb->expects($this->once())->method('delete')->willReturnSelf();
        $this->qb->expects($this->once())->method('where')->with('a.createdAt < :before')->willReturnSelf();
        $this->query->method('execute')->willReturn(5);

        self::assertEquals(5, $this->repository->deleteOldLogs(new DateTimeImmutable()));
    }

    public function testFindWithFiltersAll(): void
    {
        $this->setupQueryBuilderDefaults();

        $filters = [
            'entityClass' => 'Class',
            'entityId' => '1',
            'userId' => 2,
            'action' => 'create',
            'transactionHash' => 'tx1',
            'from' => new DateTimeImmutable(),
            'to' => new DateTimeImmutable(),
            'afterId' => Uuid::v4()->toString(),
        ];

        // Verify filter application
        $this->qb->expects($this->exactly(8))->method('andWhere');

        $this->repository->findWithFilters($filters);
    }

    public function testFindWithFiltersPaginationBackwards(): void
    {
        $this->setupQueryBuilderDefaults(false); // Don't mock getResult yet

        $filters = ['beforeId' => Uuid::v4()->toString()];

        $this->qb->expects($this->once())->method('andWhere')->with('a.id > :beforeId');
        $this->qb->expects($this->once())->method('orderBy')->with('a.id', 'ASC');

        $log = new AuditLog('Class', '1', 'create');
        $this->query->method('getResult')->willReturn([$log]);

        $result = $this->repository->findWithFilters($filters);
        self::assertCount(1, $result);
    }

    public function testFindWithFiltersPartialClass(): void
    {
        $this->setupQueryBuilderDefaults();

        $filters = ['entityClass' => 'Partial'];

        $this->qb->expects($this->once())->method('andWhere')->with('a.entityClass LIKE :entityClass');

        $this->repository->findWithFilters($filters);
    }

    public function testFindWithFiltersPaginationBackwardsReversed(): void
    {
        $this->setupQueryBuilderDefaults(false);

        $uuid = Uuid::v4()->toString();
        $filters = ['beforeId' => $uuid];

        $log1 = new AuditLog('Class', '1', 'create');
        $this->setLogId($log1, Uuid::v4()->toString());
        $log2 = new AuditLog('Class', '2', 'create');
        $this->setLogId($log2, Uuid::v4()->toString());

        // Results from DB will be ASC: [11, 12]
        $this->query->method('getResult')->willReturn([$log1, $log2]);

        $result = $this->repository->findWithFilters($filters);

        // Should be reversed to DESC: [12, 11]
        self::assertCount(2, $result);
        self::assertSame($log2, $result[0]);
        self::assertSame($log1, $result[1]);
    }

    public function testFindWithFiltersDefaultLimit(): void
    {
        $this->setupQueryBuilderDefaults();

        $this->qb->expects($this->once())->method('setMaxResults')->with(30)->willReturnSelf();

        $this->repository->findWithFilters([]);
    }

    public function testFindWithFiltersFQCN(): void
    {
        $this->setupQueryBuilderDefaults();

        $filters = ['entityClass' => AuditLog::class];

        $this->qb->expects($this->once())->method('andWhere')->with('a.entityClass = :entityClass');
        $this->qb->expects($this->once())->method('setParameter')->with('entityClass', AuditLog::class);

        $this->repository->findWithFilters($filters);
    }

    public function testFindWithFiltersShortName(): void
    {
        $this->setupQueryBuilderDefaults();

        $filters = ['entityClass' => 'AuditLog'];

        $this->qb->expects($this->once())->method('andWhere')->with('a.entityClass LIKE :entityClass');
        $this->qb->expects($this->once())->method('setParameter')->with('entityClass', '%AuditLog%');

        $this->repository->findWithFilters($filters);
    }

    public function testFindWithFiltersShortNameWithWildcards(): void
    {
        $this->setupQueryBuilderDefaults();

        $filters = ['entityClass' => 'Audit%_Log'];

        $this->qb->expects($this->once())->method('andWhere')->with('a.entityClass LIKE :entityClass');
        // Should be escaped as \% and \_
        $this->qb->expects($this->once())->method('setParameter')->with('entityClass', '%Audit\%\_Log%');

        $this->repository->findWithFilters($filters);
    }

    public function testFindByUserReturnsAllResults(): void
    {
        $this->setupQueryBuilderDefaults(false);

        $log1 = new AuditLog('Class', '1', 'create');
        $log2 = new AuditLog('Class', '2', 'create');
        $this->query->method('getResult')->willReturn([$log1, $log2]);

        $result = $this->repository->findByUser('1');
        self::assertCount(2, $result);
        self::assertSame($log1, $result[0]);
        self::assertSame($log2, $result[1]);
    }

    public function testFindByEntityReturnsAllResults(): void
    {
        $this->setupQueryBuilderDefaults(false);

        $log1 = new AuditLog('Class', '1', 'create');
        $log2 = new AuditLog('Class', '1', 'update');
        $this->query->method('getResult')->willReturn([$log1, $log2]);

        $result = $this->repository->findByEntity('Class', '1');
        self::assertCount(2, $result);
    }

    public function testFindByTransactionHashReturnsAllResults(): void
    {
        $this->setupQueryBuilderDefaults(false);

        $log1 = new AuditLog('Class', '1', 'create');
        $log2 = new AuditLog('Class', '2', 'update');
        $this->query->method('getResult')->willReturn([$log1, $log2]);

        $result = $this->repository->findByTransactionHash('tx1');
        self::assertCount(2, $result);
    }

    public function testCountOlderThan(): void
    {
        $this->setupQueryBuilderDefaults();

        // select is called with 'a' by createQueryBuilder, then 'COUNT(a.id)' by countOlderThan
        $this->qb->expects($this->exactly(2))
            ->method('select')
            ->willReturnCallback(function ($arg) {
                return $this->qb;
            });

        $this->qb->expects($this->once())->method('where')->with('a.createdAt < :before')->willReturnSelf();
        $this->query->method('getSingleScalarResult')->willReturn(10);

        self::assertEquals(10, $this->repository->countOlderThan(new DateTimeImmutable()));
    }

    private function setLogId(AuditLog $log, string $id): void
    {
        $reflection = new ReflectionClass($log);
        $property = $reflection->getProperty('id');
        $property->setValue($log, Uuid::fromString($id));
    }
}
