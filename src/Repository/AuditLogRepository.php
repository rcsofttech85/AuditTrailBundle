<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Repository;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;
use Rcsofttech\AuditTrailBundle\Contract\AuditLogRepositoryInterface;
use Rcsofttech\AuditTrailBundle\Entity\AuditLog;

/**
 * @extends ServiceEntityRepository<AuditLog>
 */
class AuditLogRepository extends ServiceEntityRepository implements AuditLogRepositoryInterface
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AuditLog::class);
    }

    /**
     * @return array<AuditLog>
     */
    public function findByEntity(string $entityClass, string $entityId): array
    {
        /** @var array<AuditLog> $result */
        $result = $this->createQueryBuilder('a')
            ->where('a.entityClass = :class')
            ->andWhere('a.entityId = :id')
            ->setParameter('class', $entityClass)
            ->setParameter('id', $entityId)
            ->orderBy('a.createdAt', 'DESC')
            ->getQuery()
            ->getResult();

        return $result;
    }

    /**
     * @return array<AuditLog>
     */
    public function findByTransactionHash(string $transactionHash): array
    {
        /** @var array<AuditLog> $result */
        $result = $this->createQueryBuilder('a')
            ->where('a.transactionHash = :hash')
            ->setParameter('hash', $transactionHash)
            ->orderBy('a.createdAt', 'DESC')
            ->getQuery()
            ->getResult();

        return $result;
    }

    /**
     * @return array<AuditLog>
     */
    public function findByUser(int $userId, int $limit = 30): array
    {
        /** @var array<AuditLog> $result */
        $result = $this->createQueryBuilder('a')
            ->where('a.userId = :userId')
            ->setParameter('userId', $userId)
            ->orderBy('a.createdAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();

        return $result;
    }

    public function deleteOldLogs(\DateTimeImmutable $before): int
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
     * Find audit logs with optional filters using keyset pagination.
     *
     * @param array<string, mixed> $filters
     *
     * @return array<int, AuditLog>
     */
    public function findWithFilters(array $filters = [], int $limit = 30): array
    {
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
        if (!isset($filters['entityClass'])) {
            return;
        }

        $entityClass = $filters['entityClass'];

        // Check if it's a FQCN (contains backslash) or valid class
        if (str_contains($entityClass, '\\') || class_exists($entityClass)) {
            // Exact match for FQCN or existing class
            $qb->andWhere('a.entityClass = :entityClass')
                ->setParameter('entityClass', $entityClass);
        } else {
            // Partial match for short names
            $qb->andWhere('a.entityClass LIKE :entityClass')
                ->setParameter('entityClass', '%' . $entityClass . '%');
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
            'action' => 'a.action',
            'transactionHash' => 'a.transactionHash',
        ];

        foreach ($scalarFilters as $filterKey => $fieldPath) {
            if (isset($filters[$filterKey])) {
                $qb->andWhere("$fieldPath = :$filterKey")
                    ->setParameter($filterKey, $filters[$filterKey]);
            }
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
        // Default order: newest first
        $order = 'DESC';

        if (isset($filters['afterId'])) {
            // Next page: get records with ID less than the cursor
            $qb->andWhere('a.id < :afterId')
                ->setParameter('afterId', $filters['afterId']);
        }

        if (isset($filters['beforeId'])) {
            // Previous page: get records with ID greater than the cursor
            $qb->andWhere('a.id > :beforeId')
                ->setParameter('beforeId', $filters['beforeId']);

            // Temporarily reverse order to fetch correct records
            $order = 'ASC';
        }

        $qb->orderBy('a.id', $order);
    }

    /**
     * Count audit logs older than a given date.
     */
    public function countOlderThan(\DateTimeImmutable $before): int
    {
        return (int) $this->createQueryBuilder('a')
            ->select('COUNT(a.id)')
            ->where('a.createdAt < :before')
            ->setParameter('before', $before)
            ->getQuery()
            ->getSingleScalarResult();
    }
}
