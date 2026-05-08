<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Repository;

use Doctrine\ORM\EntityManagerInterface;
use LogicException;
use Rcsofttech\AuditTrailBundle\Entity\AuditLog;

use function is_string;

final class AuditLogMetadataResolver
{
    /**
     * @return array{
     *     id: string,
     *     entityClass: string,
     *     entityId: string,
     *     userId: string,
     *     username: string,
     *     transactionHash: string,
     *     action: string,
     *     createdAt: string,
     *     changedFields: string
     * }
     */
    public function resolveColumnMap(EntityManagerInterface $entityManager): array
    {
        $metadata = $entityManager->getClassMetadata(AuditLog::class);

        return [
            'id' => $metadata->getColumnName('id'),
            'entityClass' => $metadata->getColumnName('entityClass'),
            'entityId' => $metadata->getColumnName('entityId'),
            'userId' => $metadata->getColumnName('userId'),
            'username' => $metadata->getColumnName('username'),
            'transactionHash' => $metadata->getColumnName('transactionHash'),
            'action' => $metadata->getColumnName('action'),
            'createdAt' => $metadata->getColumnName('createdAt'),
            'changedFields' => $metadata->getColumnName('changedFields'),
        ];
    }

    public function resolveTableName(EntityManagerInterface $entityManager): string
    {
        return $entityManager->getClassMetadata(AuditLog::class)->getTableName();
    }

    public function resolveSchemaName(EntityManagerInterface $entityManager): ?string
    {
        return $entityManager->getClassMetadata(AuditLog::class)->getSchemaName();
    }

    public function resolveIdDoctrineType(EntityManagerInterface $entityManager): string
    {
        $idType = $entityManager->getClassMetadata(AuditLog::class)->getTypeOfField('id');
        if (!is_string($idType) || $idType === '') {
            throw new LogicException('AuditLog ID field must define a Doctrine type.');
        }

        return $idType;
    }
}
