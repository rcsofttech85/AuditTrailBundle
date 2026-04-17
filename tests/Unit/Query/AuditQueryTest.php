<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Tests\Unit\Query;

use DateTimeImmutable;
use InvalidArgumentException;
use LogicException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Rcsofttech\AuditTrailBundle\Contract\AuditLogRepositoryInterface;
use Rcsofttech\AuditTrailBundle\Entity\AuditLog;
use Rcsofttech\AuditTrailBundle\Query\AuditQuery;
use ReflectionClass;
use Symfony\Component\Uid\Uuid;

final class AuditQueryTest extends TestCase
{
    private AuditLogRepositoryInterface $repository;

    private AuditQuery $query;

    protected function setUp(): void
    {
        $this->repository = self::createStub(AuditLogRepositoryInterface::class);
        $this->query = new AuditQuery($this->repository);
    }

    public function testImmutability(): void
    {
        $newQuery = $this->query->entity('App\Entity\User');

        self::assertNotSame($this->query, $newQuery);
    }

    public function testEntityFilters(): void
    {
        $repository = $this->useRepositoryMock();

        $repository->expects($this->once())
            ->method('findWithFilters')
            ->with(
                self::callback(static function (array $filters) {
                    return ($filters['entityClass'] ?? null) === 'App\Entity\User'
                        && ($filters['entityId'] ?? null) === '123';
                }),
                30
            )
            ->willReturn([]);

        $this->query
            ->entity('App\Entity\User', '123')
            ->getResults();
    }

    public function testActionAndUserFilters(): void
    {
        $repository = $this->useRepositoryMock();

        $repository->expects($this->once())
            ->method('findWithFilters')
            ->with(
                self::callback(static function (array $filters) {
                    return ($filters['action'] ?? null) === 'update'
                        && ($filters['userId'] ?? null) === '1';
                }),
                30
            )
            ->willReturn([]);

        $this->query
            ->action('update')
            ->user('1')
            ->getResults();
    }

    public function testTransactionAndPaginationFilters(): void
    {
        $repository = $this->useRepositoryMock();

        $afterId = Uuid::v7()->toString();

        $repository->expects($this->once())
            ->method('findWithFilters')
            ->with(
                self::callback(static function (array $filters) use ($afterId) {
                    return ($filters['transactionHash'] ?? null) === 'hash'
                        && ($filters['afterId'] ?? null) === $afterId
                        && !isset($filters['beforeId']);
                }),
                10
            )
            ->willReturn([]);

        $this->query
            ->transaction('hash')
            ->after($afterId)
            ->limit(10)
            ->getResults();
    }

    public function testDateFilters(): void
    {
        $repository = $this->useRepositoryMock();

        $repository->expects($this->once())
            ->method('findWithFilters')
            ->with(
                self::callback(static function (array $filters) {
                    return isset($filters['from']) && isset($filters['to']);
                }),
                30
            )
            ->willReturn([]);

        $this->query
            ->since(new DateTimeImmutable())
            ->until(new DateTimeImmutable())
            ->getResults();
    }

    public function testPaginationFilters(): void
    {
        $repository = $this->useRepositoryMock();

        $afterId = Uuid::v7()->toString();
        $beforeId = Uuid::v7()->toString();

        $callCount = 0;
        $repository->expects($this->exactly(2))
            ->method('findWithFilters')
            ->with(self::callback(static function (array $f) use (&$callCount, $afterId, $beforeId) {
                ++$callCount;
                if ($callCount === 1) {
                    return ($f['afterId'] ?? null) === $afterId && !isset($f['beforeId']);
                }

                return ($f['beforeId'] ?? null) === $beforeId && !isset($f['afterId']);
            }), 30)
            ->willReturn([]);

        $this->query->after($afterId)->getResults();
        $this->query->before($beforeId)->getResults();
    }

    public function testAfterRejectsInvalidCursor(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid audit cursor');

        $this->query->after('not-a-uuid');
    }

    public function testBeforeRejectsInvalidCursor(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid audit cursor');

        $this->query->before('not-a-uuid');
    }

    public function testLimitRejectsNonPositiveValues(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Limit must be greater than zero.');

        $this->query->limit(0);
    }

    private function setLogId(AuditLog $log, string $id): void
    {
        $reflection = new ReflectionClass($log);
        $property = $reflection->getProperty('id');
        $property->setValue($log, Uuid::fromString($id));
    }

    public function testConvenienceMethods(): void
    {
        $repository = $this->useRepositoryMock();

        $call = 0;
        $repository->expects($this->exactly(3))
            ->method('findWithFilters')
            ->with(self::callback(static function (array $filters) use (&$call): bool {
                ++$call;

                return match ($call) {
                    1 => ($filters['action'] ?? null) === 'create',
                    2 => ($filters['action'] ?? null) === 'update',
                    3 => ($filters['actions'] ?? null) === ['delete', 'soft_delete'],
                    default => false,
                };
            }), 30)
            ->willReturn([]);

        $this->query->creates()->getResults();
        $this->query->updates()->getResults();
        $this->query->deletes()->getResults();
    }

    public function testDeletesFiltersDeleteAndSoftDeleteActionsExactly(): void
    {
        $repository = $this->useRepositoryMock();

        $repository->expects($this->once())
            ->method('findWithFilters')
            ->with(self::callback(static fn (array $filters): bool => ($filters['actions'] ?? null) === ['delete', 'soft_delete']), 30)
            ->willReturn([]);

        $results = $this->query->deletes()->getResults();

        self::assertCount(0, $results);
    }

    public function testCountWithChangedFields(): void
    {
        $repository = $this->useRepositoryMock();

        $log1 = new AuditLog('Class', '1', 'create', changedFields: ['name']);
        $log2 = new AuditLog('Class', '2', 'update', changedFields: ['age']);

        $repository->expects($this->exactly(2))
            ->method('findWithFilters')
            ->willReturnOnConsecutiveCalls([$log1, $log2], [$log1, $log2]);

        // Filter by 'name'
        $count = $this->query->changedField('name')->count();
        self::assertSame(1, $count);

        // Filter by multiple fields
        $results = $this->query->changedField('name', 'age')->getResults();
        self::assertCount(2, $results);
    }

    public function testChangedFieldAppliesFilterBeforeLimit(): void
    {
        $repository = $this->useRepositoryMock();

        $nonMatch = new AuditLog('Class', '1', 'update', changedFields: ['title']);
        $match = new AuditLog('Class', '2', 'update', changedFields: ['status']);

        $repository->expects($this->once())
            ->method('findWithFilters')
            ->with(self::callback(static fn (array $filters): bool => !isset($filters['changedFields'])), 250)
            ->willReturn([$nonMatch, $match]);

        $results = $this->query
            ->changedField('status')
            ->limit(1)
            ->getResults();

        self::assertCount(1, $results);
        self::assertSame('2', $results->first()?->entityId);
    }

    public function testChangedFieldMatchesUsingLookupPerLog(): void
    {
        $repository = $this->useRepositoryMock();

        $repository->expects($this->once())
            ->method('findWithFilters')
            ->willReturn([
                new AuditLog('Class', '1', 'update', changedFields: ['name', 'status']),
                new AuditLog('Class', '2', 'update', changedFields: ['email']),
            ]);

        $results = $this->query->changedField('status')->getResults();

        self::assertCount(1, $results);
        self::assertSame('1', $results->first()?->entityId);
    }

    public function testChangedFieldExistsChecksBeyondDefaultPage(): void
    {
        $repository = $this->useRepositoryMock();

        $nonMatch = new AuditLog('Class', '1', 'update', changedFields: ['title']);
        $match = new AuditLog('Class', '2', 'update', changedFields: ['status']);

        $repository->expects($this->once())
            ->method('findWithFilters')
            ->with(self::callback(static fn (array $filters): bool => !isset($filters['changedFields'])), 250)
            ->willReturn([$nonMatch, $match]);

        self::assertTrue($this->query->changedField('status')->exists());
    }

    public function testChangedFieldRejectsReversePagination(): void
    {
        $beforeId = Uuid::v7()->toString();

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Reverse pagination with changedField() is not supported.');

        $this->query->changedField('status')->before($beforeId);
    }

    public function testChangedFieldRejectsReversePaginationWhenCursorSetFirst(): void
    {
        $beforeId = Uuid::v7()->toString();

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Reverse pagination with changedField() is not supported.');

        $this->query->before($beforeId)->changedField('status');
    }

    public function testGetFirstResult(): void
    {
        $repository = $this->useRepositoryMock();

        $uuid1 = Uuid::v7()->toString();
        $log1 = new AuditLog('Class', '1', 'create');
        $this->setLogId($log1, $uuid1);
        $log2 = new AuditLog('Class', '2', 'create');
        $this->setLogId($log2, Uuid::v7()->toString());

        // Should call findWithFilters with limit 1
        $repository->expects($this->once())
            ->method('findWithFilters')
            ->with(self::anything(), 1)
            ->willReturn([$log1]);

        $result = $this->query->getFirstResult();
        self::assertNotNull($result);
        self::assertNotNull($result->id);
        self::assertSame($uuid1, $result->id->toString());
    }

    public function testExists(): void
    {
        $this->repository = self::createStub(AuditLogRepositoryInterface::class);
        $this->repository->method('findWithFilters')->willReturn([new AuditLog('Class', '1', 'create')]);
        $this->query = new AuditQuery($this->repository);
        self::assertTrue($this->query->exists());

        $this->repository = self::createStub(AuditLogRepositoryInterface::class);
        $this->query = new AuditQuery($this->repository);
        $this->repository->method('findWithFilters')->willReturn([]);
        self::assertFalse($this->query->exists());
    }

    public function testGetNextCursor(): void
    {
        $this->repository = self::createStub(AuditLogRepositoryInterface::class);
        $this->query = new AuditQuery($this->repository);

        $uuid1 = Uuid::v7()->toString();
        $log1 = new AuditLog('Class', '1', 'create');
        $this->setLogId($log1, $uuid1);
        $uuid2 = Uuid::v7()->toString();
        $log2 = new AuditLog('Class', '2', 'create');
        $this->setLogId($log2, $uuid2);

        $repository = self::createStub(AuditLogRepositoryInterface::class);
        $repository->method('findWithFilters')->willReturn([$log1, $log2]);
        $this->repository = $repository;
        $this->query = new AuditQuery($repository);

        // getNextCursor returns the ID of the LAST result
        self::assertSame($uuid2, $this->query->getNextCursor());

        // Empty results should return null
        $this->repository = self::createStub(AuditLogRepositoryInterface::class);
        $this->query = new AuditQuery($this->repository);
        $this->repository->method('findWithFilters')->willReturn([]);
        self::assertNull($this->query->getNextCursor());
    }

    public function testEntityIdMethod(): void
    {
        $repository = $this->useRepositoryMock();

        $repository->expects($this->once())
            ->method('findWithFilters')
            ->with(self::callback(static fn (array $f) => ($f['entityId'] ?? null) === '123'), 30)
            ->willReturn([]);

        $this->query->entityId('123')->getResults();
    }

    public function testBetween(): void
    {
        $repository = $this->useRepositoryMock();

        $repository->expects($this->once())
            ->method('findWithFilters')
            ->with(self::callback(static fn (array $f) => isset($f['from']) && isset($f['to'])), 30)
            ->willReturn([]);

        $this->query->between(new DateTimeImmutable(), new DateTimeImmutable())->getResults();
    }

    private function useRepositoryMock(): AuditLogRepositoryInterface&MockObject
    {
        $repository = $this->createMock(AuditLogRepositoryInterface::class);
        $this->repository = $repository;
        $this->query = new AuditQuery($repository);

        return $repository;
    }
}
