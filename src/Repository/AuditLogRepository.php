<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Repository;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Rcsofttech\AuditTrailBundle\Entity\AuditLog;

/**
 * @extends ServiceEntityRepository<AuditLog>
 */
class AuditLogRepository extends ServiceEntityRepository
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
    public function findByUser(int $userId, int $limit = 100): array
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
     * Find audit logs with optional filters.
     *
     * @param array{
     *     entityClass?: string,
     *     entityId?: string,
     *     userId?: int,
     *     action?: string,
     *     from?: \DateTimeImmutable,
     *     to?: \DateTimeImmutable
     * } $filters
     *
     * @return array<AuditLog>
     */
    public function findWithFilters(array $filters = [], int $limit = 100, int $offset = 0): array
    {
        $qb = $this->createQueryBuilder('a')
            ->orderBy('a.createdAt', 'DESC')
            ->setMaxResults($limit)
            ->setFirstResult($offset);

        if (isset($filters['entityClass'])) {
            $qb->andWhere('a.entityClass LIKE :entityClass')
                ->setParameter('entityClass', '%'.$filters['entityClass'].'%');
        }

        if (isset($filters['entityId'])) {
            $qb->andWhere('a.entityId = :entityId')
                ->setParameter('entityId', $filters['entityId']);
        }

        if (isset($filters['userId'])) {
            $qb->andWhere('a.userId = :userId')
                ->setParameter('userId', $filters['userId']);
        }

        if (isset($filters['action'])) {
            $qb->andWhere('a.action = :action')
                ->setParameter('action', $filters['action']);
        }

        if (isset($filters['from'])) {
            $qb->andWhere('a.createdAt >= :from')
                ->setParameter('from', $filters['from']);
        }

        if (isset($filters['to'])) {
            $qb->andWhere('a.createdAt <= :to')
                ->setParameter('to', $filters['to']);
        }

        /** @var array<AuditLog> $result */
        $result = $qb->getQuery()->getResult();

        return $result;
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
