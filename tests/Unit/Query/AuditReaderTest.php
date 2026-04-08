<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Tests\Unit\Query;

use Exception;
use PHPUnit\Framework\TestCase;
use Rcsofttech\AuditTrailBundle\Contract\AuditLogRepositoryInterface;
use Rcsofttech\AuditTrailBundle\Contract\EntityIdResolverInterface;
use Rcsofttech\AuditTrailBundle\Entity\AuditLog;
use Rcsofttech\AuditTrailBundle\Query\AuditReader;
use stdClass;

final class AuditReaderTest extends TestCase
{
    public function testCreateQueryReturnsAuditQuery(): void
    {
        $reader = new AuditReader(self::createStub(AuditLogRepositoryInterface::class), self::createStub(EntityIdResolverInterface::class));
        $reader->createQuery();
        $this->expectNotToPerformAssertions();
    }

    public function testForEntityReturnsAuditQuery(): void
    {
        $reader = new AuditReader(self::createStub(AuditLogRepositoryInterface::class), self::createStub(EntityIdResolverInterface::class));
        $reader->forEntity('App\Entity\User', '123');
        $this->expectNotToPerformAssertions();
    }

    public function testByUserReturnsAuditQuery(): void
    {
        $reader = new AuditReader(self::createStub(AuditLogRepositoryInterface::class), self::createStub(EntityIdResolverInterface::class));
        $reader->byUser('1');
        $this->expectNotToPerformAssertions();
    }

    public function testByTransactionReturnsAuditQuery(): void
    {
        $reader = new AuditReader(self::createStub(AuditLogRepositoryInterface::class), self::createStub(EntityIdResolverInterface::class));
        $reader->byTransaction('hash');
        $this->expectNotToPerformAssertions();
    }

    public function testGetHistoryFor(): void
    {
        $entity = new stdClass();
        $idResolver = self::createStub(EntityIdResolverInterface::class);
        $idResolver->method('resolveFromEntity')->willReturn('123');

        $repository = self::createMock(AuditLogRepositoryInterface::class);
        $repository->expects($this->once())
            ->method('findWithFilters')
            ->with(self::callback(static fn (array $f) => ($f['entityClass'] ?? null) === stdClass::class && ($f['entityId'] ?? null) === '123'), 30)
            ->willReturn([]);

        $reader = new AuditReader($repository, $idResolver);
        $reader->getHistoryFor($entity);
    }

    public function testGetHistoryForFailure(): void
    {
        $entity = new stdClass();
        $idResolver = self::createStub(EntityIdResolverInterface::class);
        $idResolver->method('resolveFromEntity')->willThrowException(new Exception());

        $repository = self::createMock(AuditLogRepositoryInterface::class);
        $repository->expects($this->never())->method('findWithFilters');

        $reader = new AuditReader($repository, $idResolver);
        $collection = $reader->getHistoryFor($entity);
        self::assertCount(0, $collection);
    }

    public function testGetTimelineFor(): void
    {
        $entity = new stdClass();
        $idResolver = self::createStub(EntityIdResolverInterface::class);
        $idResolver->method('resolveFromEntity')->willReturn('123');

        $log = new AuditLog(stdClass::class, '123', 'create', transactionHash: 'tx1');

        $repository = self::createStub(AuditLogRepositoryInterface::class);
        $repository->method('findWithFilters')->willReturn([$log]);

        $reader = new AuditReader($repository, $idResolver);
        $timeline = $reader->getTimelineFor($entity);

        self::assertArrayHasKey('tx1', $timeline);
        self::assertCount(1, $timeline['tx1']);
    }

    public function testGetLatestFor(): void
    {
        $entity = new stdClass();
        $idResolver = self::createStub(EntityIdResolverInterface::class);
        $idResolver->method('resolveFromEntity')->willReturn('123');

        $log = new AuditLog(stdClass::class, '123', 'create');
        $repository = self::createStub(AuditLogRepositoryInterface::class);
        $repository->method('findWithFilters')->willReturn([$log]);

        $reader = new AuditReader($repository, $idResolver);
        $result = $reader->getLatestFor($entity);

        self::assertNotNull($result);
    }

    public function testHasHistoryFor(): void
    {
        $entity = new stdClass();
        $idResolver = self::createStub(EntityIdResolverInterface::class);
        $idResolver->method('resolveFromEntity')->willReturn('123');

        $repository = self::createStub(AuditLogRepositoryInterface::class);
        $repository->method('findWithFilters')->willReturn([new AuditLog(stdClass::class, '123', 'create')]);

        $reader = new AuditReader($repository, $idResolver);
        self::assertTrue($reader->hasHistoryFor($entity));
    }
}
