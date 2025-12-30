<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Contract;

/**
 * Interface for reverting entity changes based on audit logs.
 */
interface AuditReverterInterface
{
    /**
     * Revert an entity to the state described in the audit log.
     *
     * @param AuditLogInterface $log    The audit log entry to revert to/from
     * @param bool              $dryRun If true, changes are calculated but not persisted
     * @param bool              $force  If true, allows reverting creation (deleting the entity)
     *
     * @return array<string, mixed> The changes that were (or would be) applied
     *
     * @throws \RuntimeException If the revert operation fails or is unsafe
     */
    public function revert(AuditLogInterface $log, bool $dryRun = false, bool $force = false): array;
}
