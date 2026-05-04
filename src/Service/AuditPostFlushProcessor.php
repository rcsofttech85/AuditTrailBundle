<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Service;

use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Rcsofttech\AuditTrailBundle\Contract\AuditDispatcherInterface;
use Rcsofttech\AuditTrailBundle\Contract\AuditLogInterface;
use Rcsofttech\AuditTrailBundle\Contract\AuditQueueManagerInterface;
use Rcsofttech\AuditTrailBundle\Contract\AuditServiceInterface;
use Rcsofttech\AuditTrailBundle\Enum\AuditAction;
use Rcsofttech\AuditTrailBundle\Enum\AuditPhase;
use Rcsofttech\AuditTrailBundle\ValueObject\PendingAuditPlan;
use Rcsofttech\AuditTrailBundle\ValueObject\PendingDeletionEntry;
use Rcsofttech\AuditTrailBundle\ValueObject\ScheduledAuditEntry;

use function count;

final readonly class AuditPostFlushProcessor
{
    public function __construct(
        private AuditServiceInterface $auditService,
        private AuditDispatcherInterface $dispatcher,
        private AuditQueueManagerInterface $auditManager,
        private PendingAuditPlanMaterializer $pendingAuditPlanMaterializer,
        private TransactionIdGenerator $transactionIdGenerator,
        private AuditedEntityMarker $auditedEntityMarker,
        private ?LoggerInterface $logger = null,
    ) {
    }

    public function process(EntityManagerInterface $entityManager): void
    {
        // Keep deletion, deferred-plan, and scheduled-audit processing in this
        // order so post-flush delivery preserves the bundle's historical event
        // sequencing while generated identifiers are already available.
        $failedPendingDeletions = $this->processPendingDeletions($entityManager);
        $failedPendingAuditPlans = $this->processPendingAuditPlans($entityManager);
        $failedScheduledAudits = $this->processScheduledAudits($entityManager);

        $this->auditManager->clear();
        $this->retainFailedDispatches($failedPendingDeletions, $failedPendingAuditPlans, $failedScheduledAudits);
        if ($failedPendingDeletions !== [] || $failedPendingAuditPlans !== [] || $failedScheduledAudits !== []) {
            $this->logger?->warning('Audit delivery deferred until a later flush.', [
                'pending_deletions' => count($failedPendingDeletions),
                'pending_audit_plans' => count($failedPendingAuditPlans),
                'scheduled_audits' => count($failedScheduledAudits),
            ]);
        }

        $this->transactionIdGenerator->reset();
    }

    /**
     * @return list<PendingDeletionEntry>
     */
    private function processPendingDeletions(EntityManagerInterface $entityManager): array
    {
        /** @var list<PendingDeletionEntry> $failedPendingDeletions */
        $failedPendingDeletions = [];

        foreach ($this->auditManager->getPendingDeletions() as $pendingDeletion) {
            $entity = $pendingDeletion->entity;
            $oldData = $pendingDeletion->data;
            $action = $pendingDeletion->action;

            $newData = $action === AuditAction::SoftDelete
                ? $this->auditService->getEntityData($entity, [], $entityManager)
                : null;
            $audit = $this->auditService->createAuditLog($entity, $action, $oldData, $newData, [], $entityManager);

            if (!$this->dispatcher->dispatch($audit, $entityManager, AuditPhase::PostFlush, null, $entity)) {
                $failedPendingDeletions[] = $pendingDeletion;
                continue;
            }

            $this->auditedEntityMarker->mark($entity, $entityManager);
        }

        return $failedPendingDeletions;
    }

    /**
     * @return list<PendingAuditPlan>
     */
    private function processPendingAuditPlans(EntityManagerInterface $entityManager): array
    {
        $failedPlans = [];

        foreach ($this->auditManager->getPendingAuditPlans() as $plan) {
            $audit = $this->pendingAuditPlanMaterializer->materialize($plan, $entityManager);

            if (!$this->dispatcher->dispatch($audit, $entityManager, AuditPhase::PostFlush, null, $plan->entity)) {
                $failedPlans[] = $plan;
                continue;
            }

            $this->auditedEntityMarker->mark($plan->entity, $entityManager);
        }

        return $failedPlans;
    }

    /**
     * @return list<ScheduledAuditEntry>
     */
    private function processScheduledAudits(EntityManagerInterface $entityManager): array
    {
        /** @var list<ScheduledAuditEntry> $failedScheduledAudits */
        $failedScheduledAudits = [];

        foreach ($this->auditManager->getScheduledAudits() as $scheduledAudit) {
            $entity = $scheduledAudit->entity;
            $audit = $scheduledAudit->audit;

            if ($scheduledAudit->isInsert) {
                $id = $this->auditedEntityMarker->resolveEntityId($entity, $entityManager);
                if ($id !== AuditLogInterface::PENDING_ID) {
                    $audit->entityId = $id;
                }
            }

            if (!$this->dispatcher->dispatch($audit, $entityManager, AuditPhase::PostFlush, null, $entity)) {
                $failedScheduledAudits[] = $scheduledAudit;
                continue;
            }

            $this->auditedEntityMarker->mark($entity, $entityManager);
        }

        return $failedScheduledAudits;
    }

    /**
     * @param list<PendingDeletionEntry> $failedPendingDeletions
     * @param list<PendingAuditPlan>     $failedPendingAuditPlans
     * @param list<ScheduledAuditEntry>  $failedScheduledAudits
     */
    private function retainFailedDispatches(
        array $failedPendingDeletions,
        array $failedPendingAuditPlans,
        array $failedScheduledAudits,
    ): void {
        if ($failedPendingDeletions !== []) {
            $this->auditManager->replacePendingDeletions($failedPendingDeletions);
        }

        if ($failedPendingAuditPlans !== []) {
            $this->auditManager->replacePendingAuditPlans($failedPendingAuditPlans);
        }

        if ($failedScheduledAudits !== []) {
            $this->auditManager->replaceScheduledAudits($failedScheduledAudits);
        }
    }
}
