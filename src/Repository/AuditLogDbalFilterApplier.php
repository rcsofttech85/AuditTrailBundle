<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Repository;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Query\QueryBuilder;
use Doctrine\DBAL\Types\Types;
use InvalidArgumentException;
use Rcsofttech\AuditTrailBundle\Enum\AuditAction;

use function addcslashes;
use function array_map;
use function class_exists;
use function is_array;
use function is_string;
use function sprintf;
use function str_contains;

final class AuditLogDbalFilterApplier
{
    /**
     * @param array<string, mixed>  $filters
     * @param array<string, string> $columns
     */
    public function apply(
        QueryBuilder $qb,
        Connection $connection,
        array $filters,
        array $columns,
        string $idDoctrineType,
    ): void {
        $this->applyEntityClassFilter($qb, $connection, $filters, $columns['entityClass']);
        $this->applyScalarFilters($qb, $connection, $filters, $columns);
        $this->applyActionFilters($qb, $connection, $filters, $columns['action']);
        $this->applyDateRangeFilters($qb, $connection, $filters, $columns['createdAt']);
        $this->applyKeysetPagination($qb, $connection, $filters, $columns['id'], $idDoctrineType);
    }

    /**
     * @param array<string, mixed> $filters
     */
    private function applyEntityClassFilter(
        QueryBuilder $qb,
        Connection $connection,
        array $filters,
        string $column,
    ): void {
        if (!isset($filters['entityClass']) || !is_string($filters['entityClass'])) {
            return;
        }

        $identifier = AuditLogSqlIdentifierQuoter::quoteColumnReference($connection, 'a', $column);
        $entityClass = $filters['entityClass'];

        if (str_contains($entityClass, '\\') || class_exists($entityClass)) {
            $qb->andWhere(sprintf('%s = :entityClass', $identifier))
                ->setParameter('entityClass', $entityClass);

            return;
        }

        $qb->andWhere(sprintf('%s LIKE :entityClass', $identifier))
            ->setParameter('entityClass', '%'.addcslashes($entityClass, '%_').'%');
    }

    /**
     * @param array<string, mixed>  $filters
     * @param array<string, string> $columns
     */
    private function applyScalarFilters(
        QueryBuilder $qb,
        Connection $connection,
        array $filters,
        array $columns,
    ): void {
        foreach ([
            'entityId' => 'entityId',
            'userId' => 'userId',
            'username' => 'username',
            'transactionHash' => 'transactionHash',
        ] as $filterKey => $columnKey) {
            if (!isset($filters[$filterKey])) {
                continue;
            }

            $qb->andWhere(sprintf('%s = :%s', AuditLogSqlIdentifierQuoter::quoteColumnReference($connection, 'a', $columns[$columnKey]), $filterKey))
                ->setParameter($filterKey, $filters[$filterKey]);
        }
    }

    /**
     * @param array<string, mixed> $filters
     */
    private function applyActionFilters(
        QueryBuilder $qb,
        Connection $connection,
        array $filters,
        string $actionColumn,
    ): void {
        $quotedColumn = AuditLogSqlIdentifierQuoter::quoteColumnReference($connection, 'a', $actionColumn);

        if (isset($filters['action'])) {
            $qb->andWhere(sprintf('%s = :action', $quotedColumn))
                ->setParameter('action', $this->normalizeActionFilter($filters['action']));
        }

        if (isset($filters['actions']) && is_array($filters['actions']) && $filters['actions'] !== []) {
            $qb->andWhere(sprintf('%s IN (:actions)', $quotedColumn))
                ->setParameter('actions', array_map($this->normalizeActionFilter(...), $filters['actions']), ArrayParameterType::STRING);
        }
    }

    /**
     * @param array<string, mixed> $filters
     */
    private function applyDateRangeFilters(
        QueryBuilder $qb,
        Connection $connection,
        array $filters,
        string $createdAtColumn,
    ): void {
        $quotedColumn = AuditLogSqlIdentifierQuoter::quoteColumnReference($connection, 'a', $createdAtColumn);

        if (isset($filters['from'])) {
            $qb->andWhere(sprintf('%s >= :from', $quotedColumn))
                ->setParameter('from', $filters['from'], Types::DATETIME_IMMUTABLE);
        }

        if (isset($filters['to'])) {
            $qb->andWhere(sprintf('%s <= :to', $quotedColumn))
                ->setParameter('to', $filters['to'], Types::DATETIME_IMMUTABLE);
        }
    }

    /**
     * @param array<string, mixed> $filters
     */
    private function applyKeysetPagination(
        QueryBuilder $qb,
        Connection $connection,
        array $filters,
        string $idColumn,
        string $idDoctrineType,
    ): void {
        $quotedColumn = AuditLogSqlIdentifierQuoter::quoteColumnReference($connection, 'a', $idColumn);

        if (isset($filters['afterId'])) {
            $qb->andWhere(sprintf('%s < :afterId', $quotedColumn))
                ->setParameter('afterId', $filters['afterId'], $idDoctrineType);
        }

        if (isset($filters['beforeId'])) {
            $qb->andWhere(sprintf('%s > :beforeId', $quotedColumn))
                ->setParameter('beforeId', $filters['beforeId'], $idDoctrineType);
        }
    }

    private function normalizeActionFilter(mixed $action): string
    {
        return match (true) {
            $action instanceof AuditAction => $action->value,
            is_string($action) => AuditAction::from($action)->value,
            default => throw new InvalidArgumentException('Invalid action filter value.'),
        };
    }
}
