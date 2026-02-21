<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Tests\Unit\Query;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Rcsofttech\AuditTrailBundle\Entity\AuditLog;
use Rcsofttech\AuditTrailBundle\Query\AuditQuery;
use Rcsofttech\AuditTrailBundle\Repository\AuditLogRepository;
use ReflectionClass;
use Symfony\Component\Uid\Uuid;

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
        $this->repository->expects($this->once())
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
        $this->repository->expects($this->once())
            ->method('findWithFilters')
            ->with(
                self::callback(static function (array $filters) {
                    return ($filters['transactionHash'] ?? null) === 'hash'
                        && ($filters['afterId'] ?? null) === 'uuid-10'
                        && !isset($filters['beforeId']);
                }),
                10
            )
            ->willReturn([]);

        $this->query
            ->transaction('hash')
            ->after('uuid-10')
            ->limit(10)
            ->getResults();
    }

    public function testDateFilters(): void
    {
        $this->repository->expects($this->once())
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
        $callCount = 0;
        $this->repository->expects($this->exactly(2))
            ->method('findWithFilters')
            ->with(self::callback(static function (array $f) use (&$callCount) {
                ++$callCount;
                if ($callCount === 1) {
                    return ($f['afterId'] ?? null) === 'uuid-10' && !isset($f['beforeId']);
                }

                return ($f['beforeId'] ?? null) === 'uuid-20' && !isset($f['afterId']);
            }), 30)
            ->willReturn([]);

        $this->query->after('uuid-10')->getResults();
        $this->query->before('uuid-20')->getResults();
    }

    private function setLogId(AuditLog $log, string $id): void
    {
        $reflection = new ReflectionClass($log);
        $property = $reflection->getProperty('id');
        $property->setValue($log, Uuid::fromString($id));
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
        $log1 = new AuditLog('Class', '1', 'create', changedFields: ['name']);
        $log2 = new AuditLog('Class', '2', 'update', changedFields: ['age']);

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
        $uuid1 = Uuid::v4()->toString();
        $log1 = new AuditLog('Class', '1', 'create');
        $this->setLogId($log1, $uuid1);
        $log2 = new AuditLog('Class', '2', 'create');
        $this->setLogId($log2, Uuid::v4()->toString());

        // Should call findWithFilters with limit 1
        $this->repository->expects($this->once())
            ->method('findWithFilters')
            ->with(self::anything(), 1)
            ->willReturn([$log1]);

        $result = $this->query->getFirstResult();
        self::assertNotNull($result);
        self::assertNotNull($result->id);
        self::assertEquals($uuid1, $result->id->toString());
    }

    public function testExists(): void
    {
        $this->repository->method('findWithFilters')->willReturn([new AuditLog('Class', '1', 'create')]);
        self::assertTrue($this->query->exists());

        $this->repository = $this->createMock(AuditLogRepository::class);
        $this->query = new AuditQuery($this->repository);
        $this->repository->method('findWithFilters')->willReturn([]);
        self::assertFalse($this->query->exists());
    }

    public function testGetNextCursor(): void
    {
        $uuid1 = Uuid::v4()->toString();
        $log1 = new AuditLog('Class', '1', 'create');
        $this->setLogId($log1, $uuid1);
        $uuid2 = Uuid::v4()->toString();
        $log2 = new AuditLog('Class', '2', 'create');
        $this->setLogId($log2, $uuid2);

        $this->repository->method('findWithFilters')->willReturn([$log1, $log2]);

        // getNextCursor returns the ID of the LAST result
        self::assertEquals($uuid2, $this->query->getNextCursor());

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
            ->with(self::callback(static fn ($f) => $f['entityId'] === '123'), 30)
            ->willReturn([]);

        $this->query->entityId('123')->getResults();
    }

    public function testBetween(): void
    {
        $this->repository->expects($this->once())
            ->method('findWithFilters')
            ->with(self::callback(static fn ($f) => isset($f['from']) && isset($f['to'])), 30)
            ->willReturn([]);

        $this->query->between(new DateTimeImmutable(), new DateTimeImmutable())->getResults();
    }
}
