<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Repository;

use DateTimeImmutable;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;
use InvalidArgumentException;
use Override;
use Rcsofttech\AuditTrailBundle\Contract\AuditLogInterface;
use Rcsofttech\AuditTrailBundle\Contract\AuditLogRepositoryInterface;
use Rcsofttech\AuditTrailBundle\Entity\AuditLog;

use function in_array;
use function is_array;
use function is_string;

/**
 * @extends ServiceEntityRepository<AuditLog>
 */
final class AuditLogRepository extends ServiceEntityRepository implements AuditLogRepositoryInterface
{
    private const array STATE_CHANGING_ACTIONS = [
        AuditLogInterface::ACTION_CREATE,
        AuditLogInterface::ACTION_UPDATE,
        AuditLogInterface::ACTION_DELETE,
        AuditLogInterface::ACTION_SOFT_DELETE,
        AuditLogInterface::ACTION_RESTORE,
    ];

    public function __construct(ManagerRegistry $registry)
    {
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

        $this->applyEntityClassFilter($qb, $filters);
        $this->applyScalarFilters($qb, $filters);
        $this->applyDateRangeFilters($qb, $filters);
        $qb->orderBy('a.id', 'DESC');

        /** @var iterable<AuditLog> $iterable */
        $iterable = $qb->getQuery()->toIterable();

        return $iterable;
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

        $this->applyEntityClassFilter($qb, $filters);
        $this->applyScalarFilters($qb, $filters);
        $this->applyDateRangeFilters($qb, $filters);
        $this->applyKeysetPagination($qb, $filters);

        /** @var array<int, AuditLog> $result */
        $result = $qb->getQuery()->getResult();

        // Reverse results if paginating backwards to maintain DESC order
        if (isset($filters['beforeId'])) {
            $result = array_reverse($result);
        }

        return $result;
    }

    /**
     * @param array<string, mixed> $filters
     */
    private function applyEntityClassFilter(QueryBuilder $qb, array $filters): void
    {
        if (!isset($filters['entityClass']) || !is_string($filters['entityClass'])) {
            return;
        }

        $entityClass = $filters['entityClass'];

        // Check if it's a FQCN (contains backslash) or valid class
        if (str_contains($entityClass, '\\') || class_exists($entityClass)) {
            // Exact match for FQCN or existing class
            $qb->andWhere('a.entityClass = :entityClass')
                ->setParameter('entityClass', $entityClass);
        } else {
            // Partial match for short names - escape wildcards
            $escaped = addcslashes($entityClass, '%_');
            $qb->andWhere('a.entityClass LIKE :entityClass')
                ->setParameter('entityClass', '%'.$escaped.'%');
        }
    }

    /**
     * @param array<string, mixed> $filters
     */
    private function applyScalarFilters(QueryBuilder $qb, array $filters): void
    {
        $scalarFilters = [
            'entityId' => 'a.entityId',
            'userId' => 'a.userId',
            'username' => 'a.username',
            'transactionHash' => 'a.transactionHash',
        ];

        foreach ($scalarFilters as $filterKey => $fieldPath) {
            if (isset($filters[$filterKey])) {
                $qb->andWhere("$fieldPath = :$filterKey")
                    ->setParameter($filterKey, $filters[$filterKey]);
            }
        }

        if (isset($filters['action'])) {
            $qb->andWhere('a.action = :action')
                ->setParameter('action', $filters['action']);
        }

        if (isset($filters['actions']) && is_array($filters['actions']) && $filters['actions'] !== []) {
            $qb->andWhere('a.action IN (:actions)')
                ->setParameter('actions', $filters['actions']);
        }
    }

    /**
     * @param array<string, mixed> $filters
     */
    private function applyDateRangeFilters(QueryBuilder $qb, array $filters): void
    {
        if (isset($filters['from'])) {
            $qb->andWhere('a.createdAt >= :from')
                ->setParameter('from', $filters['from']);
        }

        if (isset($filters['to'])) {
            $qb->andWhere('a.createdAt <= :to')
                ->setParameter('to', $filters['to']);
        }
    }

    /**
     * @param array<string, mixed> $filters
     */
    private function applyKeysetPagination(QueryBuilder $qb, array $filters): void
    {
        $order = 'DESC';

        if (isset($filters['afterId'])) {
            $qb->andWhere('a.id < :afterId')
                ->setParameter('afterId', $filters['afterId'], 'uuid');
        }

        if (isset($filters['beforeId'])) {
            $qb->andWhere('a.id > :beforeId')
                ->setParameter('beforeId', $filters['beforeId'], 'uuid');

            $order = 'ASC';
        }

        $qb->orderBy('a.id', $order);
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
     * @return array<AuditLog>
     */
    #[Override]
    public function findOlderThan(DateTimeImmutable $before): array
    {
        /** @var array<AuditLog> $results */
        $results = $this->createQueryBuilder('a')
            ->where('a.createdAt < :before')
            ->setParameter('before', $before)
            ->orderBy('a.createdAt', 'ASC')
            ->addOrderBy('a.id', 'ASC')
            ->getQuery()
            ->getResult();

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
            ->setParameter('action', AuditLogInterface::ACTION_REVERT)
            ->setMaxResults(25)
            ->getQuery()
            ->getResult();

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
            ->setParameter('entityId', $log->entityId)
            ->setParameter('actions', self::STATE_CHANGING_ACTIONS)
            ->setParameter('createdAt', $log->createdAt)
            ->setParameter('id', $log->id->toRfc4122(), 'uuid')
            ->getQuery()
            ->getSingleScalarResult();

        return $count > 0;
    }

    private function isStateChangingAction(string $action): bool
    {
        return in_array($action, self::STATE_CHANGING_ACTIONS, true);
    }
}
