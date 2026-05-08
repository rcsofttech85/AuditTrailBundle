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
use Rcsofttech\AuditTrailBundle\Query\ChangedFieldQueryableAuditLogRepositoryInterface;
use ReflectionClass;
use Symfony\Component\Uid\Uuid;

use function array_key_exists;
use function is_array;

final class AuditQueryExecutorTest extends TestCase
{
    /** @var (AuditLogRepositoryInterface&\PHPUnit\Framework\MockObject\Stub)|(AuditLogRepositoryInterface&MockObject) */
    private AuditLogRepositoryInterface $repository;

    private AuditQueryExecutor $executor;

    protected function setUp(): void
    {
        $this->repository = self::createStub(AuditLogRepositoryInterface::class);
        $this->executor = new AuditQueryExecutor(
            $this->repository,
            new AuditQueryFilterFactory(),
            new AuditChangedFieldMatcher(),
        );
    }

    /** @return AuditLogRepositoryInterface&MockObject */
    private function useRepositoryMock(): AuditLogRepositoryInterface
    {
        $repository = $this->createMock(AuditLogRepositoryInterface::class);
        $this->repository = $repository;
        $this->executor = new AuditQueryExecutor(
            $this->repository,
            new AuditQueryFilterFactory(),
            new AuditChangedFieldMatcher(),
        );

        return $repository;
    }

    public function testCountUsesRepositoryCountWhenChangedFieldFilteringIsInactive(): void
    {
        $repository = $this->useRepositoryMock();
        $repository->expects($this->once())
            ->method('countWithFilters')
            ->with(self::callback(static fn (array $filters): bool => ($filters['userId'] ?? null) === '42'))
            ->willReturn(9);
        $repository->expects($this->never())->method('findWithFilters');

        $count = $this->executor->count(new AuditQueryState(userId: '42'));

        self::assertSame(9, $count);
    }

    public function testGetPageReturnsEntriesAndCursorFromFetchedLogs(): void
    {
        $first = new AuditLog('Class', '1', AuditAction::Create);
        $second = new AuditLog('Class', '2', AuditAction::Update, new DateTimeImmutable('-1 minute'));
        $secondId = Uuid::v7()->toString();
        $this->setLogId($second, $secondId);

        $repository = $this->useRepositoryMock();
        $repository->expects($this->once())
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

        $repository = $this->useRepositoryMock();
        $repository->expects($this->once())
            ->method('findWithFilters')
            ->with(self::callback(static fn (array $filters): bool => array_key_exists('afterId', $filters) && $filters['afterId'] === null), 250)
            ->willReturn([$first, $second, $third]);

        $count = $this->executor->count(new AuditQueryState(changedFields: ['status']));

        self::assertSame(2, $count);
    }

    public function testCountUsesRepositoryChangedFieldQueryWhenSupported(): void
    {
        /** @var (AuditLogRepositoryInterface&ChangedFieldQueryableAuditLogRepositoryInterface)&MockObject $repository */
        $repository = $this->createMockForIntersectionOfInterfaces([
            AuditLogRepositoryInterface::class,
            ChangedFieldQueryableAuditLogRepositoryInterface::class,
        ]);
        $repository->expects($this->once())->method('supportsChangedFieldQueries')->willReturn(true);
        $repository->expects($this->once())
            ->method('countWithChangedFields')
            ->with(self::callback(static fn (array $filters): bool => !isset($filters['afterId']) && !isset($filters['beforeId'])), ['status'])
            ->willReturn(4);
        $repository->expects($this->never())->method('findWithFilters');

        $executor = new AuditQueryExecutor(
            $repository,
            new AuditQueryFilterFactory(),
            new AuditChangedFieldMatcher(),
        );

        self::assertSame(4, $executor->count(new AuditQueryState(changedFields: ['status'])));
    }

    public function testGetPageUsesRepositoryChangedFieldQueryWhenSupported(): void
    {
        $last = new AuditLog('Class', '2', AuditAction::Update, changedFields: ['status']);
        $lastId = Uuid::v7()->toString();
        $this->setLogId($last, $lastId);

        /** @var (AuditLogRepositoryInterface&ChangedFieldQueryableAuditLogRepositoryInterface)&MockObject $repository */
        $repository = $this->createMockForIntersectionOfInterfaces([
            AuditLogRepositoryInterface::class,
            ChangedFieldQueryableAuditLogRepositoryInterface::class,
        ]);
        $repository->expects($this->once())->method('supportsChangedFieldQueries')->willReturn(true);
        $repository->expects($this->once())
            ->method('findWithChangedFields')
            ->with(self::callback(static fn (mixed $filters): bool => is_array($filters)), ['status'], 30)
            ->willReturn([
                new AuditLog('Class', '1', AuditAction::Update, changedFields: ['status']),
                $last,
            ]);
        $repository->expects($this->never())->method('findWithFilters');

        $executor = new AuditQueryExecutor(
            $repository,
            new AuditQueryFilterFactory(),
            new AuditChangedFieldMatcher(),
        );

        $page = $executor->getPage(new AuditQueryState(changedFields: ['status']));

        self::assertCount(2, $page->entries);
        self::assertSame($lastId, $page->nextCursor);
    }

    private function setLogId(AuditLog $log, string $id): void
    {
        $reflection = new ReflectionClass($log);
        $property = $reflection->getProperty('id');
        $property->setValue($log, Uuid::fromString($id));
    }
}
