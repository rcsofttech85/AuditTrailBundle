<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Service;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\UnitOfWork;
use Rcsofttech\AuditTrailBundle\Contract\AuditDispatcherInterface;
use Rcsofttech\AuditTrailBundle\Contract\AuditLogInterface;
use Rcsofttech\AuditTrailBundle\Contract\ScheduledAuditManagerInterface;
use Rcsofttech\AuditTrailBundle\Entity\AuditLog;
use Rcsofttech\AuditTrailBundle\Enum\AuditPhase;

final readonly class EntityAuditDispatchManager
{
    public function __construct(
        private AuditDispatcherInterface $dispatcher,
        private ScheduledAuditManagerInterface $auditManager,
        private bool $deferTransportUntilCommit = true,
        private bool $failOnTransportError = false,
    ) {
    }

    public function dispatchOrSchedule(
        AuditLog $audit,
        object $entity,
        EntityManagerInterface $em,
        UnitOfWork $uow,
        bool $isInsert,
    ): void {
        $hasResolvedEntityId = $audit->entityId !== AuditLogInterface::PENDING_ID;
        $canDispatchNow = (!$isInsert && !$this->deferTransportUntilCommit)
            || ($isInsert && !$hasResolvedEntityId && !$this->deferTransportUntilCommit && $this->failOnTransportError)
            || ($isInsert && $hasResolvedEntityId);

        if ($canDispatchNow && $this->dispatcher->dispatch($audit, $em, AuditPhase::OnFlush, $uow, $entity)) {
            return;
        }

        $this->auditManager->schedule($entity, $audit, $isInsert);
    }
}
