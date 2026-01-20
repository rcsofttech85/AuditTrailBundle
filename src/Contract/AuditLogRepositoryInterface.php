<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Contract;

use Rcsofttech\AuditTrailBundle\Entity\AuditLog;

interface AuditLogRepositoryInterface
{
    /**
     * @return array<AuditLog>
     */
    public function findByEntity(string $entityClass, string $entityId): array;

    /**
     * @return array<AuditLog>
     */
    public function findByTransactionHash(string $transactionHash): array;

    /**
     * @return array<AuditLog>
     */
    public function findByUser(string $userId, int $limit = 30): array;

    public function deleteOldLogs(\DateTimeImmutable $before): int;

    /**
     * @param array<string, mixed> $filters
     *
     * @return array<int, AuditLog>
     */
    public function findWithFilters(array $filters = [], int $limit = 30): array;

    public function countOlderThan(\DateTimeImmutable $before): int;
}
