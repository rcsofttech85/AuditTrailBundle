<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Tests\Unit\Query;

use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Rcsofttech\AuditTrailBundle\Entity\AuditLog;
use Rcsofttech\AuditTrailBundle\Query\AuditQuery;
use Rcsofttech\AuditTrailBundle\Repository\AuditLogRepository;

#[AllowMockObjectsWithoutExpectations]
class AuditQueryTest extends TestCase
{
    private AuditLogRepository&MockObject $repository;
    private AuditQuery $query;

    protected function setUp(): void
    {
        $this->repository = $this->createMock(AuditLogRepository::class);
        $this->query = new AuditQuery($this->repository);
    }

    public function testImmutability(): void
    {
        $newQuery = $this->query->entity('App\Entity\User');

        self::assertNotSame($this->query, $newQuery);
    }

    public function testEntityFilters(): void
    {
        $this->repository->expects($this->once())
            ->method('findWithFilters')
            ->with(
                self::callback(function (array $filters) {
                    return 'App\Entity\User' === ($filters['entityClass'] ?? null)
                        && '123' === ($filters['entityId'] ?? null);
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
        $this->repository->expects($this->once())
            ->method('findWithFilters')
            ->with(
                self::callback(function (array $filters) {
                    return 'update' === ($filters['action'] ?? null)
                        && 1 === ($filters['userId'] ?? null);
                }),
                30
            )
            ->willReturn([]);

        $this->query
            ->action('update')
            ->user(1)
            ->getResults();
    }

    public function testTransactionAndPaginationFilters(): void
    {
        $this->repository->expects($this->once())
            ->method('findWithFilters')
            ->with(
                self::callback(function (array $filters) {
                    return 'hash' === ($filters['transactionHash'] ?? null)
                        && 10 === ($filters['afterId'] ?? null)
                        && !isset($filters['beforeId']);
                }),
                10
            )
            ->willReturn([]);

        $this->query
            ->transaction('hash')
            ->after(10)
            ->limit(10)
            ->getResults();
    }

    public function testDateFilters(): void
    {
        $this->repository->expects($this->once())
            ->method('findWithFilters')
            ->with(
                self::callback(function (array $filters) {
                    return isset($filters['from']) && isset($filters['to']);
                }),
                30
            )
            ->willReturn([]);

        $this->query
            ->since(new \DateTimeImmutable())
            ->until(new \DateTimeImmutable())
            ->getResults();
    }

    public function testPaginationFilters(): void
    {
        $callCount = 0;
        $this->repository->expects($this->exactly(2))
            ->method('findWithFilters')
            ->with(self::callback(function (array $f) use (&$callCount) {
                ++$callCount;
                if (1 === $callCount) {
                    return 10 === ($f['afterId'] ?? null) && !isset($f['beforeId']);
                }

                return 20 === ($f['beforeId'] ?? null) && !isset($f['afterId']);
            }), 30)
            ->willReturn([]);

        $this->query->after(10)->getResults();
        $this->query->before(20)->getResults();
    }

    private function setLogId(AuditLog $log, int $id): void
    {
        $reflection = new \ReflectionClass($log);
        $property = $reflection->getProperty('id');
        $property->setValue($log, $id);
    }

    public function testConvenienceMethods(): void
    {
        $this->repository->expects($this->exactly(3))
            ->method('findWithFilters')
            ->willReturn([]);

        $this->query->creates()->getResults();
        $this->query->updates()->getResults();
        $this->query->deletes()->getResults();
    }

    public function testCountWithChangedFields(): void
    {
        $log1 = $this->createMock(AuditLog::class);
        $log1->method('getChangedFields')->willReturn(['name']);

        $log2 = $this->createMock(AuditLog::class);
        $log2->method('getChangedFields')->willReturn(['age']);

        $this->repository->method('findWithFilters')->willReturn([$log1, $log2]);

        // Filter by 'name'
        $count = $this->query->changedField('name')->count();
        self::assertEquals(1, $count);

        // Filter by multiple fields
        $results = $this->query->changedField('name', 'age')->getResults();
        self::assertCount(2, $results);
    }

    public function testGetFirstResult(): void
    {
        $log1 = new AuditLog();
        $this->setLogId($log1, 1);
        $log2 = new AuditLog();
        $this->setLogId($log2, 2);

        // Should call findWithFilters with limit 1
        $this->repository->expects($this->once())
            ->method('findWithFilters')
            ->with(self::anything(), 1)
            ->willReturn([$log1]);

        $result = $this->query->getFirstResult();
        self::assertNotNull($result);
        self::assertEquals(1, $result->getId());
    }

    public function testExists(): void
    {
        $this->repository->method('findWithFilters')->willReturn([new AuditLog()]);
        self::assertTrue($this->query->exists());

        $this->repository = $this->createMock(AuditLogRepository::class);
        $this->query = new AuditQuery($this->repository);
        $this->repository->method('findWithFilters')->willReturn([]);
        self::assertFalse($this->query->exists());
    }

    public function testGetNextCursor(): void
    {
        $log1 = new AuditLog();
        $this->setLogId($log1, 10);
        $log2 = new AuditLog();
        $this->setLogId($log2, 5);

        $this->repository->method('findWithFilters')->willReturn([$log1, $log2]);

        // getNextCursor returns the ID of the LAST result
        self::assertEquals(5, $this->query->getNextCursor());

        // Empty results should return null
        $this->repository = $this->createMock(AuditLogRepository::class);
        $this->query = new AuditQuery($this->repository);
        $this->repository->method('findWithFilters')->willReturn([]);
        self::assertNull($this->query->getNextCursor());
    }

    public function testEntityIdMethod(): void
    {
        $this->repository->expects($this->once())
            ->method('findWithFilters')
            ->with(self::callback(fn ($f) => '123' === $f['entityId']), 30)
            ->willReturn([]);

        $this->query->entityId('123')->getResults();
    }

    public function testBetween(): void
    {
        $this->repository->expects($this->once())
            ->method('findWithFilters')
            ->with(self::callback(fn ($f) => isset($f['from']) && isset($f['to'])), 30)
            ->willReturn([]);

        $this->query->between(new \DateTimeImmutable(), new \DateTimeImmutable())->getResults();
    }
}
