<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Repository;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Query\QueryBuilder;

use function implode;
use function json_encode;
use function sprintf;

use const JSON_THROW_ON_ERROR;

final readonly class AuditLogChangedFieldConstraintBuilder
{
    /**
     * @param list<string> $changedFields
     */
    public function apply(
        QueryBuilder $qb,
        Connection $connection,
        array $changedFields,
        string $changedFieldsColumn,
    ): void {
        $column = AuditLogSqlIdentifierQuoter::quoteColumnReference($connection, 'a', $changedFieldsColumn);
        $platform = $connection->getDatabasePlatform();
        $conditions = [];

        foreach ($changedFields as $index => $field) {
            $parameterName = sprintf('changedField_%d', $index);

            if ($platform instanceof AbstractMySQLPlatform) {
                $conditions[] = sprintf('JSON_CONTAINS(%s, :%s, \'$\') = 1', $column, $parameterName);
                $qb->setParameter($parameterName, json_encode($field, JSON_THROW_ON_ERROR));

                continue;
            }

            if ($platform instanceof PostgreSQLPlatform) {
                $conditions[] = sprintf('jsonb_exists(CAST(%s AS JSONB), :%s)', $column, $parameterName);
                $qb->setParameter($parameterName, $field);

                continue;
            }

            if ($platform instanceof SQLitePlatform) {
                $conditions[] = sprintf(
                    'EXISTS (SELECT 1 FROM json_each(%s) WHERE json_each.value = :%s)',
                    $column,
                    $parameterName,
                );
                $qb->setParameter($parameterName, $field);
            }
        }

        if ($conditions !== []) {
            $qb->andWhere('('.implode(' OR ', $conditions).')');
        }
    }
}
