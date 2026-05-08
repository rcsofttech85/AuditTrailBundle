<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Service;

use Rcsofttech\AuditTrailBundle\ValueObject\PendingAuditPlan;
use Rcsofttech\AuditTrailBundle\ValueObject\PendingDeletionEntry;
use Rcsofttech\AuditTrailBundle\ValueObject\ScheduledAuditEntry;

/**
 * @internal
 */
interface FailedAuditDispatchRetainerInterface
{
    /**
     * @param list<ScheduledAuditEntry> $scheduledAudits
     */
    public function replaceScheduledAudits(array $scheduledAudits): void;

    /**
     * @param list<PendingAuditPlan> $plans
     */
    public function replacePendingAuditPlans(array $plans): void;

    /**
     * @param list<PendingDeletionEntry> $pendingDeletions
     */
    public function replacePendingDeletions(array $pendingDeletions): void;
}
