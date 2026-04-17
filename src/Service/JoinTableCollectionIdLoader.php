<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Service;

use Doctrine\DBAL\Types\Type;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Mapping\JoinColumnMapping;
use Doctrine\ORM\Mapping\ManyToManyOwningSideMapping;
use Rcsofttech\AuditTrailBundle\Contract\AuditLogInterface;
use Rcsofttech\AuditTrailBundle\Contract\EntityIdResolverInterface;
use Stringable;
use Throwable;

use function is_int;
use function is_string;

final readonly class JoinTableCollectionIdLoader
{
    public function __construct(
        private EntityIdResolverInterface $idResolver,
    ) {
    }

    /**
     * @return array<int, int|string>
     */
    public function loadOriginalCollectionIdsFromDatabase(
        object $owner,
        string $associationName,
        EntityManagerInterface $em,
    ): array {
        $queryContext = $this->buildCollectionQueryContext($owner, $associationName, $em);
        if ($queryContext === null) {
            return [];
        }

        $rows = $this->fetchOriginalCollectionRows($queryContext, $em);

        return $this->normalizeLoadedIdentifiers($rows, $queryContext['inverseIdType'], $em);
    }

    /**
     * @return array{
     *     ownerId: int|string,
     *     joinTable: string,
     *     joinColumn: string,
     *     inverseJoinColumn: string,
     *     ownerIdType: ?string,
     *     inverseIdType: ?string
     * }|null
     */
    private function buildCollectionQueryContext(
        object $owner,
        string $associationName,
        EntityManagerInterface $em,
    ): ?array {
        $ownerId = $this->idResolver->resolveFromEntity($owner, $em);
        if ($ownerId === AuditLogInterface::PENDING_ID) {
            return null;
        }

        $metadata = $em->getClassMetadata($owner::class);
        $mapping = $metadata->getAssociationMapping($associationName);
        if (!$mapping instanceof ManyToManyOwningSideMapping) {
            return null;
        }

        $joinTable = $mapping->joinTable;
        $joinTableName = $joinTable->name;
        $joinColumn = $this->getFirstJoinColumn($joinTable->joinColumns);
        $inverseJoinColumn = $this->getFirstJoinColumn($joinTable->inverseJoinColumns);
        if ($joinTableName === '' || $joinColumn === null || $inverseJoinColumn === null) {
            return null;
        }
        $targetMetadata = $em->getClassMetadata($mapping->targetEntity);

        return [
            'ownerId' => $ownerId,
            'joinTable' => $joinTableName,
            'joinColumn' => $joinColumn->name,
            'inverseJoinColumn' => $inverseJoinColumn->name,
            'ownerIdType' => $this->resolveColumnType($metadata, $joinColumn->referencedColumnName),
            'inverseIdType' => $this->resolveColumnType($targetMetadata, $inverseJoinColumn->referencedColumnName),
        ];
    }

    /**
     * @param list<JoinColumnMapping> $joinColumns
     */
    private function getFirstJoinColumn(array $joinColumns): ?JoinColumnMapping
    {
        $joinColumn = $joinColumns[0] ?? null;
        if (!$joinColumn instanceof JoinColumnMapping) {
            return null;
        }

        if (!$this->isValidJoinTableName($joinColumn->name) || !$this->isValidJoinTableName($joinColumn->referencedColumnName)) {
            return null;
        }

        return $joinColumn;
    }

    private function isValidJoinTableName(string $value): bool
    {
        return $value !== '';
    }

    /**
     * @param array{
     *     ownerId: int|string,
     *     joinTable: string,
     *     joinColumn: string,
     *     inverseJoinColumn: string,
     *     ownerIdType: ?string,
     *     inverseIdType: ?string
     * } $queryContext
     *
     * @return list<mixed>
     */
    private function fetchOriginalCollectionRows(array $queryContext, EntityManagerInterface $em): array
    {
        $databaseOwnerId = $this->convertValueToDatabaseType($queryContext['ownerId'], $queryContext['ownerIdType'], $em);
        $queryBuilder = $em->getConnection()->createQueryBuilder()
            ->select($queryContext['inverseJoinColumn'])
            ->from($queryContext['joinTable'])
            ->where($queryContext['joinColumn'].' = :ownerId');

        if ($queryContext['ownerIdType'] !== null) {
            $queryBuilder->setParameter('ownerId', $databaseOwnerId, $queryContext['ownerIdType']);
        } else {
            $queryBuilder->setParameter('ownerId', $databaseOwnerId);
        }

        return $queryBuilder
            ->executeQuery()
            ->fetchFirstColumn();
    }

    /**
     * @param iterable<mixed> $rows
     *
     * @return array<int, int|string>
     */
    private function normalizeLoadedIdentifiers(iterable $rows, ?string $inverseIdType, EntityManagerInterface $em): array
    {
        $resolvedIds = [];
        foreach ($rows as $row) {
            $resolvedId = $this->normalizeLoadedIdentifier($row, $inverseIdType, $em);
            if ($resolvedId !== null) {
                $resolvedIds[] = $resolvedId;
            }
        }

        return $resolvedIds;
    }

    /**
     * @param ClassMetadata<object> $metadata
     */
    private function resolveColumnType(ClassMetadata $metadata, string $referencedColumnName): ?string
    {
        try {
            $fieldName = $metadata->getFieldForColumn($referencedColumnName);

            return $metadata->getTypeOfField($fieldName);
        } catch (Throwable) {
            return null;
        }
    }

    private function convertValueToDatabaseType(
        int|string $value,
        ?string $type,
        EntityManagerInterface $em,
    ): mixed {
        if ($type === null) {
            return $value;
        }

        try {
            return Type::getType($type)->convertToDatabaseValue($value, $em->getConnection()->getDatabasePlatform());
        } catch (Throwable) {
            return $value;
        }
    }

    private function normalizeLoadedIdentifier(
        mixed $value,
        ?string $type,
        EntityManagerInterface $em,
    ): int|string|null {
        if ($type !== null) {
            try {
                $value = Type::getType($type)->convertToPHPValue($value, $em->getConnection()->getDatabasePlatform());
            } catch (Throwable) {
            }
        }

        if (is_int($value) || is_string($value)) {
            return $value;
        }

        if ($value instanceof Stringable) {
            return (string) $value;
        }

        return null;
    }
}
