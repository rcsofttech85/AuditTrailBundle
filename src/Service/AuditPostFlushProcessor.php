<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Service;

use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Rcsofttech\AuditTrailBundle\Contract\AuditDispatcherInterface;
use Rcsofttech\AuditTrailBundle\Contract\AuditLogInterface;
use Rcsofttech\AuditTrailBundle\Contract\AuditServiceInterface;
use Rcsofttech\AuditTrailBundle\Contract\ScheduledAuditManagerInterface;
use Rcsofttech\AuditTrailBundle\Enum\AuditAction;
use Rcsofttech\AuditTrailBundle\Enum\AuditPhase;

use function count;

final readonly class AuditPostFlushProcessor
{
    public function __construct(
        private AuditServiceInterface $auditService,
        private AuditDispatcherInterface $dispatcher,
        private ScheduledAuditManagerInterface $auditManager,
        private TransactionIdGenerator $transactionIdGenerator,
        private AuditedEntityMarker $auditedEntityMarker,
        private ?LoggerInterface $logger = null,
    ) {
    }

    public function process(EntityManagerInterface $entityManager): void
    {
        $failedPendingDeletions = $this->processPendingDeletions($entityManager);
        $failedScheduledAudits = $this->processScheduledAudits($entityManager);

        $this->auditManager->clear();
        $this->retainFailedDispatches($failedPendingDeletions, $failedScheduledAudits);
        if ($failedPendingDeletions !== [] || $failedScheduledAudits !== []) {
            $this->logger?->warning('Audit delivery deferred until a later flush.', [
                'pending_deletions' => count($failedPendingDeletions),
                'scheduled_audits' => count($failedScheduledAudits),
            ]);
        }

        $this->transactionIdGenerator->reset();
    }

    /**
     * @return list<array{entity: object, data: array<string, mixed>, is_managed: bool, action: AuditAction}>
     */
    private function processPendingDeletions(EntityManagerInterface $entityManager): array
    {
        /** @var list<array{entity: object, data: array<string, mixed>, is_managed: bool, action: AuditAction}> $failedPendingDeletions */
        $failedPendingDeletions = [];

        foreach ($this->auditManager->getPendingDeletions() as $pendingDeletion) {
            $entity = $pendingDeletion['entity'];
            $oldData = $pendingDeletion['data'];
            $action = $pendingDeletion['action'];

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
     * @return array<int, array{entity: object, audit: \Rcsofttech\AuditTrailBundle\Entity\AuditLog, is_insert: bool}>
     */
    private function processScheduledAudits(EntityManagerInterface $entityManager): array
    {
        /** @var array<int, array{entity: object, audit: \Rcsofttech\AuditTrailBundle\Entity\AuditLog, is_insert: bool}> $failedScheduledAudits */
        $failedScheduledAudits = [];

        foreach ($this->auditManager->getScheduledAudits() as $scheduledAudit) {
            $entity = $scheduledAudit['entity'];
            $audit = $scheduledAudit['audit'];

            if ($scheduledAudit['is_insert']) {
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
     * @param list<array{entity: object, data: array<string, mixed>, is_managed: bool, action: AuditAction}>          $failedPendingDeletions
     * @param array<int, array{entity: object, audit: \Rcsofttech\AuditTrailBundle\Entity\AuditLog, is_insert: bool}> $failedScheduledAudits
     */
    private function retainFailedDispatches(array $failedPendingDeletions, array $failedScheduledAudits): void
    {
        if ($failedPendingDeletions !== []) {
            $this->auditManager->replacePendingDeletions($failedPendingDeletions);
        }

        if ($failedScheduledAudits !== []) {
            $this->auditManager->replaceScheduledAudits($failedScheduledAudits);
        }
    }
}
