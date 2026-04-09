<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Service;

use Doctrine\DBAL\Types\Type;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use Rcsofttech\AuditTrailBundle\Contract\AuditLogInterface;
use Rcsofttech\AuditTrailBundle\Contract\EntityIdResolverInterface;
use Stringable;
use Throwable;

use function get_object_vars;
use function is_array;
use function is_int;
use function is_object;
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
        if (!$this->isValidOwningCollectionMapping($mapping)) {
            return null;
        }

        $joinTable = $this->readMappingValue($mapping, 'joinTable');
        $joinColumn = $this->extractJoinColumnDefinition($joinTable, 'joinColumns');
        $inverseJoinColumn = $this->extractJoinColumnDefinition($joinTable, 'inverseJoinColumns');
        $joinTableName = $this->extractJoinTableName($joinTable);
        if ($joinColumn === null || $inverseJoinColumn === null || $joinTableName === null) {
            return null;
        }

        /** @var class-string<object> $targetEntity */
        $targetEntity = $mapping['targetEntity'];
        $targetMetadata = $em->getClassMetadata($targetEntity);

        return [
            'ownerId' => $ownerId,
            'joinTable' => $joinTableName,
            'joinColumn' => $joinColumn['name'],
            'inverseJoinColumn' => $inverseJoinColumn['name'],
            'ownerIdType' => $this->resolveColumnType($metadata, $joinColumn['referencedColumnName']),
            'inverseIdType' => $this->resolveColumnType($targetMetadata, $inverseJoinColumn['referencedColumnName']),
        ];
    }

    private function isValidOwningCollectionMapping(mixed $mapping): bool
    {
        if (($mapping['isOwningSide'] ?? false) !== true) {
            return false;
        }

        return $this->hasValidJoinTableStructure($mapping['joinTable'] ?? null);
    }

    private function hasValidJoinTableStructure(mixed $joinTable): bool
    {
        return $this->containsRequiredJoinTableName($joinTable)
            && $this->hasValidJoinColumnDefinition($this->readFirstJoinColumnDefinition($joinTable, 'joinColumns'))
            && $this->hasValidJoinColumnDefinition($this->readFirstJoinColumnDefinition($joinTable, 'inverseJoinColumns'));
    }

    private function containsRequiredJoinTableName(mixed $joinTable): bool
    {
        return $this->isNonEmptyString($this->extractJoinTableName($joinTable));
    }

    private function hasValidJoinColumnDefinition(mixed $definition): bool
    {
        return $this->extractJoinColumnDefinitionValues($definition) !== null;
    }

    private function isNonEmptyString(mixed $value): bool
    {
        return is_string($value) && $value !== '';
    }

    private function extractJoinTableName(mixed $joinTable): ?string
    {
        $joinTableName = $this->readMappingValue($joinTable, 'name');

        return $this->isNonEmptyString($joinTableName) ? $joinTableName : null;
    }

    private function readFirstJoinColumnDefinition(mixed $joinTable, string $columnGroup): mixed
    {
        $definitions = $this->readMappingValue($joinTable, $columnGroup);
        if (!is_array($definitions)) {
            return null;
        }

        return $definitions[0] ?? null;
    }

    /**
     * @return array{name: string, referencedColumnName: string}|null
     */
    private function extractJoinColumnDefinition(mixed $joinTable, string $columnGroup): ?array
    {
        return $this->extractJoinColumnDefinitionValues($this->readFirstJoinColumnDefinition($joinTable, $columnGroup));
    }

    /**
     * @return array{name: string, referencedColumnName: string}|null
     */
    private function extractJoinColumnDefinitionValues(mixed $definition): ?array
    {
        $name = $this->readMappingValue($definition, 'name');
        $referencedColumnName = $this->readMappingValue($definition, 'referencedColumnName');
        if (!$this->isNonEmptyString($name) || !$this->isNonEmptyString($referencedColumnName)) {
            return null;
        }

        return [
            'name' => $name,
            'referencedColumnName' => $referencedColumnName,
        ];
    }

    private function readMappingValue(mixed $mapping, string $key): mixed
    {
        if (is_array($mapping)) {
            return $mapping[$key] ?? null;
        }

        if (!is_object($mapping)) {
            return null;
        }

        $properties = get_object_vars($mapping);

        return $properties[$key] ?? null;
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
