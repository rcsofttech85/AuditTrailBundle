<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Service;

use OverflowException;
use Override;
use Rcsofttech\AuditTrailBundle\Contract\ScheduledAuditManagerInterface;
use Rcsofttech\AuditTrailBundle\Entity\AuditLog;
use Rcsofttech\AuditTrailBundle\Enum\AuditAction;
use Rcsofttech\AuditTrailBundle\ValueObject\PendingAuditPlan;
use Rcsofttech\AuditTrailBundle\ValueObject\PendingDeletionEntry;
use Rcsofttech\AuditTrailBundle\ValueObject\ScheduledAuditEntry;

use function count;
use function sprintf;

final class ScheduledAuditManager implements ScheduledAuditManagerInterface
{
    /** @var list<ScheduledAuditEntry> */
    private array $scheduledAudits = [];

    /** @var list<PendingDeletionEntry> */
    private array $pendingDeletions = [];

    /** @var list<PendingAuditPlan> */
    private array $pendingAuditPlans = [];

    private int $disableDepth = 0;

    public function __construct(
        private readonly bool $enabled = true,
        private readonly ?int $maxScheduledAudits = null,
        private readonly ?int $maxPendingAuditPlans = null,
        private readonly ?int $maxPendingDeletions = null,
    ) {
    }

    #[Override]
    public function disable(): void
    {
        ++$this->disableDepth;
    }

    #[Override]
    public function enable(): void
    {
        if ($this->disableDepth > 0) {
            --$this->disableDepth;
        }
    }

    #[Override]
    public function isEnabled(): bool
    {
        return $this->enabled && $this->disableDepth === 0;
    }

    #[Override]
    public function schedule(
        object $entity,
        AuditLog $audit,
        bool $isInsert,
    ): void {
        if ($this->maxScheduledAudits !== null && $this->maxScheduledAudits <= count($this->scheduledAudits)) {
            throw new OverflowException(sprintf('Maximum audit queue size exceeded (%d). Consider batch processing.', $this->maxScheduledAudits));
        }

        $this->scheduledAudits[] = new ScheduledAuditEntry($entity, $audit, $isInsert);
    }

    #[Override]
    public function schedulePendingAuditPlan(PendingAuditPlan $plan): void
    {
        if ($this->maxPendingAuditPlans !== null && $this->maxPendingAuditPlans <= count($this->pendingAuditPlans)) {
            throw new OverflowException(sprintf('Maximum pending audit plan queue size exceeded (%d). Consider batch processing.', $this->maxPendingAuditPlans));
        }

        $this->pendingAuditPlans[] = $plan;
    }

    /**
     * @param array<string, mixed> $data
     */
    #[Override]
    public function addPendingDeletion(object $entity, array $data, bool $isManaged, AuditAction $action): void
    {
        if ($this->maxPendingDeletions !== null && $this->maxPendingDeletions <= count($this->pendingDeletions)) {
            throw new OverflowException(sprintf('Maximum pending deletion queue size exceeded (%d). Consider batch processing.', $this->maxPendingDeletions));
        }

        $this->pendingDeletions[] = new PendingDeletionEntry($entity, $data, $isManaged, $action);
    }

    #[Override]
    public function clear(): void
    {
        $this->scheduledAudits = [];
        $this->pendingDeletions = [];
        $this->pendingAuditPlans = [];
    }

    /**
     * @return list<ScheduledAuditEntry>
     */
    #[Override]
    public function getScheduledAudits(): array
    {
        return $this->scheduledAudits;
    }

    /**
     * @return list<PendingDeletionEntry>
     */
    #[Override]
    public function getPendingDeletions(): array
    {
        return $this->pendingDeletions;
    }

    /**
     * @return list<PendingAuditPlan>
     */
    #[Override]
    public function getPendingAuditPlans(): array
    {
        return $this->pendingAuditPlans;
    }

    /**
     * @internal retains only audits that still need delivery after a failed post-flush dispatch
     *
     * @param list<ScheduledAuditEntry> $scheduledAudits
     */
    #[Override]
    public function replaceScheduledAudits(array $scheduledAudits): void
    {
        $this->scheduledAudits = $scheduledAudits;
    }

    /**
     * @param list<PendingAuditPlan> $plans
     */
    #[Override]
    public function replacePendingAuditPlans(array $plans): void
    {
        $this->pendingAuditPlans = $plans;
    }

    /**
     * @internal retains only deletions that still need audit delivery after a failed post-flush dispatch
     *
     * @param list<PendingDeletionEntry> $pendingDeletions
     */
    #[Override]
    public function replacePendingDeletions(array $pendingDeletions): void
    {
        $this->pendingDeletions = $pendingDeletions;
    }

    #[Override]
    public function reset(): void
    {
        $this->clear();
        $this->disableDepth = 0;
    }
}
