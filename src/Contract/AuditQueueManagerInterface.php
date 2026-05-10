<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Contract;

use Rcsofttech\AuditTrailBundle\Entity\AuditLog;
use Rcsofttech\AuditTrailBundle\Enum\AuditAction;
use Rcsofttech\AuditTrailBundle\ValueObject\PendingAuditPlan;
use Rcsofttech\AuditTrailBundle\ValueObject\PendingDeletionEntry;
use Rcsofttech\AuditTrailBundle\ValueObject\ScheduledAuditEntry;
use Symfony\Contracts\Service\ResetInterface;

interface AuditQueueManagerInterface extends ResetInterface
{
    public function schedule(object $entity, AuditLog $audit, bool $isInsert): void;

    public function schedulePendingAuditPlan(PendingAuditPlan $plan): void;

    /**
     * @param array<string, mixed> $data
     */
    public function addPendingDeletion(object $entity, array $data, AuditAction $action): void;

    public function clear(): void;

    /**
     * @return list<ScheduledAuditEntry>
     */
    public function getScheduledAudits(): array;

    /**
     * @return list<PendingAuditPlan>
     */
    public function getPendingAuditPlans(): array;

    /**
     * @return list<PendingDeletionEntry>
     */
    public function getPendingDeletions(): array;
}
