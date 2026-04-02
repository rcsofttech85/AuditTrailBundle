<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Contract;

use Rcsofttech\AuditTrailBundle\Entity\AuditLog;

interface ScheduledAuditManagerInterface
{
    public function schedule(object $entity, AuditLog $audit, bool $isInsert): void;

    /**
     * @param array<string, mixed> $data
     */
    public function addPendingDeletion(object $entity, array $data, bool $isManaged): void;

    public function clear(): void;

    public function disable(): void;

    public function enable(): void;

    public function isEnabled(): bool;

    /**
     * @return array<int, array{entity: object, audit: AuditLog, is_insert: bool}>
     */
    public function getScheduledAudits(): array;

    /**
     * @return list<array{entity: object, data: array<string, mixed>, is_managed: bool}>
     */
    public function getPendingDeletions(): array;

    /**
     * @param array<int, array{entity: object, audit: AuditLog, is_insert: bool}> $scheduledAudits
     */
    public function replaceScheduledAudits(array $scheduledAudits): void;

    /**
     * @param list<array{entity: object, data: array<string, mixed>, is_managed: bool}> $pendingDeletions
     */
    public function replacePendingDeletions(array $pendingDeletions): void;
}
