<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Tests\Unit\Query;

use Exception;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Rcsofttech\AuditTrailBundle\Contract\AuditLogRepositoryInterface;
use Rcsofttech\AuditTrailBundle\Contract\EntityIdResolverInterface;
use Rcsofttech\AuditTrailBundle\Entity\AuditLog;
use Rcsofttech\AuditTrailBundle\Query\AuditReader;
use stdClass;

#[AllowMockObjectsWithoutExpectations]
class AuditReaderTest extends TestCase
{
    private AuditLogRepositoryInterface&MockObject $repository;

    private EntityIdResolverInterface&MockObject $idResolver;

    private AuditReader $reader;

    protected function setUp(): void
    {
        $this->repository = $this->createMock(AuditLogRepositoryInterface::class);
        $this->idResolver = $this->createMock(EntityIdResolverInterface::class);
        $this->reader = new AuditReader($this->repository, $this->idResolver);
    }

    public function testCreateQuery(): void
    {
        $this->reader->createQuery();
        $this->expectNotToPerformAssertions();
    }

    public function testForEntity(): void
    {
        $this->reader->forEntity('App\Entity\User', '123');
        $this->expectNotToPerformAssertions();
    }

    public function testByUser(): void
    {
        $this->reader->byUser('1');
        $this->expectNotToPerformAssertions();
    }

    public function testByTransaction(): void
    {
        $this->reader->byTransaction('hash');
        $this->expectNotToPerformAssertions();
    }

    public function testGetHistoryFor(): void
    {
        $entity = new stdClass();
        $this->idResolver->method('resolveFromEntity')->with($entity)->willReturn('123');

        $this->repository->expects($this->once())
            ->method('findWithFilters')
            ->with(self::callback(static fn ($f) => $f['entityClass'] === 'stdClass' && $f['entityId'] === '123'), 30)
            ->willReturn([]);

        $this->reader->getHistoryFor($entity);
    }

    public function testGetHistoryForFailure(): void
    {
        $entity = new stdClass();
        $this->idResolver->method('resolveFromEntity')->willThrowException(new Exception());

        $this->repository->expects($this->never())->method('findWithFilters');

        $collection = $this->reader->getHistoryFor($entity);
        self::assertCount(0, $collection);
    }

    public function testGetTimelineFor(): void
    {
        $entity = new stdClass();
        $this->idResolver->method('resolveFromEntity')->willReturn('123');

        $log = new AuditLog(stdClass::class, '123', 'create', transactionHash: 'tx1');

        $this->repository->method('findWithFilters')->willReturn([$log]);

        $timeline = $this->reader->getTimelineFor($entity);
        self::assertArrayHasKey('tx1', $timeline);
        self::assertCount(1, $timeline['tx1']);
    }

    public function testGetLatestFor(): void
    {
        $entity = new stdClass();
        $this->idResolver->method('resolveFromEntity')->willReturn('123');

        $log = new AuditLog(stdClass::class, '123', 'create');
        $this->repository->method('findWithFilters')->willReturn([$log]);

        $result = $this->reader->getLatestFor($entity);
        self::assertNotNull($result);
    }

    public function testHasHistoryFor(): void
    {
        $entity = new stdClass();
        $this->idResolver->method('resolveFromEntity')->willReturn('123');

        $this->repository->method('findWithFilters')->willReturn([new AuditLog(stdClass::class, '123', 'create')]);

        self::assertTrue($this->reader->hasHistoryFor($entity));
    }
}
