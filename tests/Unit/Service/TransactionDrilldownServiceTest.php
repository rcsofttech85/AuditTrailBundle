<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Tests\Unit\Service;

use InvalidArgumentException;
use PHPUnit\Framework\MockObject\MockObject;
use Rcsofttech\AuditTrailBundle\Contract\AuditLogRepositoryInterface;
use Rcsofttech\AuditTrailBundle\Entity\AuditLog;
use Rcsofttech\AuditTrailBundle\Service\TransactionDrilldownService;
use Rcsofttech\AuditTrailBundle\Tests\Unit\AbstractAuditTestCase;
use ReflectionClass;
use Symfony\Component\Uid\Uuid;

final class TransactionDrilldownServiceTest extends AbstractAuditTestCase
{
    private AuditLogRepositoryInterface $repository;

    private TransactionDrilldownService $service;

    protected function setUp(): void
    {
        $this->repository = self::createStub(AuditLogRepositoryInterface::class);
        $this->service = new TransactionDrilldownService($this->repository);
    }

    public function testGetDrilldownPageFirstPage(): void
    {
        $repository = $this->useRepositoryMock();

        $hash = 'hash123';
        $limit = 5;

        $log1 = $this->createAuditLog();
        $log2 = $this->createAuditLog();
        $logs = [$log1, $log2];

        $repository->expects($this->once())
            ->method('count')
            ->with(['transactionHash' => $hash])
            ->willReturn(10);

        $repository->expects($this->once())
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
        $repository = $this->useRepositoryMock();

        $hash = 'hash123';
        $limit = 2;

        $log1 = $this->createAuditLog();
        $log2 = $this->createAuditLog();
        $log3 = $this->createAuditLog(); // Extra record
        $logs = [$log1, $log2, $log3];

        $repository->method('count')->willReturn(10);
        $repository->expects($this->once())
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
        $repository = $this->useRepositoryMock();

        $hash = 'hash123';
        $limit = 2;
        $afterId = 'id0';

        $log1 = $this->createAuditLog();
        $log2 = $this->createAuditLog();
        $logs = [$log1, $log2];

        $repository->method('count')->willReturn(10);
        $repository->expects($this->once())
            ->method('findWithFilters')
            ->with(['transactionHash' => $hash, 'afterId' => $afterId], $limit + 1)
            ->willReturn($logs);

        $result = $this->service->getDrilldownPage($hash, $afterId, null, $limit);

        self::assertTrue($result['hasPrevPage']);
        self::assertFalse($result['hasNextPage']);
    }

    public function testGetDrilldownPagePaginatingBackwards(): void
    {
        $repository = $this->useRepositoryMock();

        $hash = 'hash123';
        $limit = 2;
        $beforeId = (string) Uuid::v7();

        $log3 = $this->createAuditLog(); // Extra record (newer)
        $log4 = $this->createAuditLog();
        $log5 = $this->createAuditLog();
        $logs = [$log3, $log4, $log5];

        $repository->method('count')->willReturn(10);
        $repository->expects($this->once())
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

    public function testGetDrilldownPageRejectsNonPositiveLimit(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Limit must be greater than zero.');

        $this->service->getDrilldownPage('hash123', null, null, 0);
    }

    public function testGetDrilldownPageRejectsConflictingCursors(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Only one pagination cursor can be used at a time.');

        $this->service->getDrilldownPage('hash123', (string) Uuid::v7(), (string) Uuid::v7(), 5);
    }

    public function testGetDrilldownPageBackwardsWithNoResultsDoesNotExposeNextPage(): void
    {
        $repository = $this->useRepositoryMock();

        $hash = 'hash123';
        $limit = 2;
        $beforeId = (string) Uuid::v7();

        $repository->method('count')->willReturn(10);
        $repository->expects($this->once())
            ->method('findWithFilters')
            ->with(['transactionHash' => $hash, 'beforeId' => $beforeId], $limit + 1)
            ->willReturn([]);

        $result = $this->service->getDrilldownPage($hash, null, $beforeId, $limit);

        self::assertSame([], $result['logs']);
        self::assertFalse($result['hasNextPage']);
        self::assertFalse($result['hasPrevPage']);
        self::assertNull($result['firstId']);
        self::assertNull($result['lastId']);
    }

    private function createAuditLog(): AuditLog
    {
        $log = new AuditLog('Class', '1', 'create');

        $reflection = new ReflectionClass($log);
        $property = $reflection->getProperty('id');
        $property->setValue($log, Uuid::v7());

        return $log;
    }

    private function useRepositoryMock(): AuditLogRepositoryInterface&MockObject
    {
        $repository = $this->createMock(AuditLogRepositoryInterface::class);
        $this->repository = $repository;
        $this->service = new TransactionDrilldownService($repository);

        return $repository;
    }
}
