<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Repository;

use Doctrine\DBAL\Connection;

use function implode;
use function sprintf;

final class AuditLogSqlIdentifierQuoter
{
    public static function quoteColumnReference(Connection $connection, string $alias, string $column): string
    {
        return sprintf('%s.%s', $alias, $connection->quoteSingleIdentifier($column));
    }

    public static function quoteTableReference(
        Connection $connection,
        string $tableName,
        ?string $schemaName = null,
    ): string {
        $parts = [];

        if ($schemaName !== null && $schemaName !== '') {
            $parts[] = $connection->quoteSingleIdentifier($schemaName);
        }

        $parts[] = $connection->quoteSingleIdentifier($tableName);

        return implode('.', $parts);
    }
}
