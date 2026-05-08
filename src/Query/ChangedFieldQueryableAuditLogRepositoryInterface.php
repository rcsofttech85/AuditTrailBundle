<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Query;

use Rcsofttech\AuditTrailBundle\Entity\AuditLog;

/**
 * @internal
 */
interface ChangedFieldQueryableAuditLogRepositoryInterface
{
    public function supportsChangedFieldQueries(): bool;

    /**
     * @param array<string, mixed> $filters
     * @param list<string>         $changedFields
     */
    public function countWithChangedFields(array $filters, array $changedFields): int;

    /**
     * @param array<string, mixed> $filters
     * @param list<string>         $changedFields
     *
     * @return list<AuditLog>
     */
    public function findWithChangedFields(array $filters, array $changedFields, int $limit): array;
}
