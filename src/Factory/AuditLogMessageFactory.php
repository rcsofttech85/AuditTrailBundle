<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Factory;

use LogicException;
use Rcsofttech\AuditTrailBundle\Contract\AuditLogMessageFactoryInterface;
use Rcsofttech\AuditTrailBundle\Contract\EntityIdResolverInterface;
use Rcsofttech\AuditTrailBundle\Entity\AuditLog;
use Rcsofttech\AuditTrailBundle\Message\AuditLogMessage;
use Rcsofttech\AuditTrailBundle\Message\PersistAuditLogMessage;
use Rcsofttech\AuditTrailBundle\Transport\AuditTransportContext;
use Symfony\Component\Uid\Factory\UuidFactory;

/**
 * Centralises the creation of message DTOs from AuditLog entities.
 *
 * Both QueueAuditTransport and AsyncDatabaseAuditTransport delegate here,
 * eliminating duplicated entity-ID resolution and DTO mapping logic (DRY).
 */
final readonly class AuditLogMessageFactory implements AuditLogMessageFactoryInterface
{
    public function __construct(
        private EntityIdResolverInterface $idResolver,
        private UuidFactory $uuidFactory,
    ) {
    }

    /**
     * Create a message for the Queue transport (external dispatch).
     */
    public function createQueueMessage(AuditTransportContext $context): AuditLogMessage
    {
        $entityId = $this->resolveEntityId($context);

        return AuditLogMessage::createFromAuditLog($context->audit, $entityId);
    }

    /**
     * Create a message for the async Database transport (internal persistence).
     */
    public function createPersistMessage(AuditTransportContext $context): PersistAuditLogMessage
    {
        $entityId = $this->resolveEntityId($context);
        $this->ensurePersistIdentifiers($context->audit);

        return PersistAuditLogMessage::createFromAuditLog($context->audit, $entityId);
    }

    private function resolveEntityId(AuditTransportContext $context): string
    {
        $entityId = $this->idResolver->resolve($context->audit, $context) ?? $context->audit->entityId;
        if ($entityId !== null) {
            return $entityId;
        }

        throw new LogicException('Cannot create an audit transport message before the entity ID has been resolved.');
    }

    private function ensurePersistIdentifiers(AuditLog $audit): void
    {
        $audit->initializeIdIfMissing($this->uuidFactory->create());
        $audit->deliveryId ??= $this->uuidFactory->create()->toRfc4122();
    }
}
