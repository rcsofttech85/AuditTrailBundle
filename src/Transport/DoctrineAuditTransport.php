<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Transport;

use Override;
use Rcsofttech\AuditTrailBundle\Contract\AuditLogInterface;
use Rcsofttech\AuditTrailBundle\Contract\AuditLogWriterInterface;
use Rcsofttech\AuditTrailBundle\Contract\AuditTransportInterface;
use Rcsofttech\AuditTrailBundle\Contract\EntityIdResolverInterface;

final class DoctrineAuditTransport implements AuditTransportInterface
{
    public function __construct(
        private readonly EntityIdResolverInterface $idResolver,
        private readonly AuditLogWriterInterface $auditLogWriter,
    ) {
    }

    #[Override]
    public function send(AuditTransportContext $context): void
    {
        if ($context->phase->isOnFlush()) {
            $this->persistWithinCurrentUnitOfWork($context);

            return;
        }

        if ($context->phase->isDeferredPersistencePhase()) {
            $this->persistDeferredAudit($context);
        }
    }

    private function persistWithinCurrentUnitOfWork(AuditTransportContext $context): void
    {
        $context->entityManager->persist($context->audit);
        $context->unitOfWork?->computeChangeSet(
            $context->entityManager->getClassMetadata(\Rcsofttech\AuditTrailBundle\Entity\AuditLog::class),
            $context->audit
        );
    }

    private function persistDeferredAudit(AuditTransportContext $context): void
    {
        $log = $context->audit;
        $entityId = $this->idResolver->resolve($log, $context);

        if ($entityId !== null) {
            $log->entityId = $entityId;
        }

        $this->auditLogWriter->insert($log, $context->entityManager);
    }

    #[Override]
    public function supports(AuditTransportContext $context): bool
    {
        if ($context->phase->isOnFlush()) {
            return $context->audit->entityId !== AuditLogInterface::PENDING_ID;
        }

        return true;
    }
}
