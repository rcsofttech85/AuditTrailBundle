<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Transport;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\UnitOfWork;
use Rcsofttech\AuditTrailBundle\Entity\AuditLog;
use Rcsofttech\AuditTrailBundle\Enum\AuditPhase;

final readonly class AuditTransportContext
{
    public function __construct(
        public AuditPhase $phase,
        public EntityManagerInterface $entityManager,
        public AuditLog $audit,
        public ?UnitOfWork $unitOfWork = null,
        public ?object $entity = null,
    ) {
    }

    public function withAudit(AuditLog $audit): self
    {
        return new self(
            $this->phase,
            $this->entityManager,
            $audit,
            $this->unitOfWork,
            $this->entity,
        );
    }
}
