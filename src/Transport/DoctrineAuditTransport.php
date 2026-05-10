<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Transport;

use LogicException;
use Override;
use Rcsofttech\AuditTrailBundle\Contract\AuditLogWriterInterface;
use Rcsofttech\AuditTrailBundle\Contract\AuditTransportInterface;
use Rcsofttech\AuditTrailBundle\Contract\EntityIdResolverInterface;

final readonly class DoctrineAuditTransport implements AuditTransportInterface
{
    public function __construct(
        private EntityIdResolverInterface $idResolver,
        private AuditLogWriterInterface $auditLogWriter,
    ) {
    }

    #[Override]
    public function send(AuditTransportContext $context): AuditDeliveryResult
    {
        if ($context->phase->isOnFlush()) {
            $this->persistWithinCurrentUnitOfWork($context);

            return AuditDeliveryResult::delivered();
        }

        if ($context->phase->isDeferredPersistencePhase()) {
            $this->persistDeferredAudit($context);
        }

        return AuditDeliveryResult::delivered();
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
        $entityId = $this->resolveEntityId($context);

        if ($entityId === null) {
            throw new LogicException('Cannot persist a deferred audit log before the entity ID has been resolved.');
        }

        $log->entityId = $entityId;

        $this->auditLogWriter->insert($log, $context->entityManager);
    }

    #[Override]
    public function supports(AuditTransportContext $context): bool
    {
        if ($context->phase->isOnFlush()) {
            return $context->audit->hasResolvedEntityId();
        }

        return $this->resolveEntityId($context) !== null;
    }

    private function resolveEntityId(AuditTransportContext $context): ?string
    {
        return $this->idResolver->resolve($context->audit, $context) ?? $context->audit->entityId;
    }
}
