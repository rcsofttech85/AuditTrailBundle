<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Tests\Unit\Service;

use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use Rcsofttech\AuditTrailBundle\Contract\AuditLogRepositoryInterface;
use Rcsofttech\AuditTrailBundle\Entity\AuditLog;
use Rcsofttech\AuditTrailBundle\Service\TransactionDrilldownService;
use Rcsofttech\AuditTrailBundle\Tests\Unit\AbstractAuditTestCase;
use ReflectionClass;
use Symfony\Component\Uid\Uuid;

#[AllowMockObjectsWithoutExpectations]
class TransactionDrilldownServiceTest extends AbstractAuditTestCase
{
    private AuditLogRepositoryInterface&MockObject $repository;

    private TransactionDrilldownService $service;

    protected function setUp(): void
    {
        $this->repository = $this->createMock(AuditLogRepositoryInterface::class);
        $this->service = new TransactionDrilldownService($this->repository);
    }

    public function testGetDrilldownPageFirstPage(): void
    {
        $hash = 'hash123';
        $limit = 5;

        $log1 = $this->createAuditLog();
        $log2 = $this->createAuditLog();
        $logs = [$log1, $log2];

        $this->repository->expects($this->once())
            ->method('count')
            ->with(['transactionHash' => $hash])
            ->willReturn(10);

        $this->repository->expects($this->once())
            ->method('findWithFilters')
            ->with(['transactionHash' => $hash], $limit + 1)
            ->willReturn($logs);

        $result = $this->service->getDrilldownPage($hash, null, null, $limit);

        self::assertCount(2, $result['logs']);
        self::assertSame(10, $result['totalItems']);
        self::assertFalse($result['hasNextPage']);
        self::assertFalse($result['hasPrevPage']);
        self::assertSame((string) $log1->id, $result['firstId']);
        self::assertSame((string) $log2->id, $result['lastId']);
    }

    public function testGetDrilldownPageWithNextPage(): void
    {
        $hash = 'hash123';
        $limit = 2;

        $log1 = $this->createAuditLog();
        $log2 = $this->createAuditLog();
        $log3 = $this->createAuditLog(); // Extra record
        $logs = [$log1, $log2, $log3];

        $this->repository->method('count')->willReturn(10);
        $this->repository->expects($this->once())
            ->method('findWithFilters')
            ->with(['transactionHash' => $hash], $limit + 1)
            ->willReturn($logs);

        $result = $this->service->getDrilldownPage($hash, null, null, $limit);

        self::assertCount(2, $result['logs']);
        self::assertTrue($result['hasNextPage']);
        self::assertFalse($result['hasPrevPage']);
        self::assertSame((string) $log1->id, $result['firstId']);
        self::assertSame((string) $log2->id, $result['lastId']);
    }

    public function testGetDrilldownPageWithPrevPage(): void
    {
        $hash = 'hash123';
        $limit = 2;
        $afterId = 'id0';

        $log1 = $this->createAuditLog();
        $log2 = $this->createAuditLog();
        $logs = [$log1, $log2];

        $this->repository->method('count')->willReturn(10);
        $this->repository->expects($this->once())
            ->method('findWithFilters')
            ->with(['transactionHash' => $hash, 'afterId' => $afterId], $limit + 1)
            ->willReturn($logs);

        $result = $this->service->getDrilldownPage($hash, $afterId, null, $limit);

        self::assertTrue($result['hasPrevPage']);
        self::assertFalse($result['hasNextPage']);
    }

    public function testGetDrilldownPagePaginatingBackwards(): void
    {
        $hash = 'hash123';
        $limit = 2;
        $beforeId = (string) Uuid::v7();

        $log3 = $this->createAuditLog(); // Extra record (newer)
        $log4 = $this->createAuditLog();
        $log5 = $this->createAuditLog();
        $logs = [$log3, $log4, $log5];

        $this->repository->method('count')->willReturn(10);
        $this->repository->expects($this->once())
            ->method('findWithFilters')
            ->with(['transactionHash' => $hash, 'beforeId' => $beforeId], $limit + 1)
            ->willReturn($logs);

        $result = $this->service->getDrilldownPage($hash, null, $beforeId, $limit);

        self::assertCount(2, $result['logs']);
        self::assertTrue($result['hasPrevPage']); // Sliced off log3
        self::assertTrue($result['hasNextPage']);
        self::assertSame((string) $log4->id, $result['firstId']);
        self::assertSame((string) $log5->id, $result['lastId']);
    }

    private function createAuditLog(): AuditLog
    {
        $log = new AuditLog('Class', '1', 'create');

        $reflection = new ReflectionClass($log);
        $property = $reflection->getProperty('id');
        $property->setAccessible(true);
        $property->setValue($log, Uuid::v7());

        return $log;
    }
}
