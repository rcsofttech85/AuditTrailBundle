<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Service;

use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\Types\Type;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use Rcsofttech\AuditTrailBundle\Contract\AuditLogWriterInterface;
use Rcsofttech\AuditTrailBundle\Entity\AuditLog;
use Symfony\Component\Uid\Uuid;

use function array_map;
use function array_merge;
use function explode;
use function implode;
use function in_array;
use function is_string;
use function sprintf;
use function str_contains;

final class AuditLogWriter implements AuditLogWriterInterface
{
    public function insert(AuditLog $audit, EntityManagerInterface $em): void
    {
        $metadata = $em->getClassMetadata(AuditLog::class);
        $connection = $em->getConnection();
        $this->assignIdentifierIfMissing($audit, $metadata);

        $fieldNames = $metadata->getFieldNames();
        if (!in_array('id', $fieldNames, true)) {
            $fieldNames = array_merge(['id'], $fieldNames);
        }

        $data = [];
        foreach ($fieldNames as $fieldName) {
            $columnName = $metadata->getColumnName($fieldName);
            $type = $metadata->getTypeOfField($fieldName);
            $value = $metadata->getFieldValue($audit, $fieldName);

            $data[$columnName] = $type !== null
                ? $this->convertValue($value, $type, $em)
                : $value;
        }

        try {
            $connection->insert($metadata->getTableName(), $data);
        } catch (UniqueConstraintViolationException $exception) {
            if ($audit->deliveryId !== null && $this->deliveryAlreadyExists($audit, $metadata, $em)) {
                return;
            }

            throw $exception;
        }
    }

    private function convertValue(mixed $value, string $type, EntityManagerInterface $em): mixed
    {
        if ($value !== null && is_string($value) && str_contains($type, 'datetime')) {
            return $value;
        }

        return Type::getType($type)->convertToDatabaseValue($value, $em->getConnection()->getDatabasePlatform());
    }

    /**
     * @param ClassMetadata<AuditLog> $metadata
     */
    private function assignIdentifierIfMissing(AuditLog $audit, ClassMetadata $metadata): void
    {
        if ($metadata->getFieldValue($audit, 'id') !== null) {
            return;
        }

        $metadata->setFieldValue($audit, 'id', Uuid::v7());
    }

    /**
     * @param ClassMetadata<AuditLog> $metadata
     */
    private function deliveryAlreadyExists(AuditLog $audit, ClassMetadata $metadata, EntityManagerInterface $em): bool
    {
        $connection = $em->getConnection();
        $platform = $connection->getDatabasePlatform();
        $table = implode('.', array_map($platform->quoteSingleIdentifier(...), explode('.', $metadata->getTableName())));
        $column = $platform->quoteSingleIdentifier($metadata->getColumnName('deliveryId'));
        $result = $connection->fetchOne(
            sprintf('SELECT 1 FROM %s WHERE %s = ?', $table, $column),
            [$audit->deliveryId],
        );

        return $result !== false;
    }
}
