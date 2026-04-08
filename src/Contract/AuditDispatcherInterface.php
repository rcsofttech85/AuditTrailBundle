<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Contract;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\UnitOfWork;
use Rcsofttech\AuditTrailBundle\Entity\AuditLog;
use Rcsofttech\AuditTrailBundle\Enum\AuditPhase;

interface AuditDispatcherInterface
{
    /**
     * Dispatch an audit log to the transport.
     *
     * @return bool True if dispatched, false if deferred/skipped
     */
    public function dispatch(
        AuditLog $audit,
        EntityManagerInterface $em,
        AuditPhase $phase,
        ?UnitOfWork $uow = null,
        ?object $entity = null,
    ): bool;
}
