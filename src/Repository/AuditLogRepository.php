<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Repository;

use DateTimeImmutable;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use InvalidArgumentException;
use Override;
use Rcsofttech\AuditTrailBundle\Contract\AuditLogRepositoryInterface;
use Rcsofttech\AuditTrailBundle\Entity\AuditLog;
use Rcsofttech\AuditTrailBundle\Enum\AuditAction;
use Rcsofttech\AuditTrailBundle\Query\ChangedFieldQueryableAuditLogRepositoryInterface;

use function array_values;

/**
 * @extends ServiceEntityRepository<AuditLog>
 */
final class AuditLogRepository extends ServiceEntityRepository implements AuditLogRepositoryInterface, ChangedFieldQueryableAuditLogRepositoryInterface
{
    public function __construct(
        ManagerRegistry $registry,
        private readonly AuditLogQueryFilterApplier $filterApplier,
        private readonly AuditLogChangedFieldQueryExecutor $changedFieldQueryExecutor,
    ) {
        parent::__construct($registry, AuditLog::class);
    }

    /**
     * @return array<AuditLog>
     */
    #[Override]
    public function findByEntity(string $entityClass, string $entityId): array
    {
        /** @var array<AuditLog> $result */
        $result = $this->createQueryBuilder('a')
            ->where('a.entityClass = :class')
            ->andWhere('a.entityId = :id')
            ->setParameter('class', $entityClass)
            ->setParameter('id', $entityId)
            ->orderBy('a.createdAt', 'DESC')
            ->addOrderBy('a.id', 'DESC')
            ->getQuery()
            ->getResult();

        return $result;
    }

    /**
     * @return array<AuditLog>
     */
    #[Override]
    public function findByTransactionHash(string $transactionHash): array
    {
        /** @var array<AuditLog> $result */
        $result = $this->createQueryBuilder('a')
            ->where('a.transactionHash = :hash')
            ->setParameter('hash', $transactionHash)
            ->orderBy('a.createdAt', 'DESC')
            ->addOrderBy('a.id', 'DESC')
            ->getQuery()
            ->getResult();

        return $result;
    }

    /**
     * @return array<AuditLog>
     */
    #[Override]
    public function findByUser(string $userId, int $limit = 30): array
    {
        $this->assertPositiveLimit($limit);

        /** @var array<AuditLog> $result */
        $result = $this->createQueryBuilder('a')
            ->where('a.userId = :userId')
            ->setParameter('userId', $userId)
            ->orderBy('a.createdAt', 'DESC')
            ->addOrderBy('a.id', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();

        return $result;
    }

    #[Override]
    public function deleteOldLogs(DateTimeImmutable $before): int
    {
        /** @var int $count */
        $count = $this->createQueryBuilder('a')
            ->delete()
            ->where('a.createdAt < :before')
            ->setParameter('before', $before)
            ->getQuery()
            ->execute();

        return $count;
    }

    /**
     * @param array<string, mixed> $filters
     *
     * @return iterable<AuditLog>
     */
    #[Override]
    public function findAllWithFilters(array $filters = []): iterable
    {
        $qb = $this->createQueryBuilder('a');

        $this->filterApplier->apply($qb, $filters, false);
        $qb->orderBy('a.id', 'DESC');

        /** @var iterable<AuditLog> $iterable */
        $iterable = $qb->getQuery()->toIterable();

        return $iterable;
    }

    /**
     * @param array<string, mixed> $filters
     */
    #[Override]
    public function countWithFilters(array $filters = []): int
    {
        $qb = $this->createQueryBuilder('a')
            ->select('COUNT(a.id)');

        $this->filterApplier->apply($qb, $filters, false);

        /** @var int $count */
        $count = $qb
            ->getQuery()
            ->getSingleScalarResult();

        return $count;
    }

    /**
     * Find audit logs with optional filters using keyset pagination.
     *
     * @param array<string, mixed> $filters
     *
     * @return array<int, AuditLog>
     */
    #[Override]
    public function findWithFilters(array $filters = [], int $limit = 30): array
    {
        $this->assertPositiveLimit($limit);

        $qb = $this->createQueryBuilder('a')
            ->setMaxResults($limit);

        $this->filterApplier->apply($qb, $filters);

        /** @var array<int, AuditLog> $result */
        $result = $qb->getQuery()->getResult();

        if (isset($filters['beforeId'])) {
            $result = array_reverse($result);
        }

        return $result;
    }

    #[Override]
    public function supportsChangedFieldQueries(): bool
    {
        return $this->changedFieldQueryExecutor->supports($this->getEntityManager()->getConnection());
    }

    /**
     * @param array<string, mixed> $filters
     * @param list<string>         $changedFields
     */
    #[Override]
    public function countWithChangedFields(array $filters, array $changedFields): int
    {
        if ($changedFields === []) {
            return $this->countWithFilters($filters);
        }

        return $this->changedFieldQueryExecutor->count($this->getEntityManager(), $filters, $changedFields);
    }

    /**
     * @param array<string, mixed> $filters
     * @param list<string>         $changedFields
     *
     * @return list<AuditLog>
     */
    #[Override]
    public function findWithChangedFields(array $filters, array $changedFields, int $limit): array
    {
        $this->assertPositiveLimit($limit);

        if ($changedFields === []) {
            /** @var list<AuditLog> $logs */
            $logs = $this->findWithFilters($filters, $limit);

            return $logs;
        }

        return $this->changedFieldQueryExecutor->find($this->getEntityManager(), $filters, $changedFields, $limit);
    }

    private function assertPositiveLimit(int $limit): void
    {
        if ($limit < 1) {
            throw new InvalidArgumentException('Limit must be greater than zero.');
        }
    }

    /**
     * Find audit logs older than a given date.
     *
     * @return iterable<AuditLog>
     */
    #[Override]
    public function findOlderThan(DateTimeImmutable $before): iterable
    {
        /** @var iterable<AuditLog> $results */
        $results = $this->createQueryBuilder('a')
            ->where('a.createdAt < :before')
            ->setParameter('before', $before)
            ->orderBy('a.createdAt', 'ASC')
            ->addOrderBy('a.id', 'ASC')
            ->getQuery()
            ->toIterable();

        return $results;
    }

    /**
     * Count audit logs older than a given date.
     */
    #[Override]
    public function countOlderThan(DateTimeImmutable $before): int
    {
        return (int) $this->createQueryBuilder('a')
            ->select('COUNT(a.id)')
            ->where('a.createdAt < :before')
            ->setParameter('before', $before)
            ->getQuery()
            ->getSingleScalarResult();
    }

    #[Override]
    public function isReverted(AuditLog $log): bool
    {
        if ($log->id === null) {
            return false;
        }

        $revertedLogId = $log->id->toRfc4122();

        /** @var int $count */
        $count = $this->createQueryBuilder('a')
            ->select('COUNT(a.id)')
            ->where('a.revertedLogId = :revertedLogId')
            ->setParameter('revertedLogId', $revertedLogId)
            ->getQuery()
            ->getSingleScalarResult();

        if ($count > 0 || !$log->hasResolvedEntityId()) {
            return $count > 0;
        }

        // Fallback for legacy 3.x rows that stored reverted_log_id only in context.
        /** @var iterable<AuditLog> $revertLogs */
        $revertLogs = $this->createQueryBuilder('a')
            ->where('a.entityClass = :entityClass')
            ->andWhere('a.entityId = :entityId')
            ->andWhere('a.action = :action')
            ->setParameter('entityClass', $log->entityClass)
            ->setParameter('entityId', $log->requireEntityId())
            ->setParameter('action', AuditAction::Revert)
            ->orderBy('a.createdAt', 'DESC')
            ->addOrderBy('a.id', 'DESC')
            ->getQuery()
            ->toIterable();

        foreach ($revertLogs as $revertLog) {
            if (($revertLog->context['reverted_log_id'] ?? null) === $revertedLogId) {
                return true;
            }
        }

        return false;
    }

    #[Override]
    public function hasNewerStateChangingLogs(AuditLog $log): bool
    {
        if (!$this->isStateChangingAction($log->action) || $log->id === null) {
            return false;
        }

        /** @var int $count */
        $count = $this->createQueryBuilder('a')
            ->select('COUNT(a.id)')
            ->where('a.entityClass = :entityClass')
            ->andWhere('a.entityId = :entityId')
            ->andWhere('a.action IN (:actions)')
            ->andWhere('(a.createdAt > :createdAt OR (a.createdAt = :createdAt AND a.id > :id))')
            ->setParameter('entityClass', $log->entityClass)
            ->setParameter('entityId', $log->requireEntityId())
            ->setParameter('actions', self::stateChangingActions())
            ->setParameter('createdAt', $log->createdAt)
            ->setParameter('id', $log->id->toRfc4122(), 'uuid')
            ->getQuery()
            ->getSingleScalarResult();

        return $count > 0;
    }

    private function isStateChangingAction(AuditAction $action): bool
    {
        return $action->isStateChanging();
    }

    /**
     * @return list<AuditAction>
     */
    private static function stateChangingActions(): array
    {
        return array_values(array_filter(
            AuditAction::cases(),
            static fn (AuditAction $action): bool => $action->isStateChanging(),
        ));
    }
}
