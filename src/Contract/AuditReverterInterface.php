<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Contract;

use RuntimeException;

/**
 * Interface for reverting entity changes based on audit logs.
 */
interface AuditReverterInterface
{
    /**
     * Revert an entity to the state described in the audit log.
     *
     * @param AuditLogInterface    $log     The audit log entry to revert to/from
     * @param bool                 $dryRun  If true, changes are calculated but not persisted
     * @param bool                 $force   If true, allows reverting creation (deleting the entity)
     * @param array<string, mixed> $context Optional custom context for the revert audit log
     *
     * @return array<string, mixed> The changes that were (or would be) applied
     *
     * @throws RuntimeException If the revert operation fails or is unsafe
     */
    public function revert(
        AuditLogInterface $log,
        bool $dryRun = false,
        bool $force = false,
        array $context = [],
    ): array;
}
