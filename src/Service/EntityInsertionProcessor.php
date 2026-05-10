<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Service;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\UnitOfWork;
use Rcsofttech\AuditTrailBundle\Contract\AuditQueueManagerInterface;
use Rcsofttech\AuditTrailBundle\Contract\AuditServiceInterface;
use Rcsofttech\AuditTrailBundle\Enum\AuditAction;
use Rcsofttech\AuditTrailBundle\ValueObject\PendingAuditPlan;

final readonly class EntityInsertionProcessor
{
    public function __construct(
        private AuditServiceInterface $auditService,
        private AuditQueueManagerInterface $auditManager,
        private DeferredCollectionDetector $deferredCollectionDetector,
        private EntityAuditDispatchManager $dispatchManager,
    ) {
    }

    public function process(EntityManagerInterface $em, UnitOfWork $uow): void
    {
        foreach ($uow->getScheduledEntityInsertions() as $entity) {
            $data = $this->auditService->getEntityData($entity, [], $em);
            if (!$this->auditService->shouldAudit($entity, AuditAction::Create, $data)) {
                continue;
            }

            if ($this->deferredCollectionDetector->entityHasDeferredCollectionAssociations($entity, $em)) {
                $this->auditManager->schedulePendingAuditPlan(
                    PendingAuditPlan::forEntityRefresh($entity, AuditAction::Create),
                );

                continue;
            }

            $audit = $this->auditService->createAuditLog(
                $entity,
                AuditAction::Create,
                null,
                $data,
                [],
                $em,
            );

            $this->dispatchManager->dispatchOrSchedule($audit, $entity, $em, $uow, true);
        }
    }
}
