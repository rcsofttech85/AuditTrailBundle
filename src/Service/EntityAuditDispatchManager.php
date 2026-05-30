<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Service;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\UnitOfWork;
use Rcsofttech\AuditTrailBundle\Contract\AuditDispatcherInterface;
use Rcsofttech\AuditTrailBundle\Contract\AuditQueueManagerInterface;
use Rcsofttech\AuditTrailBundle\Entity\AuditLog;
use Rcsofttech\AuditTrailBundle\Enum\AuditPhase;

final readonly class EntityAuditDispatchManager
{
    public function __construct(
        private AuditDispatcherInterface $dispatcher,
        private AuditQueueManagerInterface $auditManager,
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
        if ($this->shouldDispatchNow($audit, $isInsert) && $this->dispatcher->dispatch($audit, $em, AuditPhase::OnFlush, $uow, $entity)) {
            return;
        }

        $this->auditManager->schedule($entity, $audit, $isInsert);
    }

    private function shouldDispatchNow(AuditLog $audit, bool $isInsert): bool
    {
        if ($this->deferTransportUntilCommit) {
            return false;
        }

        if (!$isInsert) {
            return true;
        }

        return $audit->hasResolvedEntityId() || $this->failOnTransportError;
    }
}
