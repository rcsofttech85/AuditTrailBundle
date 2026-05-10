<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Repository;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Query\QueryBuilder as DbalQueryBuilder;
use Doctrine\ORM\EntityManagerInterface;
use InvalidArgumentException;
use LogicException;
use Rcsofttech\AuditTrailBundle\Entity\AuditLog;
use Symfony\Component\Uid\Uuid;

use function array_reverse;
use function is_string;

final readonly class AuditLogChangedFieldQueryExecutor
{
    public function __construct(
        private AuditLogDbalFilterApplier $dbalFilterApplier,
        private AuditLogChangedFieldConstraintBuilder $changedFieldConstraintBuilder,
        private AuditLogMetadataResolver $metadataResolver,
        private AuditLogEntityFetcher $entityFetcher,
    ) {
    }

    public function supports(Connection $connection): bool
    {
        $platform = $connection->getDatabasePlatform();

        return $platform instanceof AbstractMySQLPlatform
            || $platform instanceof PostgreSQLPlatform
            || $platform instanceof SQLitePlatform;
    }

    /**
     * @param array<string, mixed> $filters
     * @param list<string>         $changedFields
     */
    public function count(EntityManagerInterface $entityManager, array $filters, array $changedFields): int
    {
        if ($changedFields === []) {
            throw new InvalidArgumentException('Changed field filters cannot be empty.');
        }

        $this->assertSupported($entityManager->getConnection());

        /** @var int|string $count */
        $count = $this->createQueryBuilder($entityManager, $filters, $changedFields)
            ->select('COUNT(*)')
            ->executeQuery()
            ->fetchOne();

        return (int) $count;
    }

    /**
     * @param array<string, mixed> $filters
     * @param list<string>         $changedFields
     *
     * @return list<AuditLog>
     */
    public function find(EntityManagerInterface $entityManager, array $filters, array $changedFields, int $limit): array
    {
        if ($limit < 1) {
            throw new InvalidArgumentException('Limit must be greater than zero.');
        }

        if ($changedFields === []) {
            throw new InvalidArgumentException('Changed field filters cannot be empty.');
        }

        $connection = $entityManager->getConnection();
        $this->assertSupported($connection);

        $columns = $this->metadataResolver->resolveColumnMap($entityManager);
        $idColumn = $columns['id'];
        $reverseResults = isset($filters['beforeId']);

        /** @var list<non-empty-string> $matchingIds */
        $matchingIds = $this->normalizeFetchedAuditLogIds(
            $this->createQueryBuilder($entityManager, $filters, $changedFields)
                ->select(AuditLogSqlIdentifierQuoter::quoteColumnReference($connection, 'a', $idColumn))
                ->orderBy(AuditLogSqlIdentifierQuoter::quoteColumnReference($connection, 'a', $idColumn), $reverseResults ? 'ASC' : 'DESC')
                ->setMaxResults($limit)
                ->executeQuery()
                ->fetchFirstColumn(),
        );

        if ($matchingIds === []) {
            return [];
        }

        $logsById = $this->entityFetcher->fetchIndexedByIds($entityManager, $matchingIds);
        $orderedLogs = [];

        foreach ($matchingIds as $id) {
            if (isset($logsById[$id])) {
                $orderedLogs[] = $logsById[$id];
            }
        }

        return $reverseResults ? array_reverse($orderedLogs) : $orderedLogs;
    }

    /**
     * @param array<string, mixed> $filters
     * @param list<string>         $changedFields
     */
    private function createQueryBuilder(
        EntityManagerInterface $entityManager,
        array $filters,
        array $changedFields,
    ): DbalQueryBuilder {
        $connection = $entityManager->getConnection();
        $columns = $this->metadataResolver->resolveColumnMap($entityManager);

        $qb = $connection->createQueryBuilder()
            ->from(
                AuditLogSqlIdentifierQuoter::quoteTableReference(
                    $connection,
                    $this->metadataResolver->resolveTableName($entityManager),
                    $this->metadataResolver->resolveSchemaName($entityManager),
                ),
                'a',
            );

        $this->dbalFilterApplier->apply(
            $qb,
            $connection,
            $filters,
            $columns,
            $this->metadataResolver->resolveIdDoctrineType($entityManager),
        );
        $this->changedFieldConstraintBuilder->apply($qb, $connection, $changedFields, $columns['changedFields']);

        return $qb;
    }

    private function assertSupported(Connection $connection): void
    {
        if ($this->supports($connection)) {
            return;
        }

        throw new LogicException('Changed-field queries are only supported on MySQL, PostgreSQL, and SQLite. Use AuditReader/AuditQuery for automatic fallback on other platforms.');
    }

    /**
     * @param list<mixed> $ids
     *
     * @return list<non-empty-string>
     */
    private function normalizeFetchedAuditLogIds(array $ids): array
    {
        $normalizedIds = [];

        foreach ($ids as $id) {
            if (!is_string($id) || $id === '') {
                continue;
            }

            $normalizedIds[] = Uuid::fromString($id)->toRfc4122();
        }

        return $normalizedIds;
    }
}
