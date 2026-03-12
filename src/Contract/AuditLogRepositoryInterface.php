<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Contract;

use DateTimeImmutable;
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

    public function deleteOldLogs(DateTimeImmutable $before): int;

    /**
     * @param array<string, mixed> $filters
     *
     * @return array<int, AuditLog>
     */
    public function findWithFilters(array $filters = [], int $limit = 30): array;

    /**
     * @param array<string, mixed> $filters
     *
     * @return iterable<AuditLog>
     */
    public function findAllWithFilters(array $filters = []): iterable;

    /**
     * @return array<AuditLog>
     */
    public function findOlderThan(DateTimeImmutable $before): array;

    public function countOlderThan(DateTimeImmutable $before): int;

    /**
     * @param array<string, mixed> $criteria
     */
    public function count(array $criteria = []): int;

    public function find(mixed $id): ?object;

    public function isReverted(AuditLog $log): bool;
}
