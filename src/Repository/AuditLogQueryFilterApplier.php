<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Repository;

use Doctrine\ORM\QueryBuilder;
use InvalidArgumentException;
use Rcsofttech\AuditTrailBundle\Enum\AuditAction;

use function array_map;
use function is_array;
use function is_string;

final class AuditLogQueryFilterApplier
{
    /**
     * @param array<string, mixed> $filters
     */
    public function apply(QueryBuilder $qb, array $filters, bool $withPagination = true): void
    {
        $this->applyEntityClassFilter($qb, $filters);
        $this->applyScalarFilters($qb, $filters);
        $this->applyDateRangeFilters($qb, $filters);

        if ($withPagination) {
            $this->applyKeysetPagination($qb, $filters);
        }
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

        if (str_contains($entityClass, '\\') || class_exists($entityClass)) {
            $qb->andWhere('a.entityClass = :entityClass')
                ->setParameter('entityClass', $entityClass);

            return;
        }

        $escaped = addcslashes($entityClass, '%_');
        $qb->andWhere('a.entityClass LIKE :entityClass')
            ->setParameter('entityClass', '%'.$escaped.'%');
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
                ->setParameter('action', $this->normalizeActionFilter($filters['action']));
        }

        if (isset($filters['actions']) && is_array($filters['actions']) && $filters['actions'] !== []) {
            $qb->andWhere('a.action IN (:actions)')
                ->setParameter('actions', array_map($this->normalizeActionFilter(...), $filters['actions']));
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

    private function normalizeActionFilter(mixed $action): AuditAction
    {
        return match (true) {
            $action instanceof AuditAction => $action,
            is_string($action) => AuditAction::from($action),
            default => throw new InvalidArgumentException('Invalid action filter value.'),
        };
    }
}
