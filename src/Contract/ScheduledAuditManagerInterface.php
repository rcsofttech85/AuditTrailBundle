<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Contract;

use Rcsofttech\AuditTrailBundle\Entity\AuditLog;

/**
 * @property array<int, array{entity: object, audit: AuditLog, is_insert: bool}>       $scheduledAudits
 * @property list<array{entity: object, data: array<string, mixed>, is_managed: bool}> $pendingDeletions
 *
 * @phpstan-property-read array<int, array{entity: object, audit: AuditLog, is_insert: bool}> $scheduledAudits
 * @phpstan-property-read list<array{entity: object, data: array<string, mixed>, is_managed: bool}> $pendingDeletions
 */
interface ScheduledAuditManagerInterface
{
    public function schedule(object $entity, AuditLog $audit, bool $isInsert): void;

    /**
     * @param array<string, mixed> $data
     */
    public function addPendingDeletion(object $entity, array $data, bool $isManaged): void;

    public function clear(): void;
}
