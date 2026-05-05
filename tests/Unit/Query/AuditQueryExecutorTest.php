<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Tests\Unit\Query;

use DateTimeImmutable;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Rcsofttech\AuditTrailBundle\Contract\AuditLogRepositoryInterface;
use Rcsofttech\AuditTrailBundle\Entity\AuditLog;
use Rcsofttech\AuditTrailBundle\Enum\AuditAction;
use Rcsofttech\AuditTrailBundle\Query\AuditChangedFieldMatcher;
use Rcsofttech\AuditTrailBundle\Query\AuditQueryExecutor;
use Rcsofttech\AuditTrailBundle\Query\AuditQueryFilterFactory;
use Rcsofttech\AuditTrailBundle\Query\AuditQueryState;
use ReflectionClass;
use Symfony\Component\Uid\Uuid;

use function array_key_exists;

final class AuditQueryExecutorTest extends TestCase
{
    private AuditLogRepositoryInterface&MockObject $repository;

    private AuditQueryExecutor $executor;

    protected function setUp(): void
    {
        $this->repository = $this->createMock(AuditLogRepositoryInterface::class);
        $this->executor = new AuditQueryExecutor(
            $this->repository,
            new AuditQueryFilterFactory(),
            new AuditChangedFieldMatcher(),
        );
    }

    public function testCountUsesRepositoryCountWhenChangedFieldFilteringIsInactive(): void
    {
        $this->repository->expects($this->once())
            ->method('countWithFilters')
            ->with(self::callback(static fn (array $filters): bool => ($filters['userId'] ?? null) === '42'))
            ->willReturn(9);
        $this->repository->expects($this->never())->method('findWithFilters');

        $count = $this->executor->count(new AuditQueryState(userId: '42'));

        self::assertSame(9, $count);
    }

    public function testGetPageReturnsEntriesAndCursorFromFetchedLogs(): void
    {
        $first = new AuditLog('Class', '1', AuditAction::Create);
        $second = new AuditLog('Class', '2', AuditAction::Update, new DateTimeImmutable('-1 minute'));
        $secondId = Uuid::v7()->toString();
        $this->setLogId($second, $secondId);

        $this->repository->expects($this->once())
            ->method('findWithFilters')
            ->with(self::callback(static fn (array $filters): bool => ($filters['entityClass'] ?? null) === 'Class'), 30)
            ->willReturn([$first, $second]);

        $page = $this->executor->getPage(new AuditQueryState(entityClass: 'Class'));

        self::assertCount(2, $page->entries);
        self::assertSame($secondId, $page->nextCursor);
        self::assertSame('1', $page->first()?->entityId);
    }

    public function testChangedFieldCountCountsOnlyMatchedLogsWithinFetchedBatch(): void
    {
        $first = new AuditLog('Class', '1', AuditAction::Update, changedFields: ['title']);
        $second = new AuditLog('Class', '2', AuditAction::Update, changedFields: ['status']);
        $third = new AuditLog('Class', '3', AuditAction::Update, changedFields: ['status']);

        $this->repository->expects($this->once())
            ->method('findWithFilters')
            ->with(self::callback(static fn (array $filters): bool => array_key_exists('afterId', $filters) && $filters['afterId'] === null), 250)
            ->willReturn([$first, $second, $third]);

        $count = $this->executor->count(new AuditQueryState(changedFields: ['status']));

        self::assertSame(2, $count);
    }

    private function setLogId(AuditLog $log, string $id): void
    {
        $reflection = new ReflectionClass($log);
        $property = $reflection->getProperty('id');
        $property->setValue($log, Uuid::fromString($id));
    }
}
