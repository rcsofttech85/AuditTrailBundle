<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Service;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use Rcsofttech\AuditTrailBundle\Contract\AuditLogWriterInterface;
use Rcsofttech\AuditTrailBundle\Entity\AuditLog;
use Symfony\Component\Uid\Uuid;

use function array_merge;
use function in_array;
use function is_string;
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

        $connection->insert($metadata->getTableName(), $data);
    }

    private function convertValue(mixed $value, string $type, EntityManagerInterface $em): mixed
    {
        if ($value !== null && is_string($value) && str_contains($type, 'datetime')) {
            return $value;
        }

        return $em->getConnection()->convertToDatabaseValue($value, $type);
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
}
