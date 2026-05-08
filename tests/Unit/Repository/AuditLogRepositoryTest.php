<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Tests\Unit\Repository;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Platforms\MySQL80Platform;
use Doctrine\DBAL\Query\QueryBuilder as DbalQueryBuilder;
use Doctrine\DBAL\Result;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Query;
use Doctrine\ORM\QueryBuilder;
use InvalidArgumentException;
use LogicException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Rcsofttech\AuditTrailBundle\Entity\AuditLog;
use Rcsofttech\AuditTrailBundle\Enum\AuditAction;
use Rcsofttech\AuditTrailBundle\Repository\AuditLogChangedFieldConstraintBuilder;
use Rcsofttech\AuditTrailBundle\Repository\AuditLogChangedFieldQueryExecutor;
use Rcsofttech\AuditTrailBundle\Repository\AuditLogDbalFilterApplier;
use Rcsofttech\AuditTrailBundle\Repository\AuditLogEntityFetcher;
use Rcsofttech\AuditTrailBundle\Repository\AuditLogMetadataResolver;
use Rcsofttech\AuditTrailBundle\Repository\AuditLogQueryFilterApplier;
use Rcsofttech\AuditTrailBundle\Repository\AuditLogRepository;
use ReflectionClass;
use Symfony\Bridge\Doctrine\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

final class AuditLogRepositoryTest extends TestCase
{
    private function createRepository(): AuditLogRepository
    {
        $entityManager = self::createStub(EntityManagerInterface::class);
        $classMetadata = self::createStub(ClassMetadata::class);
        $registry = self::createStub(ManagerRegistry::class);

        $registry->method('getManagerForClass')->willReturn($entityManager);
        $entityManager->method('getClassMetadata')->willReturn($classMetadata);
        $classMetadata->name = AuditLog::class;

        return $this->createRepositoryInstance($registry);
    }

    /**
     * @return array{
     *     0: AuditLogRepository,
     *     1: DbalQueryBuilder&MockObject,
     *     2: Result&Stub,
     *     3: QueryBuilder&Stub,
     *     4: Query<mixed>&Stub
     * }
     */
    private function createChangedFieldQueryHarness(AbstractPlatform $platform): array
    {
        $entityManager = self::createStub(EntityManagerInterface::class);
        $classMetadata = self::createStub(ClassMetadata::class);
        $registry = self::createStub(ManagerRegistry::class);
        $connection = self::createStub(Connection::class);
        $dbalQueryBuilder = self::createMock(DbalQueryBuilder::class);
        $result = self::createStub(Result::class);
        $ormQueryBuilder = self::createStub(QueryBuilder::class);
        $ormQuery = self::createStub(Query::class);

        $registry->method('getManagerForClass')->willReturn($entityManager);
        $entityManager->method('getClassMetadata')->willReturn($classMetadata);
        $entityManager->method('getConnection')->willReturn($connection);
        $entityManager->method('createQueryBuilder')->willReturn($ormQueryBuilder);

        $classMetadata->name = AuditLog::class;
        $classMetadata->method('getTableName')->willReturn('audit_log');
        $classMetadata->method('getTypeOfField')->willReturnCallback(
            static fn (string $field): ?string => $field === 'id' ? 'uuid' : null
        );
        $classMetadata->method('getColumnName')->willReturnCallback(
            static fn (string $field): string => match ($field) {
                'id' => 'id',
                'entityClass' => 'entity_class',
                'entityId' => 'entity_id',
                'userId' => 'user_id',
                'username' => 'username',
                'transactionHash' => 'transaction_hash',
                'action' => 'action',
                'createdAt' => 'created_at',
                'changedFields' => 'changed_fields',
                default => throw new InvalidArgumentException('Unexpected field "'.$field.'".'),
            }
        );

        $connection->method('createQueryBuilder')->willReturn($dbalQueryBuilder);
        $connection->method('getDatabasePlatform')->willReturn($platform);
        $connection->method('quoteSingleIdentifier')->willReturnCallback(static fn (string $identifier): string => $identifier);

        $dbalQueryBuilder->method('from')->willReturnSelf();
        $dbalQueryBuilder->method('andWhere')->willReturnSelf();
        $dbalQueryBuilder->method('setParameter')->willReturnSelf();
        $dbalQueryBuilder->method('select')->willReturnSelf();
        $dbalQueryBuilder->method('orderBy')->willReturnSelf();
        $dbalQueryBuilder->method('setMaxResults')->willReturnSelf();
        $dbalQueryBuilder->method('executeQuery')->willReturn($result);

        $ormQueryBuilder->method('select')->willReturnSelf();
        $ormQueryBuilder->method('from')->willReturnSelf();
        $ormQueryBuilder->method('andWhere')->willReturnSelf();
        $ormQueryBuilder->method('setParameter')->willReturnSelf();
        $ormQueryBuilder->method('getQuery')->willReturn($ormQuery);
        $ormQuery->method('getResult')->willReturn([]);

        return [$this->createRepositoryInstance($registry), $dbalQueryBuilder, $result, $ormQueryBuilder, $ormQuery];
    }

    /**
     * @return array{
     *     0: AuditLogRepository,
     *     1: Result&Stub,
     *     2: Query<mixed>&MockObject
     * }
     */
    private function createChangedFieldFindHarness(AbstractPlatform $platform): array
    {
        $entityManager = self::createStub(EntityManagerInterface::class);
        $classMetadata = self::createStub(ClassMetadata::class);
        $registry = self::createStub(ManagerRegistry::class);
        $connection = self::createStub(Connection::class);
        $dbalQueryBuilder = self::createStub(DbalQueryBuilder::class);
        $result = self::createStub(Result::class);
        $ormQueryBuilder = self::createStub(QueryBuilder::class);
        $ormQuery = self::createMock(Query::class);

        $registry->method('getManagerForClass')->willReturn($entityManager);
        $entityManager->method('getClassMetadata')->willReturn($classMetadata);
        $entityManager->method('getConnection')->willReturn($connection);
        $entityManager->method('createQueryBuilder')->willReturn($ormQueryBuilder);

        $classMetadata->name = AuditLog::class;
        $classMetadata->method('getTableName')->willReturn('audit_log');
        $classMetadata->method('getTypeOfField')->willReturnCallback(
            static fn (string $field): ?string => $field === 'id' ? 'uuid' : null
        );
        $classMetadata->method('getColumnName')->willReturnCallback(
            static fn (string $field): string => match ($field) {
                'id' => 'id',
                'entityClass' => 'entity_class',
                'entityId' => 'entity_id',
                'userId' => 'user_id',
                'username' => 'username',
                'transactionHash' => 'transaction_hash',
                'action' => 'action',
                'createdAt' => 'created_at',
                'changedFields' => 'changed_fields',
                default => throw new InvalidArgumentException('Unexpected field "'.$field.'".'),
            }
        );

        $connection->method('createQueryBuilder')->willReturn($dbalQueryBuilder);
        $connection->method('getDatabasePlatform')->willReturn($platform);
        $connection->method('quoteSingleIdentifier')->willReturnCallback(static fn (string $identifier): string => $identifier);

        $dbalQueryBuilder->method('from')->willReturnSelf();
        $dbalQueryBuilder->method('andWhere')->willReturnSelf();
        $dbalQueryBuilder->method('setParameter')->willReturnSelf();
        $dbalQueryBuilder->method('select')->willReturnSelf();
        $dbalQueryBuilder->method('orderBy')->willReturnSelf();
        $dbalQueryBuilder->method('setMaxResults')->willReturnSelf();
        $dbalQueryBuilder->method('executeQuery')->willReturn($result);

        $ormQueryBuilder->method('select')->willReturnSelf();
        $ormQueryBuilder->method('from')->willReturnSelf();
        $ormQueryBuilder->method('andWhere')->willReturnSelf();
        $ormQueryBuilder->method('setParameter')->willReturnSelf();
        $ormQueryBuilder->method('getQuery')->willReturn($ormQuery);

        return [$this->createRepositoryInstance($registry), $result, $ormQuery];
    }

    /**
     * @return array{
     *     0: AuditLogRepository,
     *     1: QueryBuilder&MockObject,
     *     2: Query<mixed>&Stub
     * }
     */
    private function createQueryHarness(bool $stubGetResult = true): array
    {
        $entityManager = self::createStub(EntityManagerInterface::class);
        $classMetadata = self::createStub(ClassMetadata::class);
        $registry = self::createStub(ManagerRegistry::class);
        $qb = self::createMock(QueryBuilder::class);
        $query = self::createStub(Query::class);

        $registry->method('getManagerForClass')->willReturn($entityManager);
        $entityManager->method('getClassMetadata')->willReturn($classMetadata);
        $classMetadata->name = AuditLog::class;
        $entityManager->method('createQueryBuilder')->willReturn($qb);

        $qb->method('select')->willReturnSelf();
        $qb->method('from')->willReturnSelf();
        $qb->method('where')->willReturnSelf();
        $qb->method('andWhere')->willReturnSelf();
        $qb->method('setParameter')->willReturnSelf();
        $qb->method('orderBy')->willReturnSelf();
        $qb->method('addOrderBy')->willReturnSelf();
        $qb->method('setMaxResults')->willReturnSelf();
        $qb->method('getQuery')->willReturn($query);

        if ($stubGetResult) {
            $query->method('getResult')->willReturn([]);
        }
        $query->method('toIterable')->willReturn([]);

        return [$this->createRepositoryInstance($registry), $qb, $query];
    }

    /**
     * @return array{
     *     0: AuditLogRepository,
     *     1: QueryBuilder&MockObject,
     *     2: Query<mixed>&MockObject
     * }
     */
    private function createQueryHarnessWithQueryMock(): array
    {
        $entityManager = self::createStub(EntityManagerInterface::class);
        $classMetadata = self::createStub(ClassMetadata::class);
        $registry = self::createStub(ManagerRegistry::class);
        $qb = self::createMock(QueryBuilder::class);
        $query = self::createMock(Query::class);

        $registry->method('getManagerForClass')->willReturn($entityManager);
        $entityManager->method('getClassMetadata')->willReturn($classMetadata);
        $classMetadata->name = AuditLog::class;
        $entityManager->method('createQueryBuilder')->willReturn($qb);

        $qb->method('select')->willReturnSelf();
        $qb->method('from')->willReturnSelf();
        $qb->method('where')->willReturnSelf();
        $qb->method('andWhere')->willReturnSelf();
        $qb->method('setParameter')->willReturnSelf();
        $qb->method('orderBy')->willReturnSelf();
        $qb->method('addOrderBy')->willReturnSelf();
        $qb->method('setMaxResults')->willReturnSelf();
        $qb->method('getQuery')->willReturn($query);

        return [$this->createRepositoryInstance($registry), $qb, $query];
    }

    private function createRepositoryInstance(ManagerRegistry $registry): AuditLogRepository
    {
        return new AuditLogRepository(
            $registry,
            new AuditLogQueryFilterApplier(),
            new AuditLogChangedFieldQueryExecutor(
                new AuditLogDbalFilterApplier(),
                new AuditLogChangedFieldConstraintBuilder(),
                new AuditLogMetadataResolver(),
                new AuditLogEntityFetcher(),
            ),
        );
    }

    public function testFindByEntity(): void
    {
        [$repository, $qb] = $this->createQueryHarness();

        $qb->expects($this->once())->method('where')->with('a.entityClass = :class')->willReturnSelf();
        $qb->expects($this->once())->method('andWhere')->with('a.entityId = :id')->willReturnSelf();
        $qb->expects($this->exactly(2))->method('setParameter')->willReturnSelf();
        $qb->expects($this->once())->method('orderBy')->with('a.createdAt', 'DESC')->willReturnSelf();
        $qb->expects($this->once())->method('addOrderBy')->with('a.id', 'DESC')->willReturnSelf();

        $repository->findByEntity('Class', '1');
    }

    public function testFindByTransactionHash(): void
    {
        [$repository, $qb] = $this->createQueryHarness();

        $qb->expects($this->once())->method('where')->with('a.transactionHash = :hash')->willReturnSelf();
        $qb->expects($this->once())->method('setParameter')->with('hash', 'tx1')->willReturnSelf();
        $qb->expects($this->once())->method('orderBy')->with('a.createdAt', 'DESC')->willReturnSelf();
        $qb->expects($this->once())->method('addOrderBy')->with('a.id', 'DESC')->willReturnSelf();

        $repository->findByTransactionHash('tx1');
    }

    public function testFindByUser(): void
    {
        [$repository, $qb] = $this->createQueryHarness();

        $qb->expects($this->once())->method('where')->with('a.userId = :userId')->willReturnSelf();
        $qb->expects($this->once())->method('setParameter')->with('userId', 1)->willReturnSelf();
        $qb->expects($this->once())->method('orderBy')->with('a.createdAt', 'DESC')->willReturnSelf();
        $qb->expects($this->once())->method('addOrderBy')->with('a.id', 'DESC')->willReturnSelf();
        $qb->expects($this->once())->method('setMaxResults')->with(10)->willReturnSelf();

        $repository->findByUser('1', 10);
    }

    public function testFindOlderThanUsesDeterministicOrdering(): void
    {
        [$repository, $qb] = $this->createQueryHarness();

        $qb->expects($this->once())->method('where')->with('a.createdAt < :before')->willReturnSelf();
        $qb->expects($this->once())->method('orderBy')->with('a.createdAt', 'ASC')->willReturnSelf();
        $qb->expects($this->once())->method('addOrderBy')->with('a.id', 'ASC')->willReturnSelf();

        $repository->findOlderThan(new DateTimeImmutable());
    }

    public function testDeleteOldLogs(): void
    {
        [$repository, $qb, $query] = $this->createQueryHarness();

        $qb->expects($this->once())->method('delete')->willReturnSelf();
        $qb->expects($this->once())->method('where')->with('a.createdAt < :before')->willReturnSelf();
        $query->method('execute')->willReturn(5);

        self::assertSame(5, $repository->deleteOldLogs(new DateTimeImmutable()));
    }

    public function testFindWithFiltersAll(): void
    {
        [$repository, $qb] = $this->createQueryHarness();

        $filters = [
            'entityClass' => 'Class',
            'entityId' => '1',
            'userId' => 2,
            'username' => 'admin',
            'action' => 'create',
            'transactionHash' => 'tx1',
            'from' => new DateTimeImmutable(),
            'to' => new DateTimeImmutable(),
            'afterId' => Uuid::v7()->toString(),
        ];

        // Verify filter application
        $qb->expects($this->exactly(9))->method('andWhere');

        $repository->findWithFilters($filters);
    }

    public function testFindWithFiltersUsername(): void
    {
        [$repository, $qb] = $this->createQueryHarness();

        $filters = ['username' => 'admin'];

        $qb->expects($this->once())->method('andWhere')->with('a.username = :username');
        $qb->expects($this->once())->method('setParameter')->with('username', 'admin');

        $repository->findWithFilters($filters);
    }

    public function testFindWithFiltersPaginationBackwards(): void
    {
        [$repository, $qb, $query] = $this->createQueryHarness(false);

        $filters = ['beforeId' => Uuid::v7()->toString()];

        $qb->expects($this->once())->method('andWhere')->with('a.id > :beforeId');
        $qb->expects($this->once())->method('orderBy')->with('a.id', 'ASC');

        $log = new AuditLog('Class', '1', 'create');
        $query->method('getResult')->willReturn([$log]);

        $result = $repository->findWithFilters($filters);
        self::assertCount(1, $result);
    }

    public function testFindWithFiltersPartialClass(): void
    {
        [$repository, $qb] = $this->createQueryHarness();

        $filters = ['entityClass' => 'Partial'];

        $qb->expects($this->once())->method('andWhere')->with('a.entityClass LIKE :entityClass');

        $repository->findWithFilters($filters);
    }

    public function testFindWithFiltersPaginationBackwardsReversed(): void
    {
        [$repository, $qb, $query] = $this->createQueryHarness(false);

        $uuid = Uuid::v7()->toString();
        $filters = ['beforeId' => $uuid];

        $log1 = new AuditLog('Class', '1', 'create');
        $this->setLogId($log1, Uuid::v7()->toString());
        $log2 = new AuditLog('Class', '2', 'create');
        $this->setLogId($log2, Uuid::v7()->toString());

        // Results from DB will be ASC: [11, 12]
        $qb->expects($this->once())->method('orderBy')->with('a.id', 'ASC')->willReturnSelf();
        $query->method('getResult')->willReturn([$log1, $log2]);

        $result = $repository->findWithFilters($filters);

        // Should be reversed to DESC: [12, 11]
        self::assertCount(2, $result);
        self::assertSame($log2, $result[0]);
        self::assertSame($log1, $result[1]);
    }

    public function testFindWithFiltersDefaultLimit(): void
    {
        [$repository, $qb] = $this->createQueryHarness();

        $qb->expects($this->once())->method('setMaxResults')->with(30)->willReturnSelf();

        $repository->findWithFilters([]);
    }

    public function testCountWithFiltersUsesDatabaseCountQuery(): void
    {
        [$repository, $qb, $query] = $this->createQueryHarness();

        $qb->expects($this->exactly(2))
            ->method('select')
            ->willReturnCallback(static function (string $select) use ($qb): QueryBuilder {
                static $expected = ['a', 'COUNT(a.id)'];
                static $index = 0;

                TestCase::assertSame($expected[$index], $select);
                ++$index;

                return $qb;
            });
        $qb->expects($this->once())->method('andWhere')->with('a.userId = :userId')->willReturnSelf();
        $query->method('getSingleScalarResult')->willReturn(12);

        self::assertSame(12, $repository->countWithFilters(['userId' => '1']));
    }

    public function testFindByUserRejectsNonPositiveLimit(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Limit must be greater than zero.');

        $repository = $this->createRepository();
        $repository->findByUser('1', 0);
    }

    public function testFindWithFiltersRejectsNonPositiveLimit(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Limit must be greater than zero.');

        $repository = $this->createRepository();
        $repository->findWithFilters([], 0);
    }

    public function testFindWithFiltersFQCN(): void
    {
        [$repository, $qb] = $this->createQueryHarness();

        $filters = ['entityClass' => AuditLog::class];

        $qb->expects($this->once())->method('andWhere')->with('a.entityClass = :entityClass');
        $qb->expects($this->once())->method('setParameter')->with('entityClass', AuditLog::class);

        $repository->findWithFilters($filters);
    }

    public function testFindWithFiltersShortName(): void
    {
        [$repository, $qb] = $this->createQueryHarness();

        $filters = ['entityClass' => 'AuditLog'];

        $qb->expects($this->once())->method('andWhere')->with('a.entityClass LIKE :entityClass');
        $qb->expects($this->once())->method('setParameter')->with('entityClass', '%AuditLog%');

        $repository->findWithFilters($filters);
    }

    public function testFindWithFiltersShortNameWithWildcards(): void
    {
        [$repository, $qb] = $this->createQueryHarness();

        $filters = ['entityClass' => 'Audit%_Log'];

        $qb->expects($this->once())->method('andWhere')->with('a.entityClass LIKE :entityClass');
        // Should be escaped as \% and \_
        $qb->expects($this->once())->method('setParameter')->with('entityClass', '%Audit\%\_Log%');

        $repository->findWithFilters($filters);
    }

    public function testCountOlderThan(): void
    {
        [$repository, $qb, $query] = $this->createQueryHarness();

        // select is called with 'a' by createQueryBuilder, then 'COUNT(a.id)' by countOlderThan
        $qb->expects($this->exactly(2))
            ->method('select')
            ->willReturnCallback(static function () use ($qb) {
                return $qb;
            });

        $qb->expects($this->once())->method('where')->with('a.createdAt < :before')->willReturnSelf();
        $query->method('getSingleScalarResult')->willReturn(10);

        self::assertSame(10, $repository->countOlderThan(new DateTimeImmutable()));
    }

    public function testCountWithChangedFieldsBindsDateFiltersAsDateTimeImmutable(): void
    {
        [$repository, $dbalQueryBuilder, $result] = $this->createChangedFieldQueryHarness(new MySQL80Platform());
        $from = new DateTimeImmutable('2024-01-01 00:00:00');
        $to = new DateTimeImmutable('2024-01-31 23:59:59');
        $parameters = [];

        $dbalQueryBuilder->expects($this->exactly(3))
            ->method('setParameter')
            ->willReturnCallback(static function (string $name, mixed $value, mixed $type = null) use (&$parameters, $dbalQueryBuilder): DbalQueryBuilder {
                $parameters[$name] = [$value, $type];

                return $dbalQueryBuilder;
            });
        $result->method('fetchOne')->willReturn('0');

        self::assertSame(0, $repository->countWithChangedFields([
            'from' => $from,
            'to' => $to,
        ], ['status']));
        self::assertSame([$from, Types::DATETIME_IMMUTABLE], $parameters['from']);
        self::assertSame([$to, Types::DATETIME_IMMUTABLE], $parameters['to']);
    }

    public function testCountWithChangedFieldsBindsCursorFiltersAsUuidType(): void
    {
        foreach (['afterId', 'beforeId'] as $filterKey) {
            [$repository, $dbalQueryBuilder, $result] = $this->createChangedFieldQueryHarness(new MySQL80Platform());
            $cursorId = Uuid::v7()->toRfc4122();
            $parameters = [];

            $dbalQueryBuilder->expects($this->exactly(2))
                ->method('setParameter')
                ->willReturnCallback(static function (string $name, mixed $value, mixed $type = null) use (&$parameters, $dbalQueryBuilder): DbalQueryBuilder {
                    $parameters[$name] = [$value, $type];

                    return $dbalQueryBuilder;
                });
            $result->method('fetchOne')->willReturn('0');

            self::assertSame(0, $repository->countWithChangedFields([$filterKey => $cursorId], ['status']));
            self::assertSame([$cursorId, 'uuid'], $parameters[$filterKey]);
        }
    }

    public function testCountWithChangedFieldsRejectsUnsupportedPlatforms(): void
    {
        [$repository, $dbalQueryBuilder] = $this->createChangedFieldQueryHarness(self::createStub(AbstractPlatform::class));

        $dbalQueryBuilder->expects($this->never())->method('executeQuery');

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Changed-field queries are only supported on MySQL, PostgreSQL, and SQLite.');

        $repository->countWithChangedFields([], ['status']);
    }

    public function testFindWithChangedFieldsMatchesBinaryUuidResultsAgainstHydratedLogs(): void
    {
        [$repository, $result, $ormQuery] = $this->createChangedFieldFindHarness(new MySQL80Platform());
        $uuid = Uuid::v7();
        $log = new AuditLog('Class', '1', 'update');
        $this->setLogId($log, $uuid->toRfc4122());

        $result->method('fetchFirstColumn')->willReturn([$uuid->toBinary()]);
        $ormQuery->expects($this->once())->method('getResult')->willReturn([$log]);

        self::assertSame([$log], $repository->findWithChangedFields([], ['status'], 10));
    }

    public function testFindWithChangedFieldsRejectsUnsupportedPlatforms(): void
    {
        [$repository, , $ormQuery] = $this->createChangedFieldFindHarness(self::createStub(AbstractPlatform::class));

        $ormQuery->expects($this->never())->method('getResult');

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Changed-field queries are only supported on MySQL, PostgreSQL, and SQLite.');

        $repository->findWithChangedFields([], ['status'], 10);
    }

    public function testIsReverted(): void
    {
        [$repository, $qb, $query] = $this->createQueryHarnessWithQueryMock();

        $log = new AuditLog('Class', '1', 'update');
        $this->setLogId($log, Uuid::v7()->toString());
        $qb->expects($this->exactly(2))
            ->method('select')
            ->willReturnCallback(static function (string $select) use ($qb): QueryBuilder {
                static $expected = ['a', 'COUNT(a.id)'];
                static $index = 0;

                TestCase::assertSame($expected[$index], $select);
                ++$index;

                return $qb;
            });
        $qb->expects($this->once())->method('where')->with('a.revertedLogId = :revertedLogId')->willReturnSelf();
        $qb->expects($this->once())->method('setParameter')->with('revertedLogId', $log->id?->toRfc4122())->willReturnSelf();
        $query->expects($this->once())->method('getSingleScalarResult')->willReturn(1);

        self::assertTrue($repository->isReverted($log));
    }

    public function testIsRevertedFalse(): void
    {
        [$repository, $qb, $query] = $this->createQueryHarnessWithQueryMock();

        $log = new AuditLog('Class', '1', 'update');
        $this->setLogId($log, Uuid::v7()->toString());
        $qb->expects($this->exactly(3))
            ->method('select')
            ->willReturnCallback(static function (string $select) use ($qb): QueryBuilder {
                static $expected = ['a', 'COUNT(a.id)', 'a'];
                static $index = 0;

                TestCase::assertSame($expected[$index], $select);
                ++$index;

                return $qb;
            });
        $qb->expects($this->exactly(2))
            ->method('where')
            ->willReturnCallback(static function (string $where) use ($qb): QueryBuilder {
                static $expected = ['a.revertedLogId = :revertedLogId', 'a.entityClass = :entityClass'];
                static $index = 0;

                TestCase::assertSame($expected[$index], $where);
                ++$index;

                return $qb;
            });
        $qb->expects($this->exactly(2))
            ->method('andWhere')
            ->willReturnCallback(static function (string $where) use ($qb): QueryBuilder {
                static $expected = ['a.entityId = :entityId', 'a.action = :action'];
                static $index = 0;

                TestCase::assertSame($expected[$index], $where);
                ++$index;

                return $qb;
            });
        $qb->expects($this->exactly(4))
            ->method('setParameter')
            ->willReturnCallback(static function (string $name, mixed $value) use ($qb, $log): QueryBuilder {
                static $expected = null;
                static $index = 0;

                $expected ??= [
                    ['revertedLogId', $log->id?->toRfc4122()],
                    ['entityClass', $log->entityClass],
                    ['entityId', $log->requireEntityId()],
                    ['action', AuditAction::Revert],
                ];

                TestCase::assertSame($expected[$index][0], $name);
                TestCase::assertSame($expected[$index][1], $value);
                ++$index;

                return $qb;
            });
        $qb->expects($this->once())->method('orderBy')->with('a.createdAt', 'DESC')->willReturnSelf();
        $qb->expects($this->once())->method('addOrderBy')->with('a.id', 'DESC')->willReturnSelf();
        $query->expects($this->once())->method('getSingleScalarResult')->willReturn(0);
        $query->expects($this->once())->method('toIterable')->willReturn([]);

        self::assertFalse($repository->isReverted($log));
    }

    public function testIsRevertedFallsBackToLegacyContextReference(): void
    {
        [$repository, $qb, $query] = $this->createQueryHarnessWithQueryMock();

        $log = new AuditLog('Class', '1', 'update');
        $this->setLogId($log, Uuid::v7()->toString());

        $legacyRevertLog = new AuditLog('Class', '1', AuditAction::Revert);
        $legacyRevertLog->context = ['reverted_log_id' => $log->id?->toRfc4122()];

        $qb->expects($this->exactly(3))
            ->method('select')
            ->willReturnCallback(static function (string $select) use ($qb): QueryBuilder {
                static $expected = ['a', 'COUNT(a.id)', 'a'];
                static $index = 0;

                TestCase::assertSame($expected[$index], $select);
                ++$index;

                return $qb;
            });
        $qb->expects($this->exactly(2))
            ->method('where')
            ->willReturnCallback(static function (string $where) use ($qb): QueryBuilder {
                static $expected = ['a.revertedLogId = :revertedLogId', 'a.entityClass = :entityClass'];
                static $index = 0;

                TestCase::assertSame($expected[$index], $where);
                ++$index;

                return $qb;
            });
        $qb->expects($this->exactly(2))
            ->method('andWhere')
            ->willReturnCallback(static function (string $where) use ($qb): QueryBuilder {
                static $expected = ['a.entityId = :entityId', 'a.action = :action'];
                static $index = 0;

                TestCase::assertSame($expected[$index], $where);
                ++$index;

                return $qb;
            });
        $qb->expects($this->exactly(4))
            ->method('setParameter')
            ->willReturnCallback(static function (string $name, mixed $value) use ($qb, $log): QueryBuilder {
                static $expected = null;
                static $index = 0;

                $expected ??= [
                    ['revertedLogId', $log->id?->toRfc4122()],
                    ['entityClass', $log->entityClass],
                    ['entityId', $log->requireEntityId()],
                    ['action', AuditAction::Revert],
                ];

                TestCase::assertSame($expected[$index][0], $name);
                TestCase::assertSame($expected[$index][1], $value);
                ++$index;

                return $qb;
            });
        $qb->expects($this->once())->method('orderBy')->with('a.createdAt', 'DESC')->willReturnSelf();
        $qb->expects($this->once())->method('addOrderBy')->with('a.id', 'DESC')->willReturnSelf();
        $query->expects($this->once())->method('getSingleScalarResult')->willReturn(0);
        $query->expects($this->once())->method('toIterable')->willReturn([$legacyRevertLog]);

        self::assertTrue($repository->isReverted($log));
    }

    public function testIsRevertedScansEntireLegacyRevertHistory(): void
    {
        [$repository, $qb, $query] = $this->createQueryHarnessWithQueryMock();

        $log = new AuditLog('Class', '1', 'update');
        $this->setLogId($log, Uuid::v7()->toString());

        $legacyRevertLogs = [];
        for ($i = 0; $i < 30; ++$i) {
            $legacyRevertLog = new AuditLog('Class', '1', AuditAction::Revert);
            if ($i === 29) {
                $legacyRevertLog->context = ['reverted_log_id' => $log->id?->toRfc4122()];
            }

            $legacyRevertLogs[] = $legacyRevertLog;
        }

        $qb->expects($this->exactly(3))
            ->method('select')
            ->willReturnCallback(static function (string $select) use ($qb): QueryBuilder {
                static $expected = ['a', 'COUNT(a.id)', 'a'];
                static $index = 0;

                TestCase::assertSame($expected[$index], $select);
                ++$index;

                return $qb;
            });
        $qb->expects($this->exactly(2))
            ->method('where')
            ->willReturnCallback(static function (string $where) use ($qb): QueryBuilder {
                static $expected = ['a.revertedLogId = :revertedLogId', 'a.entityClass = :entityClass'];
                static $index = 0;

                TestCase::assertSame($expected[$index], $where);
                ++$index;

                return $qb;
            });
        $qb->expects($this->exactly(2))
            ->method('andWhere')
            ->willReturnCallback(static function (string $where) use ($qb): QueryBuilder {
                static $expected = ['a.entityId = :entityId', 'a.action = :action'];
                static $index = 0;

                TestCase::assertSame($expected[$index], $where);
                ++$index;

                return $qb;
            });
        $qb->expects($this->exactly(4))
            ->method('setParameter')
            ->willReturnCallback(static function (string $name, mixed $value) use ($qb, $log): QueryBuilder {
                static $expected = null;
                static $index = 0;

                $expected ??= [
                    ['revertedLogId', $log->id?->toRfc4122()],
                    ['entityClass', $log->entityClass],
                    ['entityId', $log->requireEntityId()],
                    ['action', AuditAction::Revert],
                ];

                TestCase::assertSame($expected[$index][0], $name);
                TestCase::assertSame($expected[$index][1], $value);
                ++$index;

                return $qb;
            });
        $qb->expects($this->once())->method('orderBy')->with('a.createdAt', 'DESC')->willReturnSelf();
        $qb->expects($this->once())->method('addOrderBy')->with('a.id', 'DESC')->willReturnSelf();
        $qb->expects($this->never())->method('setMaxResults');
        $query->expects($this->once())->method('getSingleScalarResult')->willReturn(0);
        $query->expects($this->once())->method('toIterable')->willReturn($legacyRevertLogs);

        self::assertTrue($repository->isReverted($log));
    }

    public function testHasNewerStateChangingLogsReturnsTrueWhenAStateChangeAppearsBeforeTargetLog(): void
    {
        [$repository, $qb, $query] = $this->createQueryHarnessWithQueryMock();

        $olderUpdateLog = new AuditLog('Class', '1', 'update', new DateTimeImmutable('-10 minutes'));
        $this->setLogId($olderUpdateLog, Uuid::v7()->toString());

        $qb->expects($this->exactly(2))
            ->method('select')
            ->willReturnCallback(static function (string $select) use ($qb): QueryBuilder {
                static $expected = ['a', 'COUNT(a.id)'];
                static $index = 0;

                TestCase::assertSame($expected[$index], $select);
                ++$index;

                return $qb;
            });
        $qb->expects($this->once())->method('where')->with('a.entityClass = :entityClass')->willReturnSelf();
        $qb->expects($this->exactly(3))->method('andWhere')->willReturnSelf();
        $query->expects($this->once())->method('getSingleScalarResult')->willReturn(1);

        self::assertTrue($repository->hasNewerStateChangingLogs($olderUpdateLog));
    }

    public function testHasNewerStateChangingLogsReturnsFalseForLatestStateChangingLog(): void
    {
        [$repository, $qb, $query] = $this->createQueryHarnessWithQueryMock();

        $latestCreateLog = new AuditLog('Class', '1', 'create', new DateTimeImmutable('-5 minutes'));
        $this->setLogId($latestCreateLog, Uuid::v7()->toString());

        $qb->expects($this->exactly(2))
            ->method('select')
            ->willReturnCallback(static function (string $select) use ($qb): QueryBuilder {
                static $expected = ['a', 'COUNT(a.id)'];
                static $index = 0;

                TestCase::assertSame($expected[$index], $select);
                ++$index;

                return $qb;
            });
        $qb->expects($this->once())->method('where')->with('a.entityClass = :entityClass')->willReturnSelf();
        $qb->expects($this->exactly(3))->method('andWhere')->willReturnSelf();
        $query->expects($this->once())->method('getSingleScalarResult')->willReturn(0);

        self::assertFalse($repository->hasNewerStateChangingLogs($latestCreateLog));
    }

    public function testHasNewerStateChangingLogsReturnsFalseForLogWithoutId(): void
    {
        [$repository, $qb, $query] = $this->createQueryHarnessWithQueryMock();
        $log = new AuditLog('Class', '1', 'update');

        $qb->expects($this->never())->method('select');
        $query->expects($this->never())->method('getSingleScalarResult');

        self::assertFalse($repository->hasNewerStateChangingLogs($log));
    }

    private function setLogId(AuditLog $log, string $id): void
    {
        $reflection = new ReflectionClass($log);
        $property = $reflection->getProperty('id');
        $property->setValue($log, Uuid::fromString($id));
    }
}
