<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Tests\Unit\EventSubscriber;

use Rcsofttech\AuditTrailBundle\Contract\ScheduledAuditManagerInterface;
use Rcsofttech\AuditTrailBundle\Entity\AuditLog;
use Rcsofttech\AuditTrailBundle\Enum\AuditAction;
use Rcsofttech\AuditTrailBundle\ValueObject\PendingAuditPlan;
use Rcsofttech\AuditTrailBundle\ValueObject\PendingDeletionEntry;
use Rcsofttech\AuditTrailBundle\ValueObject\ScheduledAuditEntry;

final class MockScheduledAuditManager implements ScheduledAuditManagerInterface
{
    /** @var list<ScheduledAuditEntry> */
    private array $scheduledAudits = [];

    /** @var list<PendingDeletionEntry> */
    private array $pendingDeletions = [];

    /** @var list<PendingAuditPlan> */
    private array $pendingAuditPlans = [];

    public function schedule(object $entity, AuditLog $audit, bool $isInsert): void
    {
        $this->scheduledAudits[] = new ScheduledAuditEntry($entity, $audit, $isInsert);
    }

    public function addPendingDeletion(object $entity, array $data, bool $isManaged, AuditAction $action): void
    {
        $this->pendingDeletions[] = new PendingDeletionEntry($entity, $data, $isManaged, $action);
    }

    public function schedulePendingAuditPlan(PendingAuditPlan $plan): void
    {
        $this->pendingAuditPlans[] = $plan;
    }

    public function clear(): void
    {
        $this->scheduledAudits = [];
        $this->pendingDeletions = [];
        $this->pendingAuditPlans = [];
    }

    /**
     * @param list<ScheduledAuditEntry|array{entity: object, audit: AuditLog, is_insert: bool}> $scheduledAudits
     */
    public function replaceScheduledAudits(array $scheduledAudits): void
    {
        $this->scheduledAudits = $this->normalizeScheduledAudits($scheduledAudits);
    }

    /**
     * @param list<PendingDeletionEntry|array{entity: object, data: array<string, mixed>, is_managed: bool, action: AuditAction}> $pendingDeletions
     */
    public function replacePendingDeletions(array $pendingDeletions): void
    {
        $this->pendingDeletions = $this->normalizePendingDeletions($pendingDeletions);
    }

    /** @return list<ScheduledAuditEntry> */
    public function getScheduledAudits(): array
    {
        return $this->scheduledAudits;
    }

    /** @return list<PendingDeletionEntry> */
    public function getPendingDeletions(): array
    {
        return $this->pendingDeletions;
    }

    /** @return list<PendingAuditPlan> */
    public function getPendingAuditPlans(): array
    {
        return $this->pendingAuditPlans;
    }

    public function hasScheduledAudits(): bool
    {
        return $this->scheduledAudits !== [];
    }

    public function hasPendingDeletions(): bool
    {
        return $this->pendingDeletions !== [];
    }

    public function replacePendingAuditPlans(array $plans): void
    {
        $this->pendingAuditPlans = $plans;
    }

    /**
     * @param list<ScheduledAuditEntry|array{entity: object, audit: AuditLog, is_insert: bool}> $scheduledAudits
     */
    public function seedScheduledAudits(array $scheduledAudits): void
    {
        $this->scheduledAudits = $this->normalizeScheduledAudits($scheduledAudits);
    }

    /**
     * @param list<PendingDeletionEntry|array{entity: object, data: array<string, mixed>, is_managed: bool, action: AuditAction}> $pendingDeletions
     */
    public function seedPendingDeletions(array $pendingDeletions): void
    {
        $this->pendingDeletions = $this->normalizePendingDeletions($pendingDeletions);
    }

    private bool $enabled = true;

    public function disable(): void
    {
        $this->enabled = false;
    }

    public function enable(): void
    {
        $this->enabled = true;
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function reset(): void
    {
        $this->clear();
        $this->enabled = true;
    }

    /**
     * @param list<ScheduledAuditEntry|array{entity: object, audit: AuditLog, is_insert: bool}> $scheduledAudits
     *
     * @return list<ScheduledAuditEntry>
     */
    private function normalizeScheduledAudits(array $scheduledAudits): array
    {
        $normalized = [];
        foreach ($scheduledAudits as $scheduledAudit) {
            if ($scheduledAudit instanceof ScheduledAuditEntry) {
                $normalized[] = $scheduledAudit;
                continue;
            }

            $normalized[] = new ScheduledAuditEntry(
                $scheduledAudit['entity'],
                $scheduledAudit['audit'],
                $scheduledAudit['is_insert'],
            );
        }

        return $normalized;
    }

    /**
     * @param list<PendingDeletionEntry|array{entity: object, data: array<string, mixed>, is_managed: bool, action: AuditAction}> $pendingDeletions
     *
     * @return list<PendingDeletionEntry>
     */
    private function normalizePendingDeletions(array $pendingDeletions): array
    {
        $normalized = [];
        foreach ($pendingDeletions as $pendingDeletion) {
            if ($pendingDeletion instanceof PendingDeletionEntry) {
                $normalized[] = $pendingDeletion;
                continue;
            }

            $normalized[] = new PendingDeletionEntry(
                $pendingDeletion['entity'],
                $pendingDeletion['data'],
                $pendingDeletion['is_managed'],
                $pendingDeletion['action'],
            );
        }

        return $normalized;
    }
}
