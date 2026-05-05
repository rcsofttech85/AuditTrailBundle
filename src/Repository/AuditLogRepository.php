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

use function array_any;

/**
 * @extends ServiceEntityRepository<AuditLog>
 */
final class AuditLogRepository extends ServiceEntityRepository implements AuditLogRepositoryInterface
{
    private const array STATE_CHANGING_ACTIONS = [
        AuditAction::Create,
        AuditAction::Update,
        AuditAction::Delete,
        AuditAction::SoftDelete,
        AuditAction::Restore,
    ];

    public function __construct(
        ManagerRegistry $registry,
        private readonly AuditLogQueryFilterApplier $filterApplier,
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

        // Reverse results if paginating backwards to maintain DESC order
        if (isset($filters['beforeId'])) {
            $result = array_reverse($result);
        }

        return $result;
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

        /** @var list<AuditLog> $revertLogs */
        $revertLogs = $this->createQueryBuilder('a')
            ->where('a.entityClass = :entityClass')
            ->andWhere('a.entityId = :entityId')
            ->andWhere('a.action = :action')
            ->setParameter('entityClass', $log->entityClass)
            ->setParameter('entityId', $log->entityId)
            ->setParameter('action', AuditAction::Revert)
            ->setMaxResults(25)
            ->getQuery()
            ->getResult();

        return array_any(
            $revertLogs,
            static fn (AuditLog $revertLog): bool => ($revertLog->context['reverted_log_id'] ?? null) === $revertedLogId,
        );
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
            ->setParameter('entityId', $log->entityId)
            ->setParameter('actions', self::STATE_CHANGING_ACTIONS)
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
}
