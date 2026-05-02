<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Tests\Unit\Query;

use Exception;
use PHPUnit\Framework\TestCase;
use Rcsofttech\AuditTrailBundle\Contract\AuditLogRepositoryInterface;
use Rcsofttech\AuditTrailBundle\Contract\EntityIdResolverInterface;
use Rcsofttech\AuditTrailBundle\Entity\AuditLog;
use Rcsofttech\AuditTrailBundle\Query\AuditChangedFieldMatcher;
use Rcsofttech\AuditTrailBundle\Query\AuditQueryFilterFactory;
use Rcsofttech\AuditTrailBundle\Query\AuditReader;
use stdClass;

final class AuditReaderTest extends TestCase
{
    private function createReader(
        ?AuditLogRepositoryInterface $repository = null,
        ?EntityIdResolverInterface $idResolver = null,
    ): AuditReader {
        return new AuditReader(
            $repository ?? self::createStub(AuditLogRepositoryInterface::class),
            $idResolver ?? self::createStub(EntityIdResolverInterface::class),
            new AuditQueryFilterFactory(),
            new AuditChangedFieldMatcher(),
        );
    }

    public function testCreateQueryReturnsAuditQuery(): void
    {
        $reader = $this->createReader();
        $reader->createQuery();
        $this->expectNotToPerformAssertions();
    }

    public function testForEntityReturnsAuditQuery(): void
    {
        $reader = $this->createReader();
        $reader->forEntity('App\Entity\User', '123');
        $this->expectNotToPerformAssertions();
    }

    public function testByUserReturnsAuditQuery(): void
    {
        $reader = $this->createReader();
        $reader->byUser('1');
        $this->expectNotToPerformAssertions();
    }

    public function testByTransactionReturnsAuditQuery(): void
    {
        $reader = $this->createReader();
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

        $reader = $this->createReader($repository, $idResolver);
        $reader->getHistoryFor($entity);
    }

    public function testGetHistoryForFailure(): void
    {
        $entity = new stdClass();
        $idResolver = self::createStub(EntityIdResolverInterface::class);
        $idResolver->method('resolveFromEntity')->willThrowException(new Exception());

        $repository = self::createMock(AuditLogRepositoryInterface::class);
        $repository->expects($this->never())->method('findWithFilters');

        $reader = $this->createReader($repository, $idResolver);
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

        $reader = $this->createReader($repository, $idResolver);
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

        $reader = $this->createReader($repository, $idResolver);
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

        $reader = $this->createReader($repository, $idResolver);
        self::assertTrue($reader->hasHistoryFor($entity));
    }
}
